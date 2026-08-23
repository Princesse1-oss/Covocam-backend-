<?php

namespace App\Controller;

use App\Entity\Paiement;
use App\Entity\Reservation;
use App\Entity\Notification;
use App\Entity\Evaluation;
use App\Repository\ReservationRepository;
use App\Repository\TrajetRepository;
use App\Service\TrajetLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/reservations', name: 'api_reservations_')]
class ReservationController extends AbstractController
{
    // ✅ MÉTHODE RÉÉCRITE EN ORM PUR (plus de SQL brut fragile)
    #[Route('/mes-reservations', name: 'mes_reservations', methods: ['GET'])]
    public function getMesReservations(EntityManagerInterface $em, TrajetLifecycleService $lifecycle): JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user instanceof \App\Entity\Utilisateur) {
                return new JsonResponse(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
            }

            $reservations = $em->createQueryBuilder()
                ->select('r', 't', 'c')
                ->from(Reservation::class, 'r')
                ->leftJoin('r.trajet', 't')
                ->leftJoin('t.conducteur', 'c')
                ->where('r.passager = :user')
                ->setParameter('user', $user)
                ->orderBy('r.dateReservation', 'DESC')
                ->getQuery()
                ->getResult();

            $formattedResults = [];
            foreach ($reservations as $r) {
                $trajet = $r->getTrajet();
                if ($trajet) {
                    $lifecycle->evaluerTransitions($trajet);
                }
                $conducteur = $trajet ? $trajet->getConducteur() : null;
                $formattedResults[] = [
                    'id' => $r->getId(),
                    'statut' => $r->getStatut(),
                    'prixTotal' => $r->getPrixTotal() ?? 0,
                    'placesReservees' => $r->getPlacesReservees() ?? 0,
                    'dateReservation' => $r->getDateReservation() ? $r->getDateReservation()->format('Y-m-d H:i:s') : null,
                    'trajet' => $trajet ? [
                        'id' => $trajet->getId(),
                        'statut' => $trajet->getStatut(),
                        'villeDepart' => $trajet->getVilleDepart(),
                        'villeArrivee' => $trajet->getVilleArrivee(),
                        'dateDepart' => $trajet->getDateDepart() ? $trajet->getDateDepart()->format('Y-m-d') : null,
                        'heureDepart' => $trajet->getHeureDepart() ? $trajet->getHeureDepart()->format('H:i') : null,
                        'heureArriveeEstimee' => $trajet->getHeureArriveeEstimee() ? $trajet->getHeureArriveeEstimee()->format('H:i') : null,
                        'conducteur' => $conducteur ? [
                            'id' => $conducteur->getId(),
                            'nom' => $conducteur->getNom(),
                            'prenom' => $conducteur->getPrenom(),
                            'photo' => $conducteur->getPhoto(),
                            'telephone' => $conducteur->getTelephone(),
                        ] : null
                    ] : null
                ];
            }

            return new JsonResponse($formattedResults);
            
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ERREUR_FATALE' => true,
                'message' => $e->getMessage(),
                'fichier' => $e->getFile(),
                'ligne' => $e->getLine()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ✅ MÉTHODE POUR LE DASHBOARD CONDUCTEUR (restaurée)
    #[Route('/conducteur/mes-reservations', name: 'conducteur_mes_reservations', methods: ['GET'])]
    public function getMesReservationsConducteur(ReservationRepository $reservationRepository): JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user instanceof \App\Entity\Utilisateur) {
                return new JsonResponse(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
            }

            $dateLimite = new \DateTime();
            $dateLimite->modify('-30 days');

            $reservations = $reservationRepository->createQueryBuilder('r')
                ->join('r.trajet', 't')
                ->where('t.conducteur = :conducteur')
                ->andWhere('t.dateDepart >= :dateLimite')
                ->setParameter('conducteur', $user)
                ->setParameter('dateLimite', $dateLimite)
                ->orderBy('r.dateReservation', 'DESC')
                ->getQuery()
                ->getResult();

            $data = [];
            foreach ($reservations as $r) {
                $passager = $r->getPassager();
                $trajet = $r->getTrajet();
                
                $data[] = [
                    'id' => $r->getId(),
                    'placesReservees' => $r->getPlacesReservees(),
                    'statut' => $r->getStatut(),
                    'dateReservation' => $r->getDateReservation() ? $r->getDateReservation()->format('Y-m-d H:i:s') : null,
                    'passager' => $passager ? [
                        'id' => $passager->getId(), 
                        'nom' => $passager->getNom() ?? 'Inconnu',
                        'prenom' => $passager->getPrenom() ?? 'Inconnu',
                        'photo' => $passager->getPhoto(),
                    ] : null,
                    'trajet' => $trajet ? [
                        'id' => $trajet->getId(),
                        'statut' => $trajet->getStatut(),
                        'villeDepart' => $trajet->getVilleDepart() ?? 'N/A',
                        'villeArrivee' => $trajet->getVilleArrivee() ?? 'N/A',
                        'dateDepart' => $trajet->getDateDepart() ? $trajet->getDateDepart()->format('Y-m-d') : null,
                        'heureDepart' => $trajet->getHeureDepart() ? $trajet->getHeureDepart()->format('H:i') : null,
                    ] : null,
                ];
            }

            return new JsonResponse($data);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'ERREUR: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ✅ CRÉER UNE RÉSERVATION (avec notifications)
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request, TrajetRepository $trajetRepository, EntityManagerInterface $entityManager): JsonResponse 
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\Utilisateur) {
            return new JsonResponse(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $trajet = $trajetRepository->find($data['trajetId'] ?? null);
        
        if (!$trajet) {
            return new JsonResponse(['error' => 'Trajet non trouvé'], Response::HTTP_NOT_FOUND);
        }
        if (!in_array(strtoupper($trajet->getStatut()), ['OUVERT', 'OPEN', 'COMPLET'])) {
            return new JsonResponse(['error' => 'Ce trajet n\'est plus disponible'], Response::HTTP_BAD_REQUEST);
        }

        $placesDemandees = $data['placesReservees'] ?? $data['nbPlaces'] ?? 1;
        $placesDispo = method_exists($trajet, 'getPlacesDisponibles') ? $trajet->getPlacesDisponibles() : ($trajet->getNbPlaces() ?? 0);

        if ($placesDemandees > $placesDispo) {
            return new JsonResponse(['error' => 'Places insuffisantes'], Response::HTTP_BAD_REQUEST);
        }
        if ($trajet->getConducteur()->getId() === $user->getId()) {
            return new JsonResponse(['error' => 'Vous ne pouvez pas réserver votre propre trajet'], Response::HTTP_BAD_REQUEST);
        }

        $existingReservation = $entityManager->getRepository(Reservation::class)->findOneBy([
            'trajet' => $trajet,
            'passager' => $user,
        ]);
        if ($existingReservation && !in_array($existingReservation->getStatut(), ['ANNULEE', 'REFUSEE', 'NON_PRESENT'])) {
            return new JsonResponse(['error' => 'Vous avez déjà réservé ce trajet.'], Response::HTTP_BAD_REQUEST);
        }

        $reservation = new Reservation();
        $reservation->setPlacesReservees($placesDemandees);
        $prix = method_exists($trajet, 'getPrixParPlace') ? $trajet->getPrixParPlace() : 0;
        $reservation->setPrixTotal($placesDemandees * $prix);
        $reservation->setCommission($reservation->getPrixTotal() * 0.10);
        $reservation->setStatut('EN_ATTENTE');
        $reservation->setPassager($user);
        $reservation->setTrajet($trajet);

        $nouvellesPlacesDispo = $placesDispo - $placesDemandees;
        if (method_exists($trajet, 'setPlacesDisponibles')) {
            $trajet->setPlacesDisponibles($nouvellesPlacesDispo);
        }
        if ($nouvellesPlacesDispo <= 0) {
            $trajet->setStatut('COMPLET');
        }

        // Notification au conducteur
        $conducteur = $trajet->getConducteur();
        $notifConducteur = new Notification();
        $notifConducteur->setTitre('📋 Nouvelle demande de réservation');
        $notifConducteur->setMessage(sprintf('%s %s a demandé à réserver %d place(s) pour votre trajet %s → %s.', $user->getPrenom(), $user->getNom(), $placesDemandees, $trajet->getVilleDepart(), $trajet->getVilleArrivee()));
        $notifConducteur->setType('reservation');
        $notifConducteur->setDestinataire($conducteur);
        $notifConducteur->setTrajet($trajet);
        $notifConducteur->setReservation($reservation);
        $notifConducteur->setIcone('📋');
        $notifConducteur->setCouleur('#f97316');
        $notifConducteur->setUrl('/conducteur/reservations');
        
        $entityManager->persist($notifConducteur);
        $entityManager->persist($reservation);
        $entityManager->flush();

        return new JsonResponse(['message' => 'Réservation effectuée. En attente de confirmation.', 'id' => $reservation->getId()], Response::HTTP_CREATED);
    }

    // ✅ ACCEPTER UNE RÉSERVATION (avec notification)
    #[Route('/{id}/accepter', name: 'accepter', methods: ['POST'])]
    public function accepter(Reservation $reservation, EntityManagerInterface $entityManager): JsonResponse 
    {
        $user = $this->getUser();
        if (!$user || $reservation->getTrajet()->getConducteur()->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }
        if ($reservation->getStatut() !== 'EN_ATTENTE') {
            return new JsonResponse(['error' => 'Statut invalide'], Response::HTTP_BAD_REQUEST);
        }

        $reservation->setStatut('A_PAYER');
        $reservation->setDateConfirmation(new \DateTimeImmutable());

        // Notification au passager
        $passager = $reservation->getPassager();
        $trajet = $reservation->getTrajet();
        $notifPassager = new Notification();
        $notifPassager->setTitre('✅ Réservation acceptée');
        $notifPassager->setMessage(sprintf('Le conducteur %s %s a accepté votre demande. Cliquez sur "Payer" pour confirmer.', $user->getPrenom(), $user->getNom()));
        $notifPassager->setType('acceptation');
        $notifPassager->setDestinataire($passager);
        $notifPassager->setTrajet($trajet);
        $notifPassager->setReservation($reservation);
        $notifPassager->setIcone('✅');
        $notifPassager->setCouleur('#16a34a');
        $notifPassager->setUrl('/passager/reservations');
        
        $entityManager->persist($notifPassager);
        $entityManager->flush();

        return new JsonResponse(['message' => 'Réservation acceptée. Le passager peut maintenant payer.']);
    }

    // ✅ REFUSER UNE RÉSERVATION (avec notification et libération des places)
    #[Route('/{id}/refuser', name: 'refuser', methods: ['POST'])]
    public function refuser(Reservation $reservation, EntityManagerInterface $entityManager): JsonResponse 
    {
        $user = $this->getUser();
        if (!$user || $reservation->getTrajet()->getConducteur()->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }
        if ($reservation->getStatut() !== 'EN_ATTENTE') {
            return new JsonResponse(['error' => 'Statut invalide'], Response::HTTP_BAD_REQUEST);
        }

        $reservation->setStatut('REFUSEE');
        $trajet = $reservation->getTrajet();
        
        // Libérer les places
        $placesDispo = method_exists($trajet, 'getPlacesDisponibles') ? $trajet->getPlacesDisponibles() : 0;
        $nouvellesPlacesDispo = $placesDispo + $reservation->getPlacesReservees();
        
        if (method_exists($trajet, 'setPlacesDisponibles')) {
            $trajet->setPlacesDisponibles($nouvellesPlacesDispo);
        }
        if ($trajet->getStatut() === 'COMPLET' && $nouvellesPlacesDispo > 0) {
            $trajet->setStatut('OUVERT');
        }

        // Notification au passager
        $passager = $reservation->getPassager();
        $notifPassager = new Notification();
        $notifPassager->setTitre('❌ Réservation refusée');
        $notifPassager->setMessage(sprintf('Le conducteur %s %s a refusé votre demande. Les places ont été libérées.', $user->getPrenom(), $user->getNom()));
        $notifPassager->setType('refus');
        $notifPassager->setDestinataire($passager);
        $notifPassager->setTrajet($trajet);
        $notifPassager->setReservation($reservation);
        $notifPassager->setIcone('❌');
        $notifPassager->setCouleur('#dc2626');
        $notifPassager->setUrl('/passager/dashboard');
        
        $entityManager->persist($notifPassager);
        $entityManager->flush();

        return new JsonResponse(['message' => 'Réservation refusée. Places libérées.']);
    }

    // ✅ ANNULER UNE RÉSERVATION (par le passager)
    #[Route('/{id}/annuler', name: 'annuler', methods: ['POST'])]
    public function annuler(Reservation $reservation, EntityManagerInterface $entityManager): JsonResponse 
    {
        $user = $this->getUser();
        if (!$user || $reservation->getPassager()->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        if (in_array($reservation->getStatut(), ['ANNULEE', 'CONFIRMEE', 'REMBOURSEE'])) {
            return new JsonResponse(['error' => 'Cette réservation ne peut plus être annulée.'], Response::HTTP_BAD_REQUEST);
        }

        $trajet = $reservation->getTrajet();
        $maintenant = new \DateTime();
        if ($trajet->getDateDepart() && $trajet->getDateDepart() < $maintenant) {
            return new JsonResponse(['error' => 'Impossible d\'annuler un trajet déjà terminé.'], Response::HTTP_BAD_REQUEST);
        }

        $reservation->setStatut('ANNULEE');
        $reservation->setDateAnnulation(new \DateTimeImmutable());
        
        // Libérer les places
        $placesDispoActuelles = method_exists($trajet, 'getPlacesDisponibles') ? $trajet->getPlacesDisponibles() : 0;
        $nouvellesPlacesDispo = $placesDispoActuelles + $reservation->getPlacesReservees();
        
        if (method_exists($trajet, 'setPlacesDisponibles')) {
            $trajet->setPlacesDisponibles($nouvellesPlacesDispo);
        }
        if ($trajet->getStatut() === 'COMPLET' && $nouvellesPlacesDispo > 0) {
            $trajet->setStatut('OUVERT');
        }

        // Notification au conducteur
        $conducteur = $trajet->getConducteur();
        $notifConducteur = new Notification();
        $notifConducteur->setTitre('⚠️ Réservation annulée');
        $notifConducteur->setMessage(sprintf('%s %s a annulé sa réservation. Les places ont été libérées.', $user->getPrenom(), $user->getNom()));
        $notifConducteur->setType('annulation_passager');
        $notifConducteur->setDestinataire($conducteur);
        $notifConducteur->setTrajet($trajet);
        $notifConducteur->setReservation($reservation);
        $notifConducteur->setIcone('⚠️');
        $notifConducteur->setCouleur('#d97706');
        $notifConducteur->setUrl('/conducteur/reservations');
        
        $entityManager->persist($notifConducteur);
        $entityManager->flush();

        return new JsonResponse(['message' => 'Réservation annulée avec succès.']);
    } 

    // ✅ PAIEMENT D'UNE RÉSERVATION
    #[Route('/{id}/payer', name: 'payer', methods: ['POST'])]
    public function payer(Reservation $reservation, EntityManagerInterface $entityManager): JsonResponse 
    {
        $user = $this->getUser();
        if (!$user || $reservation->getPassager()->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }
        if ($reservation->getStatut() !== 'A_PAYER') {
            return new JsonResponse(['error' => 'Cette réservation ne peut pas être payée pour le moment'], Response::HTTP_BAD_REQUEST);
        }

        $reservation->setStatut('CONFIRMEE');
        $trajet = $reservation->getTrajet();

        $montantTotal = $reservation->getPrixTotal();
        $commission = round($montantTotal * 0.10, 0);
        $montantNet = $montantTotal - $commission;

        $paiement = new Paiement();
        $paiement->setReservation($reservation);
        $paiement->setCampayReference('SIM-' . uniqid());
        $paiement->setMontantTotal($montantTotal);
        $paiement->setCommission($commission);
        $paiement->setMontantNetConducteur($montantNet);
        $paiement->setStatut('REUSSI');
        $paiement->setDatePaiement(new \DateTimeImmutable());
        $entityManager->persist($paiement);

        // Notification au conducteur
        $conducteur = $trajet->getConducteur();
        $notifConducteur = new Notification();
        $notifConducteur->setTitre('💰 Paiement reçu');
        $notifConducteur->setMessage(sprintf('%s %s a effectué le paiement de %s FCFA.', $user->getPrenom(), $user->getNom(), number_format($reservation->getPrixTotal(), 0, ',', ' ')));
        $notifConducteur->setType('paiement');
        $notifConducteur->setDestinataire($conducteur);
        $notifConducteur->setTrajet($trajet);
        $notifConducteur->setReservation($reservation);
        $notifConducteur->setIcone('💰');
        $notifConducteur->setCouleur('#16a34a');
        $notifConducteur->setUrl('/conducteur/reservations');
        
        $entityManager->persist($notifConducteur);

        // Notification au passager (confirmation de paiement)
        $notifPassager = new Notification();
        $notifPassager->setTitre('✅ Paiement confirmé');
        $notifPassager->setMessage(sprintf('Votre paiement de %s FCFA pour le trajet %s → %s a été confirmé. Votre place est réservée !', number_format($reservation->getPrixTotal(), 0, ',', ' '), $trajet->getVilleDepart(), $trajet->getVilleArrivee()));
        $notifPassager->setType('paiement_confirme');
        $notifPassager->setDestinataire($user);
        $notifPassager->setTrajet($trajet);
        $notifPassager->setReservation($reservation);
        $notifPassager->setIcone('✅');
        $notifPassager->setCouleur('#16a34a');
        $notifPassager->setUrl('/passager/reservations');
        $entityManager->persist($notifPassager);

        $entityManager->flush();

        return new JsonResponse(['message' => 'Paiement réussi ! Votre place est confirmée.']);
    }

    // ✅ ADMIN : RÉSERVATIONS D'UN TRAJET
    #[Route('/trajet/{id}', name: 'trajet_reservations', methods: ['GET'])]
    public function getReservationsByTrajet(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new JsonResponse(['error' => 'Accès réservé à l\'administrateur'], Response::HTTP_FORBIDDEN);
        }

        $trajet = $em->getRepository(\App\Entity\Trajet::class)->find($id);
        if (!$trajet) {
            return new JsonResponse(['error' => 'Trajet non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $reservations = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->where('r.trajet = :trajet')
            ->orderBy('r.dateReservation', 'ASC')
            ->setParameter('trajet', $trajet)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($reservations as $reservation) {
            $passager = $reservation->getPassager();
            $result[] = [
                'id' => $reservation->getId(),
                'nbPlaces' => $reservation->getPlacesReservees(),
                'prixTotal' => $reservation->getPrixTotal(),
                'statut' => $reservation->getStatut(),
                'dateReservation' => $reservation->getDateReservation()?->format('Y-m-d H:i:s'),
                'passager' => $passager ? [
                    'id' => $passager->getId(),
                    'nom' => $passager->getNom(),
                    'prenom' => $passager->getPrenom(),
                    'email' => $passager->getEmail(),
                    'telephone' => $passager->getTelephone()
                ] : null,
            ];
        }

        return new JsonResponse($result);
    }

    // ✅ DÉTAILS D'UNE RÉSERVATION
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $reservation = $em->getRepository(Reservation::class)->find($id);
        if (!$reservation) {
            return new JsonResponse(['error' => 'Réservation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $isPassager = $reservation->getPassager()->getId() === $user->getId();
        $isConducteur = $reservation->getTrajet()->getConducteur()->getId() === $user->getId();

        if (!$isPassager && !$isConducteur) {
            return new JsonResponse(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'id' => $reservation->getId(),
            'statut' => $reservation->getStatut(),
            'placesReservees' => $reservation->getPlacesReservees(),
            'prixTotal' => $reservation->getPrixTotal(),
            'dateReservation' => $reservation->getDateReservation() ? $reservation->getDateReservation()->format('Y-m-d H:i:s') : null,
            'passager' => [
                'id' => $reservation->getPassager()->getId(),
                'nom' => $reservation->getPassager()->getNom(),
                'prenom' => $reservation->getPassager()->getPrenom(),
                'photo' => $reservation->getPassager()->getPhoto(),
            ],
            'trajet' => [
                'id' => $reservation->getTrajet()->getId(),
                'villeDepart' => $reservation->getTrajet()->getVilleDepart(),
                'villeArrivee' => $reservation->getTrajet()->getVilleArrivee(),
                'dateDepart' => $reservation->getTrajet()->getDateDepart() ? $reservation->getTrajet()->getDateDepart()->format('Y-m-d') : null,
                'heureDepart' => $reservation->getTrajet()->getHeureDepart() ? $reservation->getTrajet()->getHeureDepart()->format('H:i') : null,
                'conducteur' => [
                    'prenom' => $reservation->getTrajet()->getConducteur()->getPrenom(),
                    'nom' => $reservation->getTrajet()->getConducteur()->getNom(),
                ]
            ]
        ]);
    }

    // ✅ ÉVALUER UN CONDUCTEUR APRÈS LE TRAJET
    #[Route('/{id}/evaluer', name: 'evaluer', methods: ['POST'])]
    public function evaluerConducteur(int $id, Request $request, ReservationRepository $reservationRepository, EntityManagerInterface $entityManager): JsonResponse 
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\Utilisateur) {
            return new JsonResponse(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $reservation = $reservationRepository->find($id);
        if (!$reservation || $reservation->getPassager()->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Réservation non trouvée ou non autorisée'], Response::HTTP_FORBIDDEN);
        }

        $trajet = $reservation->getTrajet();
        $maintenant = new \DateTime();
        
        if (!$trajet->getDateDepart() || $trajet->getDateDepart() > $maintenant) {
            return new JsonResponse(['error' => 'Le trajet n\'est pas encore terminé.'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $note = (int)($data['note'] ?? 0);
        $commentaire = $data['commentaire'] ?? '';

        if ($note < 1 || $note > 5) {
            return new JsonResponse(['error' => 'La note doit être comprise entre 1 et 5.'], Response::HTTP_BAD_REQUEST);
        }

        if (!class_exists(Evaluation::class)) {
            return new JsonResponse(['error' => 'La fonctionnalité d\'évaluation n\'est pas encore configurée.'], Response::HTTP_NOT_IMPLEMENTED);
        }

        $evaluation = new Evaluation(); 
        $evaluation->setReservation($reservation);
        $evaluation->setAuteur($user);
        $evaluation->setCible($trajet->getConducteur());
        $evaluation->setTrajet($trajet);
        $evaluation->setNote($note);
        $evaluation->setCommentaire($commentaire);
        $evaluation->setDateEvaluation(new \DateTimeImmutable());

        $entityManager->persist($evaluation);
        $entityManager->flush();

        return new JsonResponse(['message' => 'Évaluation envoyée avec succès ! Merci.'], Response::HTTP_CREATED);
    }
}