<?php

namespace App\Controller;

use App\Entity\Trajet;
use App\Entity\Reservation;
use App\Repository\TrajetRepository;
use App\Repository\UtilisateurRepository;
use App\Repository\ReservationRepository;
use App\Repository\PaiementRepository;
use App\Service\CampayService;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/admin', name: 'api_admin_')]
class AdminController extends AbstractController
{
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function getStats(
        UtilisateurRepository $utilisateurRepository,
        TrajetRepository $trajetRepository,
        ReservationRepository $reservationRepository,
        PaiementRepository $paiementRepository
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $stats = [
            'utilisateurs' => [
                'total' => $utilisateurRepository->count([]),
                'conducteurs' => $utilisateurRepository->count(['typeUtilisateur' => 'conducteur']),
                'passagers' => $utilisateurRepository->count(['typeUtilisateur' => 'passager']),
                'actifs' => $utilisateurRepository->count(['estActif' => true])
            ],
            'trajets' => [
                'total' => $trajetRepository->count([]),
                'ouverts' => $trajetRepository->count(['statut' => 'OUVERT']),
                'complets' => $trajetRepository->count(['statut' => 'COMPLET']),
                'annules' => $trajetRepository->count(['statut' => 'ANNULE'])
            ],
            'reservations' => [
                'total' => $reservationRepository->count([]),
                'en_attente' => $reservationRepository->count(['statut' => 'EN_ATTENTE']),
                'acceptees' => $reservationRepository->count(['statut' => 'CONFIRMEE']) + $reservationRepository->count(['statut' => 'A_PAYER']),
                'refusees' => $reservationRepository->count(['statut' => 'REFUSEE']),
                'annulees' => $reservationRepository->count(['statut' => 'ANNULEE'])
            ],
            'paiements' => [
                'total' => $paiementRepository->count([]),
                'reussis' => $paiementRepository->count(['statut' => 'REUSSI']),
                'en_attente' => $paiementRepository->count(['statut' => 'EN_ATTENTE']),
                'rembourses' => $paiementRepository->count(['statut' => 'REMBOURSE'])
            ]
        ];

        return $this->json($stats);
    }

    #[Route('/utilisateurs', name: 'users', methods: ['GET'])]
    public function getUsers(UtilisateurRepository $utilisateurRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $utilisateurs = $utilisateurRepository->findAll();
        $result = [];
        foreach ($utilisateurs as $utilisateur) {
            $result[] = [
                'id' => $utilisateur->getId(),
                'nom' => $utilisateur->getNom(),
                'prenom' => $utilisateur->getPrenom(),
                'email' => $utilisateur->getEmail(),
                'telephone' => $utilisateur->getTelephone(),
                'photo' => $utilisateur->getPhoto(),
                'typeUtilisateur' => $utilisateur->getTypeUtilisateur(),
                'estActif' => $utilisateur->isEstActif(),
                'noteMoyenne' => $utilisateur->getNoteMoyenne(),
                'dateCreation' => $utilisateur->getDateCreation()?->format('Y-m-d H:i:s')
            ];
        }

        return $this->json($result);
    }

