<?php

namespace App\Service;

use App\Entity\Trajet;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Machine à états du trajet.
 *
 * Cycle : OUVERT → COMPLET → EN_ATTENTE_DEPART → EN_COURS → EN_ATTENTE_VALIDATION → TERMINE
 *         └───────────── ANNULE (depuis OUVERT, COMPLET ou EN_ATTENTE_DEPART)
 *
 * Chaque transition est ATOMIQUE : un UPDATE ... WHERE statut IN (...) garantit que
 * deux requêtes concurrentes ne peuvent pas faire transiter le trajet deux fois.
 */
class TrajetLifecycleService
{
    public const STATUT_OUVERT = 'OUVERT';
    public const STATUT_COMPLET = 'COMPLET';
    public const STATUT_EN_ATTENTE_DEPART = 'EN_ATTENTE_DEPART';
    public const STATUT_EN_COURS = 'EN_COURS';
    public const STATUT_EN_ATTENTE_VALIDATION = 'EN_ATTENTE_VALIDATION';
    public const STATUT_TERMINE = 'TERMINE';
    public const STATUT_ANNULE = 'ANNULE';

    public const STATUTS_VALIDES = [
        self::STATUT_OUVERT,
        self::STATUT_COMPLET,
        self::STATUT_EN_ATTENTE_DEPART,
        self::STATUT_EN_COURS,
        self::STATUT_EN_ATTENTE_VALIDATION,
        self::STATUT_TERMINE,
        self::STATUT_ANNULE,
    ];

    /** Trajets encore capables d'accepter des passagers (hors trajets commencés/terminés/annulés). */
    public const STATUTS_RESERVABLES = [
        self::STATUT_OUVERT,
        self::STATUT_COMPLET,
        self::STATUT_EN_ATTENTE_DEPART,
    ];

    private EntityManagerInterface $em;
    private NotificationService $notifService;

    public function __construct(EntityManagerInterface $em, NotificationService $notifService)
    {
        $this->em = $em;
        $this->notifService = $notifService;
    }

    public function estReservable(Trajet $trajet): bool
    {
        return in_array($trajet->getStatut(), self::STATUTS_RESERVABLES, true);
    }

    /**
     * Transition atomique. Retourne true si le trajet a bien changé de statut,
     * false si son statut actuel n'autorisait pas cette transition.
     */
    private function transitionAtomique(Trajet $trajet, string $nouveauStatut, array $statutsAutorises, bool $trajetActive = false): bool
    {
        $qb = $this->em->createQueryBuilder()
            ->update(Trajet::class, 't')
            ->set('t.statut', ':nouveau')
            ->set('t.trajetActive', ':actif')
            ->set('t.updatedAt', ':now')
            ->where('t.id = :id')
            ->andWhere('t.statut IN (:autorises)')
            ->setParameter('nouveau', $nouveauStatut)
            ->setParameter('actif', $trajetActive)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('id', $trajet->getId())
            ->setParameter('autorises', $statutsAutorises);

        if ($nouveauStatut === self::STATUT_TERMINE) {
            $qb->set('t.dateTermine', ':dateTermine')
               ->setParameter('dateTermine', new \DateTimeImmutable());
        }

        $affected = $qb->getQuery()->execute();

        if ($affected === 1) {
            $this->em->refresh($trajet);
        }

        return $affected === 1;
    }

    /** Démarrage effectif : le conducteur lance le GPS / appuie sur "Démarrer". */
    public function demarrer(Trajet $trajet): bool
    {
        return $this->transitionAtomique($trajet, self::STATUT_EN_COURS, [
            self::STATUT_OUVERT,
            self::STATUT_COMPLET,
            self::STATUT_EN_ATTENTE_DEPART,
        ], true);
    }

    /** Arrivée à destination : en attente de validation des présences par le conducteur. */
    public function arriver(Trajet $trajet): bool
    {
        return $this->transitionAtomique($trajet, self::STATUT_EN_ATTENTE_VALIDATION, [
            self::STATUT_EN_COURS,
            self::STATUT_EN_ATTENTE_DEPART,
            self::STATUT_COMPLET,
        ], false);
    }

    /** Clôture : présences validées, trajet réellement terminé. */
    public function terminer(Trajet $trajet): bool
    {
        return $this->transitionAtomique($trajet, self::STATUT_TERMINE, [
            self::STATUT_EN_COURS,
            self::STATUT_EN_ATTENTE_VALIDATION,
        ], false);
    }

