<?php

namespace App\Service;

use App\Entity\Trajet;
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

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
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
}