    #[Route('/utilisateurs/{id}', name: 'delete_user', methods: ['DELETE'])]
    public function deleteUser(int $id, UtilisateurRepository $utilisateurRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $admin = $this->getUser();
        if (!$admin || !in_array('ROLE_ADMIN', $admin->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }
        $utilisateur = $utilisateurRepository->find($id);
        if (!$utilisateur) {
            return $this->json(['error' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }
        if (in_array('ROLE_ADMIN', $utilisateur->getRoles())) {
            return $this->json(['error' => 'Impossible de supprimer un administrateur'], Response::HTTP_BAD_REQUEST);
        }
        $entityManager->remove($utilisateur);
        $entityManager->flush();
        return $this->json(['message' => 'Utilisateur supprimé avec succès']);
    }

    #[Route('/utilisateurs/{id}', name: 'update_user', methods: ['PUT'])]
    public function updateUser(
        int $id,
        Request $request,
        UtilisateurRepository $utilisateurRepository,
        EntityManagerInterface $entityManager,
        \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $admin = $this->getUser();
        if (!$admin || !in_array('ROLE_ADMIN', $admin->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }
        $utilisateur = $utilisateurRepository->find($id);
        if (!$utilisateur) {
            return $this->json(['error' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }
        $data = json_decode($request->getContent(), true);
        if (isset($data['nom'])) $utilisateur->setNom($data['nom']);
        if (isset($data['prenom'])) $utilisateur->setPrenom($data['prenom']);
        if (isset($data['email'])) $utilisateur->setEmail($data['email']);
        if (isset($data['telephone'])) $utilisateur->setTelephone($data['telephone']);
        if (isset($data['typeUtilisateur'])) $utilisateur->setTypeUtilisateur($data['typeUtilisateur']);
        if (isset($data['motDePasse'])) {
            $hashed = $passwordHasher->hashPassword($utilisateur, $data['motDePasse']);
            $utilisateur->setMotDePasse($hashed);
        }
        if (isset($data['estActif'])) $utilisateur->setEstActif($data['estActif']);
        if (isset($data['roles'])) $utilisateur->setRoles($data['roles']);
        $entityManager->flush();
        return $this->json(['message' => 'Utilisateur mis à jour', 'id' => $utilisateur->getId()]);
    }

    #[Route('/utilisateurs/{id}/suspendre', name: 'suspendre', methods: ['POST'])]
    public function suspendreUtilisateur(
        int $id,
        UtilisateurRepository $utilisateurRepository,
        EntityManagerInterface $entityManager,
        EmailService $emailService
    ): JsonResponse {
        $admin = $this->getUser();
        if (!$admin || !in_array('ROLE_ADMIN', $admin->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $utilisateur = $utilisateurRepository->find($id);
        if (!$utilisateur) {
            return $this->json(['error' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        // On ne fait quelque chose que s'il est actuellement actif
        if ($utilisateur->isEstActif() === true) {
            // 1. Modifier le statut
            $utilisateur->setEstActif(false);
            $utilisateur->setDateModification(new \DateTimeImmutable());
            
            // 2. SAUVEGARDER EN BASE DE DONNÉES (CRUCIAL)
            $entityManager->flush();

            // 3. ENVOYER L'EMAIL (après la sauvegarde)
            try {
                $emailService->sendSuspensionEmail($utilisateur->getEmail(), $utilisateur->getPrenom());
                $emailEnvoye = true;
            } catch (\Exception $e) {
                $emailEnvoye = false;
                error_log("❌ Erreur envoi email suspension : " . $e->getMessage());
            }

            return $this->json([
                'message' => 'Utilisateur suspendu avec succès.',
                'emailEnvoye' => $emailEnvoye,
                'utilisateur' => [
                    'id' => $utilisateur->getId(),
                    'estActif' => $utilisateur->isEstActif()
                ]
            ]);
        }

        return $this->json(['message' => 'Cet utilisateur est déjà suspendu.']);
    }

    #[Route('/utilisateurs/{id}/activer', name: 'activer', methods: ['POST'])]
    public function activerUtilisateur(
        int $id,
        UtilisateurRepository $utilisateurRepository,
        EntityManagerInterface $entityManager,
        EmailService $emailService
    ): JsonResponse {
        $admin = $this->getUser();
        if (!$admin || !in_array('ROLE_ADMIN', $admin->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $utilisateur = $utilisateurRepository->find($id);
        if (!$utilisateur) {
            return $this->json(['error' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        // On ne fait quelque chose que s'il est actuellement inactif (false ou null)
        if ($utilisateur->isEstActif() === false || $utilisateur->isEstActif() === null) {
            // 1. Modifier le statut
            $utilisateur->setEstActif(true);
            $utilisateur->setDateModification(new \DateTimeImmutable());
            
            // 2. SAUVEGARDER EN BASE DE DONNÉES (CRUCIAL)
            $entityManager->flush();

            // 3. ENVOYER L'EMAIL
            try {
                $emailService->sendReactivationEmail($utilisateur->getEmail(), $utilisateur->getPrenom());
                $emailEnvoye = true;
            } catch (\Exception $e) {
                $emailEnvoye = false;
                error_log("❌ Erreur envoi email réactivation : " . $e->getMessage());
            }

            return $this->json([
                'message' => 'Utilisateur réactivé avec succès.',
                'emailEnvoye' => $emailEnvoye,
                'utilisateur' => [
                    'id' => $utilisateur->getId(),
                    'estActif' => $utilisateur->isEstActif()
                ]
            ]);
        }

        return $this->json(['message' => 'Cet utilisateur est déjà actif.']);
    }

    #[Route('/test', methods: ['GET'])]
    public function test(): JsonResponse
    {
        return $this->json([
            'user' => $this->getUser()?->getEmail(),
            'roles' => $this->getUser()?->getRoles()
        ]);
    }

    // Liste complète des réservations (Admin) — utilisée par la page admin des réservations
    #[Route('/reservations', name: 'admin_reservations', methods: ['GET'])]
    public function getReservations(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $reservations = $em->getRepository(\App\Entity\Reservation::class)->createQueryBuilder('r')
            ->orderBy('r.dateReservation', 'DESC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($reservations as $reservation) {
            $passager = $reservation->getPassager();
            $trajet = $reservation->getTrajet();
            $conducteur = $trajet ? $trajet->getConducteur() : null;

            $result[] = [
                'id' => $reservation->getId(),
                'placesReservees' => $reservation->getPlacesReservees(),
                'prixTotal' => $reservation->getPrixTotal(),
                'statut' => $reservation->getStatut(),
                'dateReservation' => $reservation->getDateReservation()?->format('Y-m-d H:i:s'),
                'passager' => $passager ? [
                    'id' => $passager->getId(),
                    'nom' => $passager->getNom(),
                    'prenom' => $passager->getPrenom(),
                    'email' => $passager->getEmail(),
                    'photo' => $passager->getPhoto()
                ] : null,
                'trajet' => $trajet ? [
                    'id' => $trajet->getId(),
                    'villeDepart' => $trajet->getVilleDepart(),
                    'villeArrivee' => $trajet->getVilleArrivee(),
                    'dateDepart' => $trajet->getDateDepart()?->format('Y-m-d H:i:s'),
                    'prixParPlace' => $trajet->getPrixParPlace(),
                    'statut' => $trajet->getStatut(),
                    'conducteur' => $conducteur ? [
                        'id' => $conducteur->getId(),
                        'nom' => $conducteur->getNom(),
                        'prenom' => $conducteur->getPrenom(),
                        'photo' => $conducteur->getPhoto()
                    ] : null
                ] : null,
            ];
        }

        return $this->json($result);
    }

       #[Route('/trajets', name: 'trajets', methods: ['GET'])]
    public function getTrajets(TrajetRepository $trajetRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        // ✅ Filtrer les trajets : restent visibles jusqu'à 1 semaine (7 jours) après la date de départ
        $dateLimite = new \DateTime();
        $dateLimite->modify('-7 days');

        $trajets = $trajetRepository->createQueryBuilder('t')
            ->where('t.dateDepart >= :dateLimite')
            ->setParameter('dateLimite', $dateLimite)
            ->orderBy('t.dateDepart', 'DESC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($trajets as $trajet) {
            $conducteur = $trajet->getConducteur();
            $result[] = [
                'id' => $trajet->getId(),
                'villeDepart' => $trajet->getVilleDepart(),
                'villeArrivee' => $trajet->getVilleArrivee(),
                'dateDepart' => $trajet->getDateDepart()?->format('Y-m-d'),
                'heureDepart' => $trajet->getHeureDepart()?->format('H:i'),
                'statut' => $trajet->getStatut(),
                'nbPlaces' => $trajet->getPlacesDisponibles(),
                'conducteur' => $conducteur ? [
                    'nom' => $conducteur->getNom(),
                    'prenom' => $conducteur->getPrenom(),
                    'noteMoyenne' => $conducteur->getNoteMoyenne(),
                    'photo' => $conducteur->getPhoto(), 
                ] : null,
            ];
        }

        return $this->json($result);
    }

    #[Route('/recent-users', name: 'recent_users', methods: ['GET'])]
    public function getRecentUsers(UtilisateurRepository $utilisateurRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $utilisateurs = $utilisateurRepository->findBy([], ['dateCreation' => 'DESC'], 5);
        $result = [];
        foreach ($utilisateurs as $utilisateur) {
            $result[] = [
                'id' => $utilisateur->getId(),
                'nom' => $utilisateur->getNom(),
                'prenom' => $utilisateur->getPrenom(),
                'telephone' => $utilisateur->getTelephone(),
                'photo' => $utilisateur->getPhoto(),
                'typeUtilisateur' => $utilisateur->getTypeUtilisateur(),
                'estActif' => $utilisateur->isEstActif(),
                'dateCreation' => $utilisateur->getDateCreation()?->format('Y-m-d H:i:s')
            ];
        }

        return $this->json($result);
    }

       #[Route('/recent-reservations', name: 'recent_reservations', methods: ['GET'])]
    public function getRecentReservations(ReservationRepository $reservationRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        // ✅ Filtrer pour ne garder que les réservations des 7 derniers jours
        $dateLimite = new \DateTime();
        $dateLimite->modify('-7 days');

        $reservations = $reservationRepository->createQueryBuilder('r')
            ->join('r.trajet', 't')
            ->where('t.dateDepart >= :dateLimite')
            ->setParameter('dateLimite', $dateLimite)
            ->orderBy('r.dateReservation', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($reservations as $reservation) {
            $trajet = $reservation->getTrajet();
            $passager = $reservation->getPassager();
            $result[] = [
                'id' => $reservation->getId(),
                'passager' => $passager ? $passager->getPrenom() . ' ' . $passager->getNom() : 'Inconnu',
                'villeDepart' => $trajet?->getVilleDepart() ?? 'N/A',
                'villeArrivee' => $trajet?->getVilleArrivee() ?? 'N/A',
                'dateDepart' => $trajet?->getDateDepart()?->format('d/m/Y'),
                'statut' => $reservation->getStatut(),
                'dateReservation' => $reservation->getDateReservation()?->format('d/m/Y')
            ];
        }

        return $this->json($result);
    }
     
    #[Route('/reservations-par-mois', name: 'reservations_par_mois', methods: ['GET'])]
    public function getReservationsParMois(ReservationRepository $reservationRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $currentYear = date('Y');
        $data = [];
        
        for ($month = 1; $month <= 12; $month++) {
            $startDate = new \DateTime("$currentYear-$month-01");
            $endDate = (clone $startDate)->modify('last day of this month')->setTime(23, 59, 59);
            
            $count = $reservationRepository->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->where('r.dateReservation BETWEEN :start AND :end')
                ->setParameter('start', $startDate)
                ->setParameter('end', $endDate)
                ->getQuery()
                ->getSingleScalarResult();
            
            $data[] = [
                'mois' => $month,
                'count' => (int)$count
            ];
        }

        return $this->json($data);
    }

    #[Route('/stats-paiements', name: 'stats_paiements', methods: ['GET'])]
    public function getStatsPaiements(EntityManagerInterface $entityManager, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $days = (int) $request->query->get('days', 0);

        try {
            $qb = $entityManager->createQueryBuilder();
            $qb->select('p.statut, COUNT(p.id) as count')
               ->from(\App\Entity\Paiement::class, 'p')
               ->groupBy('p.statut');

            if ($days > 0) {
                $since = new \DateTimeImmutable("-{$days} days");
                $qb->andWhere('p.datePaiement >= :since')
                   ->setParameter('since', $since);
            }
            
            $results = $qb->getQuery()->getResult();
            
            $stats = [
                'confirmes' => 0,
                'en_attente' => 0,
                'rembourses' => 0,
                'total' => 0
            ];
            
            foreach ($results as $row) {
                $count = (int)$row['count'];
                $stats['total'] += $count;
                
                $status = strtoupper($row['statut']);
                if ($status === 'REUSSI') {
                    $stats['confirmes'] = $count;
                } elseif ($status === 'EN_ATTENTE') {
                    $stats['en_attente'] = $count;
                } elseif ($status === 'REMBOURSE' || $status === 'REMBOURSEE') {
                    $stats['rembourses'] = $count;
                }
            }

            return $this->json($stats);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Erreur lors du calcul des stats: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/positions', name: 'positions', methods: ['GET'])]
    public function getPositions(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $trajetsActifs = $em->getRepository(Trajet::class)->createQueryBuilder('t')
            ->innerJoin('t.conducteur', 'c')
            ->where('t.statut IN (:statuts)')
            ->setParameter('statuts', ['EN_COURS', 'EN_ATTENTE_DEPART'])
            ->andWhere('t.positionActuelleLat IS NOT NULL')
            ->andWhere('t.positionActuelleLng IS NOT NULL')
            ->getQuery()
            ->getResult();

        $positions = [];
        foreach ($trajetsActifs as $trajet) {
            $conducteur = $trajet->getConducteur();
            $positions[] = [
                'id' => $conducteur->getId(),
                'nom' => $conducteur->getNom(),
                'prenom' => $conducteur->getPrenom(),
                'photo' => $conducteur->getPhoto(),
                'lat' => $trajet->getPositionActuelleLat(),
                'lng' => $trajet->getPositionActuelleLng(),
                'trajetId' => $trajet->getId(),
                'statut' => $trajet->getStatut(),
                'villeDepart' => $trajet->getVilleDepart(),
                'villeArrivee' => $trajet->getVilleArrivee(),
                'dateDepart' => $trajet->getDateDepart()?->format('Y-m-d H:i:s'),
            ];
        }

        return $this->json($positions);
    }

    #[Route('/reservations/{id}/envoyer-argent', name: 'envoyer_argent', methods: ['POST'])]
    public function envoyerArgentConducteur(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        CampayService $campayService
    ): JsonResponse {
        $admin = $this->getUser();
        if (!$admin || !in_array('ROLE_ADMIN', $admin->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $reservation = $entityManager->getRepository(Reservation::class)->find($id);
        if (!$reservation) {
            return $this->json(['error' => 'Réservation non trouvée'], Response::HTTP_NOT_FOUND);
        }

        // Vérifier que la réservation est confirmée
        if (!in_array(strtoupper($reservation->getStatut()), ['CONFIRMEE', 'TERMINEE'])) {
            return $this->json(['error' => 'Cette réservation n\'est pas encore confirmée'], Response::HTTP_BAD_REQUEST);
        }

        $trajet = $reservation->getTrajet();
        $conducteur = $trajet->getConducteur();

        if (!$conducteur || !$conducteur->getTelephone()) {
            return $this->json(['error' => 'Le conducteur n\'a pas de numéro de téléphone'], Response::HTTP_BAD_REQUEST);
        }

        // Calculer le montant à envoyer (prix total - commission)
        $prixTotal = $reservation->getPrixTotal();
        $commission = $reservation->getCommission() ?? ($prixTotal * 0.10);
        $montantAEnvoyer = $prixTotal - $commission;

        try {
            // Envoyer l'argent via Campay
            $result = $campayService->sendMoney(
                $conducteur->getTelephone(),
                $montantAEnvoyer,
                sprintf('Paiement trajet %s → %s', $trajet->getVilleDepart(), $trajet->getVilleArrivee()),
                'RESERVATION_' . $reservation->getId()
            );

            if (!isset($result['reference'])) {
                return $this->json(['error' => 'Erreur lors de l\'envoi de l\'argent via Campay'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            // Marquer la réservation comme payée au conducteur
            $reservation->setStatut('PAYEE_CONDUCTEUR');
            $entityManager->flush();

            // Notifier le conducteur
            $notification = new \App\Entity\Notification();
            $notification->setTitre('💰 Paiement reçu');
            $notification->setMessage(sprintf(
                'Vous avez reçu %s FCFA pour votre trajet %s → %s (Commission déduite: %s FCFA).',
                number_format($montantAEnvoyer, 0, ',', ' '),
                $trajet->getVilleDepart(),
                $trajet->getVilleArrivee(),
                number_format($commission, 0, ',', ' ')
            ));
            $notification->setType('paiement_conducteur');
            $notification->setDestinataire($conducteur);
            $notification->setTrajet($trajet);
            $notification->setReservation($reservation);
            $notification->setIcone('💰');
            $notification->setCouleur('#16a34a');
            $notification->setUrl('/conducteur/paiements');
            $entityManager->persist($notification);
            $entityManager->flush();

            return $this->json([
                'message' => 'Argent envoyé avec succès au conducteur',
                'montant' => $montantAEnvoyer,
                'commission' => $commission,
                'reference' => $result['reference']
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Erreur lors de l\'envoi: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}