    /** Annulation par le conducteur (avant départ) ou par le système. */
    public function annuler(Trajet $trajet): bool
    {
        return $this->transitionAtomique($trajet, self::STATUT_ANNULE, [
            self::STATUT_OUVERT,
            self::STATUT_COMPLET,
            self::STATUT_EN_ATTENTE_DEPART,
        ], false);
    }

    /** Fenêtre de départ : le trajet passe en attente de démarrage. */
    public function passerEnAttenteDepart(Trajet $trajet): bool
    {
        return $this->transitionAtomique($trajet, self::STATUT_EN_ATTENTE_DEPART, [
            self::STATUT_OUVERT,
            self::STATUT_COMPLET,
        ], false);
    }

    /** Trajet plein : plus de place, mais toujours actif (désistements possibles). */
    public function passerEnComplet(Trajet $trajet): bool
    {
        return $this->transitionAtomique($trajet, self::STATUT_COMPLET, [self::STATUT_OUVERT], false);
    }

    /** Une place se libère : le trajet complet redevient réservable. */
    public function rouvrir(Trajet $trajet): bool
    {
        return $this->transitionAtomique($trajet, self::STATUT_OUVERT, [self::STATUT_COMPLET], false);
    }

    /**
     * Revalidation de la disponibilité à chaque confirmation / annulation.
     * À appeler à la confirmation d'une réservation (décision 3.3).
     */
    public function revaliderDisponibilite(Trajet $trajet): void
    {
        $statut = $trajet->getStatut();
        $places = $trajet->getPlacesDisponibles();

        if (in_array($statut, self::STATUTS_RESERVABLES, true)) {
            if ($places !== null && $places <= 0) {
                $this->passerEnComplet($trajet);
            } elseif ($statut === self::STATUT_COMPLET && $places > 0) {
                $this->rouvrir($trajet);
            }
        }
    }

    /**
     * Expiration "paresseuse" (décision 3.10) : à appeler à la lecture d'un trajet.
     * Un trajet dont l'heure de départ est passée de plus de 24h sans jamais avoir
     * été démarré est considéré comme manqué → ANNULE.
     */
    public function revaliderExpiration(Trajet $trajet): bool
    {
        $statut = $trajet->getStatut();
        if (!in_array($statut, [self::STATUT_OUVERT, self::STATUT_COMPLET, self::STATUT_EN_ATTENTE_DEPART], true)) {
            return false;
        }

        $dateDepart = $trajet->getDateDepart();
        if ($dateDepart === null) {
            return false;
        }

        if ((new \DateTime()) > $dateDepart->modify('+24 hours')) {
            return $this->annuler($trajet);
        }

        return false;
    }

