<?php

namespace App\Controller;

use App\Entity\DemandeTrajet;
use App\Entity\Trajet;
use App\Entity\Reservation;
use App\Entity\Notification;
use App\Entity\Utilisateur;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/demandes', name: 'api_demandes_')]
class DemandeTrajetController extends AbstractController
{
    public function __construct(private NotificationService $notificationService)
    {
    }

    // =========================================================================
    // 1. PUBLIER UNE DEMANDE (Passager)
    // =========================================================================
    #[Route('', name: 'create', methods: ['POST'])]
    public function createDemande(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Données JSON invalides'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['villeDepart']) || empty($data['villeArrivee'])) {
            return $this->json(['error' => 'La ville de départ et la ville d\'arrivée sont obligatoires'], Response::HTTP_BAD_REQUEST);
        }

        if (strtolower($data['villeDepart']) === strtolower($data['villeArrivee'])) {
            return $this->json(['error' => 'La ville de départ et d\'arrivée doivent être différentes'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $dateDepart = new \DateTime($data['dateDepart']);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Date de départ invalide'], Response::HTTP_BAD_REQUEST);
        }

        // Règle 3.9 : publication uniquement si le départ est dans plus de 48h
        if ($dateDepart <= (new \DateTime())->modify('+48 hours')) {
            return $this->json(['error' => 'La date de départ doit être dans plus de 48 heures'], Response::HTTP_BAD_REQUEST);
        }

        $demande = new DemandeTrajet();
        $demande->setPassager($user);
        $demande->setVilleDepart($data['villeDepart']);
        $demande->setVilleArrivee($data['villeArrivee']);
        $demande->setQuartierDepart($data['quartierDepart'] ?? null);
        $demande->setQuartierArrivee($data['quartierArrivee'] ?? null);
        $demande->setDateDepart($dateDepart);
        try {
            $demande->setHeureDepart(isset($data['heureDepart']) && $data['heureDepart'] ? new \DateTime($data['heureDepart']) : null);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Heure de départ invalide'], Response::HTTP_BAD_REQUEST);
        }
        $demande->setNbPlaces(max(1, (int) ($data['nbPlaces'] ?? 1)));
        $demande->setBudgetMax((float) ($data['budgetMax'] ?? 0));
        $demande->setDescription($data['description'] ?? null);

        // Expiration 48h avant le départ (min 24h)
        $dateExpiration = (clone $dateDepart)->modify('-48 hours');
        if ($dateExpiration < new \DateTime()) {
            $dateExpiration = (new \DateTime())->modify('+24 hours');
        }
        $demande->setDateExpiration($dateExpiration);
        $demande->setStatut('EN_ATTENTE');

        if (!empty($data['estPrivee']) && !empty($data['destinatairePriveEmail'])) {
            $email = trim($data['destinatairePriveEmail']);
            $destinataire = $em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
            if ($destinataire && $destinataire->getId() !== $user->getId()) {
                $demande->setEstPrivee(true);
                $demande->setDestinatairePrive($destinataire);
            } else {
                return $this->json(['error' => 'Email conducteur invalide ou identique au vôtre'], Response::HTTP_BAD_REQUEST);
            }
        }

        $em->persist($demande);
        $em->flush();

        // Notifier les conducteurs
        if ($demande->isEstPrivee() && $demande->getDestinatairePrive()) {
            $this->notificationService->notifier(
                $demande->getDestinatairePrive(),
                '📩 Nouvelle demande privée',
                sprintf('%s %s vous a envoyé une demande privée pour un trajet %s → %s le %s.',
                    $user->getPrenom(), $user->getNom(),
                    $demande->getVilleDepart(), $demande->getVilleArrivee(),
                    $dateDepart->format('d/m/Y')
                ),
                'demande_privee',
                null
            );
        } else {
            $conducteurs = $em->getRepository(Utilisateur::class)->createQueryBuilder('u')
                ->where('u.typeUtilisateur IN (:types)')
                ->andWhere('u.estActif = :actif')
                ->setParameter('types', ['conducteur', 'les_deux'])
                ->setParameter('actif', true)
                ->getQuery()
                ->getResult();

            foreach ($conducteurs as $conducteur) {
                if ($conducteur->getId() !== $user->getId()) {
                    $this->notificationService->notifier(
                        $conducteur,
                        '📩 Nouvelle demande de trajet',
                        sprintf('%s %s cherche un trajet %s → %s le %s (budget max: %s FCFA).',
                            $user->getPrenom(), $user->getNom(),
                            $demande->getVilleDepart(), $demande->getVilleArrivee(),
                            $dateDepart->format('d/m/Y'),
                            number_format($demande->getBudgetMax(), 0, ',', '.')
                        ),
                        'demande_publique',
                        null
                    );
                }
            }
        }

        return $this->json([
            'message' => 'Demande publiée avec succès !',
            'demande' => $this->formatDemande($demande)
        ], Response::HTTP_CREATED);
    }

    // =========================================================================
    // 2. DEMANDES DISPONIBLES (Conducteur)
    // =========================================================================
    #[Route('/disponibles', name: 'disponibles', methods: ['GET'])]
    public function getDemandesDisponibles(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $qb = $em->getRepository(DemandeTrajet::class)->createQueryBuilder('d')
            ->where('d.statut = :statut')
            ->andWhere('d.dateDepart > :now')
            ->setParameter('statut', 'EN_ATTENTE')
            ->setParameter('now', new \DateTime())
            ->orderBy('d.dateCreation', 'DESC');

        if ($request->query->get('villeDepart')) {
            $qb->andWhere('d.villeDepart = :villeDepart')
               ->setParameter('villeDepart', $request->query->get('villeDepart'));
        }
        if ($request->query->get('villeArrivee')) {
            $qb->andWhere('d.villeArrivee = :villeArrivee')
               ->setParameter('villeArrivee', $request->query->get('villeArrivee'));
        }
        if ($request->query->get('budgetMin')) {
            $qb->andWhere('d.budgetMax >= :budgetMin')
               ->setParameter('budgetMin', (float) $request->query->get('budgetMin'));
        }

        $demandes = $qb->getQuery()->getResult();

        $result = [];
        foreach ($demandes as $demande) {
            // Ne pas montrer sa propre demande ; la demande acceptée disparaît
            // automatiquement (statut ACCEPTEE, décision 3.8)
            if ($demande->getPassager()->getId() === $user->getId()) {
                continue;
            }
            if ($demande->isEstPrivee()) {
                if ($demande->getDestinatairePrive() && $demande->getDestinatairePrive()->getId() === $user->getId()) {
                    $result[] = $this->formatDemande($demande);
                }
            } else {
                $result[] = $this->formatDemande($demande);
            }
        }

        return $this->json($result);
    }

    // =========================================================================
    // 3. VOIR SES PROPRES DEMANDES (Passager)
    // =========================================================================
    #[Route('/mes-demandes', name: 'mes_demandes', methods: ['GET'])]
    public function getMesDemandes(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $demandes = $em->getRepository(DemandeTrajet::class)->createQueryBuilder('d')
            ->where('d.passager = :user')
            ->setParameter('user', $user)
            ->orderBy('d.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($demandes as $demande) {
            $result[] = $this->formatDemande($demande, true);
        }

        return $this->json($result);
    }

    // =========================================================================
    // 4. ACCEPTER UNE DEMANDE (Conducteur)
    // =========================================================================
    #[Route('/{id}/accepter', name: 'accepter', methods: ['POST'])]
    public function accepterDemande(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $demande = $em->getRepository(DemandeTrajet::class)->find($id);
        if (!$demande) {
            return $this->json(['error' => 'Demande non trouvée'], Response::HTTP_NOT_FOUND);
        }

        if ($demande->getStatut() !== 'EN_ATTENTE') {
            return $this->json(['error' => 'Cette demande n\'est plus disponible'], Response::HTTP_BAD_REQUEST);
        }

        $passager = $demande->getPassager();
        if (!$passager || $passager->getId() === $user->getId()) {
            return $this->json(['error' => 'Vous ne pouvez pas accepter votre propre demande'], Response::HTTP_BAD_REQUEST);
        }

        if ($demande->isEstPrivee()) {
            $destinataire = $demande->getDestinatairePrive();
            if (!$destinataire || $destinataire->getId() !== $user->getId()) {
                return $this->json(['error' => 'Cette demande privée ne vous est pas destinée'], Response::HTTP_FORBIDDEN);
            }
        }

        $data = json_decode($request->getContent(), true);
        $prixPropose = (float) ($data['prixPropose'] ?? 0);
        if ($prixPropose <= 0) {
            return $this->json(['error' => 'Vous devez proposer un prix valide'], Response::HTTP_BAD_REQUEST);
        }

        if ($demande->getBudgetMax() > 0 && $prixPropose > $demande->getBudgetMax()) {
            return $this->json(['error' => sprintf('Votre proposition (%d FCFA) dépasse le budget du passager (%d FCFA)', (int) $prixPropose, (int) $demande->getBudgetMax())], Response::HTTP_BAD_REQUEST);
        }

        $demande->setStatut('ACCEPTEE');
        $demande->setConducteurAcceptant($user);
        $demande->setPrixPropose($prixPropose);

        // Trajet brouillon lié à la demande
        $trajet = new Trajet();
        $trajet->setConducteur($user);
        $trajet->setVilleDepart((string) $demande->getVilleDepart());
        $trajet->setVilleArrivee((string) $demande->getVilleArrivee());
        $trajet->setQuartierDepart((string) ($demande->getQuartierDepart() ?? ''));
        $trajet->setQuartierArrivee((string) ($demande->getQuartierArrivee() ?? ''));
        $trajet->setDateDepart($demande->getDateDepart());

        $heureDep = $demande->getHeureDepart();
        $trajet->setHeureDepart($heureDep instanceof \DateTimeInterface ? $heureDep : $demande->getDateDepart());

        $trajet->setPrixParPlace($prixPropose);
        $trajet->setPlacesDisponibles($demande->getNbPlaces());
        $trajet->setStatut('BROUILLON');
        $trajet->setPointDepart((string) ($demande->getQuartierDepart() ?? $demande->getVilleDepart()));
        $trajet->setPointArrivee((string) ($demande->getQuartierArrivee() ?? $demande->getVilleArrivee()));

        $em->persist($trajet);
        $demande->setTrajetCree($trajet);

        // Notification au passager
        $this->notificationService->notifier(
            $passager,
            '🎉 Votre demande a été acceptée !',
            sprintf('%s %s a accepté votre demande %s → %s.', $user->getPrenom(), $user->getNom(), $demande->getVilleDepart(), $demande->getVilleArrivee()),
            'demande_acceptee',
            $trajet,
            null,
            '/passager/demandes',
            '🎉',
            '#16A34A'
        );

        $em->flush();

        return $this->json([
            'message' => 'Demande acceptée ! Le trajet a été créé en brouillon.',
            'trajetId' => $trajet->getId()
        ]);
    }

    // =========================================================================
    // 5. COMPLÉTER LE TRAJET BROUILLON (Conducteur)
    // =========================================================================
    #[Route('/{id}/completer-trajet', name: 'completer_trajet', methods: ['PUT'])]
    public function completerTrajet(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $demande = $em->getRepository(DemandeTrajet::class)->find($id);
        if (!$demande || $demande->getConducteurAcceptant()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Demande non trouvée ou non autorisée'], Response::HTTP_FORBIDDEN);
        }

        if (!in_array($demande->getStatut(), ['ACCEPTEE', 'CONFIRMEE'], true)) {
            return $this->json(['error' => 'Cette demande n\'est pas en attente de complétion.'], Response::HTTP_BAD_REQUEST);
        }

        $trajet = $demande->getTrajetCree();
        if (!$trajet) {
            return $this->json(['error' => 'Le trajet associé n\'existe pas.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['vehiculeId'])) {
            $vehicule = $em->getRepository(\App\Entity\Vehicule::class)->find($data['vehiculeId']);
            if ($vehicule && $vehicule->getUtilisateur()->getId() === $user->getId()) {
                $trajet->setVehicule($vehicule);

                // Places restantes = places véhicule (sans conducteur) - demande du passager
                $placesTotalesVehicule = (int) $vehicule->getPlaces();
                $placesDejaReservees = (int) $demande->getNbPlaces();

                if ($placesTotalesVehicule < $placesDejaReservees) {
                    return $this->json(['error' => 'Le véhicule sélectionné n\'a pas assez de places pour cette demande.'], Response::HTTP_BAD_REQUEST);
                }

                $trajet->setPlacesDisponibles($placesTotalesVehicule - $placesDejaReservees);
            } else {
                return $this->json(['error' => 'Véhicule invalide.'], Response::HTTP_BAD_REQUEST);
            }
        }

        if (isset($data['heureDepart'])) $trajet->setHeureDepart(new \DateTime($data['heureDepart']));
        if (isset($data['pointDepart'])) $trajet->setPointDepart($data['pointDepart']);
        if (isset($data['pointArrivee'])) $trajet->setPointArrivee($data['pointArrivee']);
        if (isset($data['description'])) $trajet->setDescription($data['description']);

        if (!$trajet->getVehicule()) {
            return $this->json(['error' => 'Veuillez sélectionner un véhicule.'], Response::HTTP_BAD_REQUEST);
        }

        $trajet->setStatut('OUVERT');
        $demande->setStatut('CONFIRMEE');

        // Réservation du passager demandeur (éviter les doublons)
        $reservation = $em->getRepository(Reservation::class)->findOneBy([
            'trajet' => $trajet,
            'passager' => $demande->getPassager()
        ]);

        if (!$reservation) {
            $reservation = new Reservation();
            $reservation->setPassager($demande->getPassager());
            $reservation->setTrajet($trajet);
            $reservation->setPlacesReservees($demande->getNbPlaces());
            $reservation->setPrixTotal($demande->getNbPlaces() * (float) $demande->getPrixPropose());
            $reservation->setStatut('CONFIRMEE');
            $em->persist($reservation);
        }

        // Notification au passager
        $this->notificationService->notifier(
            $demande->getPassager(),
            '✅ Trajet confirmé !',
            sprintf('Votre trajet %s → %s est maintenant confirmé. Votre place est réservée.', $trajet->getVilleDepart(), $trajet->getVilleArrivee()),
            'trajet_confirme',
            $trajet,
            $reservation,
            '/passager/reservations',
            '✅',
            '#16A34A'
        );

        $em->flush();

        return $this->json([
            'message' => 'Trajet complété et publié avec succès !',
            'trajet' => [
                'id' => $trajet->getId(),
                'statut' => $trajet->getStatut(),
                'placesDisponibles' => $trajet->getPlacesDisponibles()
            ]
        ]);
    }

    // =========================================================================
    // 6. CONFIRMER LE PAIEMENT (Passager, paiement comptant décision 3.11)
    // =========================================================================
    #[Route('/{id}/confirmer-paiement', name: 'confirmer_paiement', methods: ['POST'])]
    public function confirmerPaiement(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $demande = $em->getRepository(DemandeTrajet::class)->find($id);
        if (!$demande) {
            return $this->json(['error' => 'Demande introuvable'], Response::HTTP_NOT_FOUND);
        }

        if ($demande->getPassager()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        if (!in_array($demande->getStatut(), ['ACCEPTEE', 'CONFIRMEE'], true)) {
            return $this->json(['error' => 'Cette demande ne peut pas être confirmée pour le moment'], Response::HTTP_BAD_REQUEST);
        }

        $demande->setStatut('CONFIRMEE');

        // Si le trajet est déjà publié, s'assurer que la réservation existe (CONFIRMEE)
        $trajet = $demande->getTrajetCree();
        if ($trajet && $trajet->getStatut() !== 'BROUILLON') {
            $reservation = $em->getRepository(Reservation::class)->findOneBy([
                'trajet' => $trajet,
                'passager' => $user
            ]);

            if (!$reservation) {
                $reservation = new Reservation();
                $reservation->setPassager($user);
                $reservation->setTrajet($trajet);
                $reservation->setPlacesReservees($demande->getNbPlaces());
                $reservation->setPrixTotal($demande->getNbPlaces() * (float) $demande->getPrixPropose());
                $reservation->setStatut('CONFIRMEE');
                $em->persist($reservation);
            }
        }

        // Notifier le conducteur
        if ($demande->getConducteurAcceptant()) {
            $this->notificationService->notifier(
                $demande->getConducteurAcceptant(),
                '💰 Paiement confirmé',
                sprintf('%s %s a confirmé le paiement de votre proposition (%d FCFA).', $user->getPrenom(), $user->getNom(), (int) $demande->getPrixPropose()),
                'paiement_confirme',
                $trajet,
                null,
                '/conducteur/demandes',
                '💰',
                '#16A34A'
            );
        }

        $em->flush();

        return $this->json([
            'message' => 'Paiement confirmé avec succès !',
            'statut' => 'CONFIRMEE'
        ]);
    }

    // =========================================================================
    // 7. ANNULER UNE DEMANDE (Passager)
    // =========================================================================
    #[Route('/{id}', name: 'annuler', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function annulerDemande(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $demande = $em->getRepository(DemandeTrajet::class)->find($id);
        if (!$demande || $demande->getPassager()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Demande non trouvée ou non autorisée'], Response::HTTP_FORBIDDEN);
        }

        if (!in_array($demande->getStatut(), ['EN_ATTENTE', 'ACCEPTEE'], true)) {
            return $this->json(['error' => 'Cette demande ne peut plus être annulée.'], Response::HTTP_BAD_REQUEST);
        }

        if ($demande->getStatut() === 'ACCEPTEE' && $demande->getTrajetCree()) {
            $this->notificationService->notifier(
                $demande->getConducteurAcceptant(),
                '❌ Demande annulée',
                sprintf('%s %s a annulé sa demande %s → %s. Le trajet associé a été supprimé.', $user->getPrenom(), $user->getNom(), $demande->getVilleDepart(), $demande->getVilleArrivee()),
                'demande_annulee',
                $demande->getTrajetCree(),
                null,
                '/conducteur/demandes',
                '❌',
                '#DC2626'
            );

            $em->remove($demande->getTrajetCree());
        }

        $demande->setStatut('ANNULEE');
        $em->flush();

        return $this->json(['message' => 'Demande annulée avec succès.']);
    }

    // =========================================================================
    // 8. HISTORIQUE COMPLET (Passager et Conducteur)
    // =========================================================================
    #[Route('/historique', name: 'historique', methods: ['GET'])]
    public function getHistorique(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $demandesPassager = $em->getRepository(DemandeTrajet::class)->createQueryBuilder('d')
            ->where('d.passager = :user')
            ->andWhere('d.statut IN (:statuts)')
            ->setParameter('user', $user)
            ->setParameter('statuts', ['CONFIRMEE', 'EXPIREE', 'ANNULEE'])
            ->orderBy('d.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();

        $demandesConducteur = $em->getRepository(DemandeTrajet::class)->createQueryBuilder('d')
            ->where('d.conducteurAcceptant = :user')
            ->andWhere('d.statut IN (:statuts)')
            ->setParameter('user', $user)
            ->setParameter('statuts', ['CONFIRMEE', 'EXPIREE'])
            ->orderBy('d.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->json([
            'enTantQuePassager' => array_map(fn($d) => $this->formatDemande($d, true), $demandesPassager),
            'enTantQueConducteur' => array_map(fn($d) => $this->formatDemande($d, true), $demandesConducteur)
        ]);
    }

    // =========================================================================
    // MÉTHODE PRIVÉE : Formatage de la demande
    // =========================================================================
    private function formatDemande(DemandeTrajet $demande, bool $inclureConducteur = false): array
    {
        $data = [
            'id' => $demande->getId(),
            'villeDepart' => $demande->getVilleDepart(),
            'villeArrivee' => $demande->getVilleArrivee(),
            'quartierDepart' => $demande->getQuartierDepart(),
            'quartierArrivee' => $demande->getQuartierArrivee(),
            'dateDepart' => $demande->getDateDepart()?->format('Y-m-d'),
            'heureDepart' => $demande->getHeureDepart()?->format('H:i'),
            'nbPlaces' => $demande->getNbPlaces(),
            'budgetMax' => $demande->getBudgetMax(),
            'description' => $demande->getDescription(),
            'statut' => $demande->getStatut(),
            'dateCreation' => $demande->getDateCreation()?->format('Y-m-d H:i:s'),
            'dateExpiration' => $demande->getDateExpiration()?->format('Y-m-d H:i:s'),
            'prixPropose' => $demande->getPrixPropose(),
            'estPrivee' => $demande->isEstPrivee(),
            'passager' => $demande->getPassager() ? [
                'id' => $demande->getPassager()->getId(),
                'nom' => $demande->getPassager()->getNom(),
                'prenom' => $demande->getPassager()->getPrenom(),
                'photo' => $demande->getPassager()->getPhoto()
            ] : null,
            'trajetCreeId' => $demande->getTrajetCree()?->getId()
        ];

        if ($inclureConducteur && $demande->getConducteurAcceptant()) {
            $data['conducteurAcceptant'] = [
                'id' => $demande->getConducteurAcceptant()->getId(),
                'nom' => $demande->getConducteurAcceptant()->getNom(),
                'prenom' => $demande->getConducteurAcceptant()->getPrenom(),
                'photo' => $demande->getConducteurAcceptant()->getPhoto(),
                'noteMoyenne' => $demande->getConducteurAcceptant()->getNoteMoyenne()
            ];
        }

        return $data;
    }
}