    /**
     * Évaluation paresseuse de toutes les transitions basées sur le temps.
     * À appeler à la lecture d'un trajet ou d'une liste de trajets.
     *
     * OUVERT/COMPLET → EN_ATTENTE_DEPART  (départ dans ≤30 min)
     * EN_ATTENTE_DEPART → EN_COURS         (heure de départ atteinte)
     * EN_COURS → EN_ATTENTE_VALIDATION     (heure d'arrivée atteinte)
     */
    public function evaluerTransitions(Trajet $trajet): void
    {
        $statut = $trajet->getStatut();
        $maintenant = new \DateTime();

        if (in_array($statut, [self::STATUT_OUVERT, self::STATUT_COMPLET, self::STATUT_EN_ATTENTE_DEPART], true)) {
            $datetimeDepart = $this->calculerDatetimeDepart($trajet);
            if ($datetimeDepart === null) {
                return;
            }

            // Expiration : départ dépassé de +24h sans démarrage → ANNULE
            if ($maintenant > clone $datetimeDepart->modify('+24 hours')) {
                $this->annuler($trajet);
                return;
            }

            // OUVERT/COMPLET → EN_ATTENTE_DEPART (départ dans ≤30 min)
            if (in_array($statut, [self::STATUT_OUVERT, self::STATUT_COMPLET], true)) {
                $limite30min = (clone $datetimeDepart)->modify('-30 minutes');
                if ($maintenant >= $limite30min) {
                    $this->passerEnAttenteDepart($trajet);
                    $this->envoyerNotification(
                        $trajet->getConducteur(),
                        'Rappel de départ',
                        'Votre trajet ' . $trajet->getVilleDepart() . ' → ' . $trajet->getVilleArrivee() . ' commence dans 30 minutes.',
                        'rappel_depart_30min',
                        $trajet
                    );
                    $this->notifierPassagersConfirme($trajet, 'Rappel de trajet', 'Votre trajet commence dans 30 minutes.', 'rappel_depart_30min');
                    return;
                }
            }

            // EN_ATTENTE_DEPART → EN_COURS (heure de départ atteinte)
            if ($statut === self::STATUT_EN_ATTENTE_DEPART && $maintenant >= $datetimeDepart) {
                $this->demarrer($trajet);
                $this->envoyerNotification(
                    $trajet->getConducteur(),
                    'Trajet démarré',
                    'Votre trajet ' . $trajet->getVilleDepart() . ' → ' . $trajet->getVilleArrivee() . ' est en cours.',
                    'trajet_demarre',
                    $trajet
                );
                $this->notifierPassagersConfirme($trajet, 'Trajet en cours', 'Votre conducteur a démarré le trajet.', 'trajet_demarre');
                return;
            }
        }

        // EN_COURS → EN_ATTENTE_VALIDATION (heure d'arrivée atteinte)
        if ($statut === self::STATUT_EN_COURS) {
            $datetimeArrivee = $this->calculerDatetimeArrivee($trajet);
            if ($datetimeArrivee !== null && $maintenant >= $datetimeArrivee) {
                $this->arriver($trajet);
                $this->envoyerNotification(
                    $trajet->getConducteur(),
                    'Arrivée à destination',
                    'Vous êtes arrivé à ' . $trajet->getVilleArrivee() . '. Validez les présences des passagers.',
                    'arrivee_destination',
                    $trajet
                );
                $this->notifierPassagersConfirme($trajet, 'Arrivée à destination', 'Le trajet est terminé. En attente de validation.', 'arrivee_destination');
            }
        }
    }

    private function calculerDatetimeDepart(Trajet $trajet): ?\DateTime
    {
        $dateDepart = $trajet->getDateDepart();
        $heureDepart = $trajet->getHeureDepart();
        if ($dateDepart === null || $heureDepart === null) {
            return null;
        }
        $heure = explode(':', $heureDepart->format('H:i'));
        $dt = (new \DateTime($dateDepart->format('Y-m-d')));
        $dt->setTime((int)$heure[0], (int)($heure[1] ?? 0));
        return $dt;
    }

    private function calculerDatetimeArrivee(Trajet $trajet): ?\DateTime
    {
        $dateDepart = $trajet->getDateDepart();
        $heureArrivee = $trajet->getHeureArriveeEstimee();
        if ($dateDepart === null) {
            return null;
        }
        if ($heureArrivee !== null) {
            $heure = explode(':', $heureArrivee->format('H:i'));
            $dt = (new \DateTime($dateDepart->format('Y-m-d')));
            $dt->setTime((int)$heure[0], (int)($heure[1] ?? 0));
            // Si l'heure d'arrivée est avant l'heure de départ, c'est le lendemain
            $datetimeDepart = $this->calculerDatetimeDepart($trajet);
            if ($datetimeDepart !== null && $dt < $datetimeDepart) {
                $dt->modify('+1 day');
            }
            return $dt;
        }
        // Fallback : départ + 2 heures
        $datetimeDepart = $this->calculerDatetimeDepart($trajet);
        if ($datetimeDepart !== null) {
            return $datetimeDepart->modify('+2 hours');
        }
        return null;
    }

    private function envoyerNotification(Utilisateur $destinataire, string $titre, string $message, string $type, Trajet $trajet): void
    {
        if ($destinataire === null) {
            return;
        }
        $existing = $this->em->getRepository('App:Notification')->findOneBy([
            'destinataire' => $destinataire,
            'type' => $type,
            'trajet' => $trajet,
        ]);
        if ($existing === null) {
            $this->notifService->notifier($destinataire, $titre, $message, $type, $trajet);
        }
    }

    private function notifierPassagersConfirme(Trajet $trajet, string $titre, string $message, string $type): void
    {
        foreach ($trajet->getReservations() as $reservation) {
            if ($reservation->getStatut() === 'CONFIRMEE') {
                $passager = $reservation->getPassager();
                if ($passager !== null) {
                    $this->envoyerNotification($passager, $titre, $message, $type, $trajet);
                }
            }
        }
    }
}
