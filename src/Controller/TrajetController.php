<?php

namespace App\Controller;

use App\Entity\Trajet;
use App\Entity\Reservation;
use App\Entity\Notification;
use App\Entity\PositionHistory;
use App\Repository\TrajetRepository;
use App\Repository\ReservationRepository; // ✅ AJOUTÉ : Nécessaire pour les annulations et présences
use App\Repository\VehiculeRepository;
use App\Service\NotificationService;
use App\Service\PrixService;
use App\Service\TrajetLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api', name: 'api_')]
class TrajetController extends AbstractController
{
    public function __construct(private PrixService $prixService)
    {
    }
    // =========================================================================
    // 1. Création d'un trajet (Conducteur)
    // =========================================================================
    #[Route('/conducteur/trajets', name: 'conducteur_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        VehiculeRepository $vehiculeRepository
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->getTypeUtilisateur() !== 'conducteur' && $user->getTypeUtilisateur() !== 'les_deux') {
            return $this->json(['error' => 'Vous devez être conducteur pour publier un trajet'], Response::HTTP_FORBIDDEN);
        }

        if (!$user->getPhoto()) {
            return $this->json(['error' => 'Sécurité : Vous devez ajouter une photo de profil avant de publier un trajet.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $vehiculeId = $data['vehiculeId'] ?? null;

        if (!$vehiculeId) {
            return $this->json(['error' => 'Véhicule non spécifié.'], Response::HTTP_BAD_REQUEST);
        }

        $vehicule = $vehiculeRepository->find($vehiculeId);
        if (!$vehicule) {
            return $this->json(['error' => 'Véhicule introuvable.'], Response::HTTP_BAD_REQUEST);
        }

        if ($vehicule->getUtilisateur()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Ce véhicule ne vous appartient pas.'], Response::HTTP_FORBIDDEN);
        }

        $villeDepart = $data['villeDepart'] ?? '';
        $villeArrivee = $data['villeArrivee'] ?? '';
        
        if (strtolower($villeDepart) === strtolower($villeArrivee)) {
            return $this->json(['error' => 'La ville de départ et d\'arrivée doivent être différentes.'], Response::HTTP_BAD_REQUEST);
        }
        
        $trajet = new Trajet();
        $trajet->setVilleDepart($villeDepart);
        $trajet->setVilleArrivee($villeArrivee);
        
        if (method_exists($trajet, 'setQuartierDepart')) $trajet->setQuartierDepart($data['quartierDepart'] ?? '');
        if (method_exists($trajet, 'setQuartierArrivee')) $trajet->setQuartierArrivee($data['quartierArrivee'] ?? '');
        if (method_exists($trajet, 'setPointDepart')) $trajet->setPointDepart($data['pointDepart'] ?? '');
        if (method_exists($trajet, 'setPointArrivee')) $trajet->setPointArrivee($data['pointArrivee'] ?? '');

        $dateDepartStr = $data['dateDepart'] ?? '';
        $heureDepartStr = $data['heureDepart'] ?? '';
        
        if (!empty($dateDepartStr) && !empty($heureDepartStr) && method_exists($trajet, 'setDateDepart')) {
            $dateDepartComplete = new \DateTime($dateDepartStr . ' ' . $heureDepartStr);
            if ($dateDepartComplete <= new \DateTime()) {
                return $this->json(['error' => 'La date et l\'heure de départ doivent être dans le futur.'], Response::HTTP_BAD_REQUEST);
            }
            $trajet->setDateDepart($dateDepartComplete);
            if (method_exists($trajet, 'setHeureDepart')) {
                $trajet->setHeureDepart($dateDepartComplete);
            }
        }

        if (!empty($data['heureArriveeEstimee']) && method_exists($trajet, 'setHeureArriveeEstimee')) {
            $heureArrivee = \DateTime::createFromFormat('H:i', $data['heureArriveeEstimee']);
            if ($heureArrivee) {
                $trajet->setHeureArriveeEstimee($heureArrivee);
            }
        }

        $nbPlaces = (int)($data['nbPlaces'] ?? 1);
        if (method_exists($trajet, 'setPlacesDisponibles')) $trajet->setPlacesDisponibles($nbPlaces);
        elseif (method_exists($trajet, 'setNbPlaces')) $trajet->setNbPlaces($nbPlaces);

        $prix = (int)($data['prixParPassager'] ?? 0);
        if ($prix < 500) {
            return $this->json(['error' => 'Le prix minimum est de 500 FCFA.'], Response::HTTP_BAD_REQUEST);
        }

        // ✅ POINT 7 : prix plafonné par distance pour protéger le passager
        $prixMax = $this->prixService->calculer(
            $villeDepart,
            $villeArrivee,
            $nbPlaces,
            isset($data['pointDepartLat']) ? (float) $data['pointDepartLat'] : null,
            isset($data['pointDepartLng']) ? (float) $data['pointDepartLng'] : null,
            isset($data['pointArriveeLat']) ? (float) $data['pointArriveeLat'] : null,
            isset($data['pointArriveeLng']) ? (float) $data['pointArriveeLng'] : null
        )['prixMax'];

        if ($prix > $prixMax) {
            return $this->json(['error' => sprintf('Le prix est plafonné à %d FCFA pour cette route.', (int) $prixMax)], Response::HTTP_BAD_REQUEST);
        }

        if (method_exists($trajet, 'setPrixParPlace')) $trajet->setPrixParPlace($prix);
        elseif (method_exists($trajet, 'setPrixParPassager')) $trajet->setPrixParPassager($prix);

        // Coordonnées GPS (optionnelles, permettent un prix précis et le suivi)
        if (isset($data['pointDepartLat'])) $trajet->setPointDepartLat((float) $data['pointDepartLat']);
        if (isset($data['pointDepartLng'])) $trajet->setPointDepartLng((float) $data['pointDepartLng']);
        if (isset($data['pointArriveeLat'])) $trajet->setPointArriveeLat((float) $data['pointArriveeLat']);
        if (isset($data['pointArriveeLng'])) $trajet->setPointArriveeLng((float) $data['pointArriveeLng']);

        if (method_exists($trajet, 'setDescription')) $trajet->setDescription($data['description'] ?? null);

        $trajet->setStatut(in_array($data['statut'] ?? '', ['BROUILLON']) ? 'BROUILLON' : 'OUVERT');
        $trajet->setConducteur($user);
        $trajet->setVehicule($vehicule);

        $errors = $validator->validate($trajet);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], Response::HTTP_BAD_REQUEST);
        }

        $entityManager->persist($trajet);
        $entityManager->flush();

        return $this->json([
            'message' => 'Trajet publié avec succès',
            'trajet' => $this->formatTrajet($trajet)
        ], Response::HTTP_CREATED);
    }

    // =========================================================================
    // 2. Récupérer la liste des villes et leurs quartiers
    // =========================================================================
    #[Route('/villes-quartiers', name: 'villes_quartiers', methods: ['GET'])]
    public function getVillesEtQuartiers(): JsonResponse
    {
        $jsonPath = $this->getParameter('kernel.project_dir') . '/data/villes-quartiers.json';
        if (!file_exists($jsonPath)) {
            return $this->json(['error' => 'Fichier introuvable.'], Response::HTTP_NOT_FOUND);
        }
        return $this->json(json_decode(file_get_contents($jsonPath), true));
    }

    // =========================================================================
    // 3. Rechercher des trajets (Passager)
    // =========================================================================
    #[Route('/trajets/search', name: 'trajets_search', methods: ['GET'])]
    public function search(Request $request, TrajetRepository $trajetRepository, TrajetLifecycleService $lifecycle): JsonResponse
    {
        $villeDepart = $request->query->get('villeDepart');
        $villeArrivee = $request->query->get('villeArrivee');
        $date = $request->query->get('date');
        $aujourdhui = new \DateTime('today midnight');

        $qb = $trajetRepository->createQueryBuilder('t')
            ->where('t.statut IN (:statuts)')
            ->setParameter('statuts', TrajetLifecycleService::STATUTS_RESERVABLES)
            ->andWhere('t.placesDisponibles > 0')
            ->andWhere('t.dateDepart >= :aujourdhui')
            ->setParameter('aujourdhui', $aujourdhui)
            ->orderBy('t.dateDepart', 'ASC');

        if ($villeDepart) $qb->andWhere('t.villeDepart LIKE :villeDepart')->setParameter('villeDepart', '%' . $villeDepart . '%');
        if ($villeArrivee) $qb->andWhere('t.villeArrivee LIKE :villeArrivee')->setParameter('villeArrivee', '%' . $villeArrivee . '%');
        if ($date) $qb->andWhere('t.dateDepart >= :date')->setParameter('date', $date);

        $result = [];
        foreach ($qb->getQuery()->getResult() as $trajet) {
            // Transitions paresseuses basées sur le temps
            $lifecycle->evaluerTransitions($trajet);
            $result[] = $this->formatTrajet($trajet);
        }

        return new JsonResponse($result);
    }
    
    // =========================================================================
    // 4. Consulter les trajets d'un conducteur
    // =========================================================================
    #[Route('/conducteur/trajets', name: 'conducteur_list', methods: ['GET'])]
    public function getMesTrajets(TrajetRepository $trajetRepository, TrajetLifecycleService $lifecycle): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);

        $dateLimite = (new \DateTime())->modify('-2 days');

        $qb = $trajetRepository->createQueryBuilder('t')
            ->where('t.conducteur = :user')
            ->andWhere('t.dateDepart >= :dateLimite')
            ->setParameter('user', $user)
            ->setParameter('dateLimite', $dateLimite)
            ->orderBy('t.dateDepart', 'ASC');

        $result = [];
        foreach ($qb->getQuery()->getResult() as $trajet) {
            $lifecycle->evaluerTransitions($trajet);
            $result[] = $this->formatTrajet($trajet);
        }
        return $this->json($result);
    }
    
    // =========================================================================
    // 5. Consulter un trajet spécifique
    // =========================================================================
    #[Route('/trajets/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Trajet $trajet, TrajetLifecycleService $lifecycle): JsonResponse
    {
        $lifecycle->evaluerTransitions($trajet);
        return $this->json($this->formatTrajet($trajet));
    }

    // Suppression d'un trajet (Admin uniquement)
    #[Route('/trajets/{id}', name: 'admin_delete_trajet', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->json(['error' => 'Accès réservé à l\'administrateur'], Response::HTTP_FORBIDDEN);
        }

        $trajet = $entityManager->getRepository(Trajet::class)->find($id);
        if (!$trajet) {
            return $this->json(['error' => 'Trajet non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $erreur = $this->supprimerTrajet($trajet, $entityManager);
        if ($erreur !== null) {
            return $this->json(['error' => $erreur], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['message' => 'Trajet supprimé avec succès']);
    }

    // Suppression d'un trajet par son conducteur (brouillons / trajets sans réservation active)
    #[Route('/conducteur/trajets/{id}', name: 'conducteur_delete_trajet', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteConducteur(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $trajet = $entityManager->getRepository(Trajet::class)->find($id);
        if (!$trajet) {
            return $this->json(['error' => 'Trajet non trouvé'], Response::HTTP_NOT_FOUND);
        }

        if (!$trajet->getConducteur() || $trajet->getConducteur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $erreur = $this->supprimerTrajet($trajet, $entityManager);
        if ($erreur !== null) {
            return $this->json(['error' => $erreur], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['message' => 'Trajet supprimé avec succès']);
    }

    private function supprimerTrajet(Trajet $trajet, EntityManagerInterface $entityManager): ?string
    {
        $reservationsActives = $entityManager->getRepository(Reservation::class)->createQueryBuilder('r')
            ->where('r.trajet = :trajet')
            ->andWhere('r.statut IN (:statuts)')
            ->setParameter('trajet', $trajet)
            ->setParameter('statuts', ['EN_ATTENTE', 'A_PAYER', 'CONFIRMEE'])
            ->getQuery()
            ->getResult();

        if (count($reservationsActives) > 0) {
            return sprintf('Impossible de supprimer ce trajet : %d réservation(s) active(s). Annulez-les d\'abord.', count($reservationsActives));
        }

        // Détacher les notifications liées (FK sans cascade)
        $entityManager->createQueryBuilder()
            ->update(Notification::class, 'n')
            ->set('n.trajet', ':nul')
            ->where('n.trajet = :trajet')
            ->setParameter('nul', null)
            ->setParameter('trajet', $trajet)
            ->getQuery()
            ->execute();

        $reservations = $trajet->getReservations();
        if (count($reservations) > 0) {
            $entityManager->createQueryBuilder()
                ->update(Notification::class, 'n')
                ->set('n.reservation', ':nul')
                ->where('n.reservation IN (:reservations)')
                ->setParameter('nul', null)
                ->setParameter('reservations', $reservations)
                ->getQuery()
                ->execute();
        }

        try {
            $entityManager->remove($trajet);
            $entityManager->flush();
        } catch (\Throwable $e) {
            return 'Impossible de supprimer ce trajet (dépendances liées).';
        }

        return null;
    }

    // =========================================================================
    // 6. Modifier / Compléter un trajet (Conducteur) - ✅ FUSIONNÉ ET CORRIGÉ
    // =========================================================================
    #[Route('/conducteur/trajets/{id}', name: 'conducteur_update', methods: ['PUT'])]
    public function update(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        TrajetRepository $trajetRepository,
        ValidatorInterface $validator
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $trajet = $trajetRepository->find($id);
        if (!$trajet || $trajet->getConducteur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Vous n\'êtes pas autorisé à modifier ce trajet'], Response::HTTP_FORBIDDEN);
        }

        // Seuls les trajets en BROUILLON ou OUVERT peuvent être modifiés
        if (!in_array($trajet->getStatut(), ['BROUILLON', 'OUVERT'])) {
            return $this->json(['error' => 'Ce trajet ne peut plus être modifié'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['villeDepart'])) $trajet->setVilleDepart($data['villeDepart']);
        if (isset($data['villeArrivee'])) $trajet->setVilleArrivee($data['villeArrivee']);
        
        if (isset($data['quartierDepart']) && method_exists($trajet, 'setQuartierDepart')) $trajet->setQuartierDepart($data['quartierDepart']);
        if (isset($data['quartierArrivee']) && method_exists($trajet, 'setQuartierArrivee')) $trajet->setQuartierArrivee($data['quartierArrivee']);

        if (isset($data['dateDepart']) && method_exists($trajet, 'setDateDepart')) {
            $trajet->setDateDepart(new \DateTime($data['dateDepart']));
        }
        if (isset($data['heureDepart']) && method_exists($trajet, 'setHeureDepart')) {
            $trajet->setHeureDepart(new \DateTime($data['heureDepart']));
        }
        if (isset($data['heureArriveeEstimee']) && method_exists($trajet, 'setHeureArriveeEstimee')) {
            $heureArrivee = \DateTime::createFromFormat('H:i', $data['heureArriveeEstimee']);
            if ($heureArrivee) $trajet->setHeureArriveeEstimee($heureArrivee);
        }
        
        if (isset($data['placesDisponibles']) && method_exists($trajet, 'setPlacesDisponibles')) {
            $trajet->setPlacesDisponibles((int)$data['placesDisponibles']);
        }
        if (isset($data['prixParPlace']) && method_exists($trajet, 'setPrixParPlace')) {
            $trajet->setPrixParPlace((int)$data['prixParPlace']);
        }
        if (isset($data['description']) && method_exists($trajet, 'setDescription')) {
            $trajet->setDescription($data['description']);
        }

        // ✅ GESTION DU VÉHICULE (Pour les brouillons)
        if (isset($data['vehiculeId'])) {
            $vehicule = $entityManager->getRepository(\App\Entity\Vehicule::class)->find($data['vehiculeId']);
            if ($vehicule && $vehicule->getUtilisateur()->getId() === $user->getId()) {
                $trajet->setVehicule($vehicule);
            } else {
                return $this->json(['error' => 'Véhicule invalide ou ne vous appartient pas'], Response::HTTP_BAD_REQUEST);
            }
        }

        // ✅ GESTION DES POINTS DE RDV (Pour les brouillons)
        if (isset($data['pointDepart']) && method_exists($trajet, 'setPointDepart')) {
            $trajet->setPointDepart($data['pointDepart']);
        }
        if (isset($data['pointArrivee']) && method_exists($trajet, 'setPointArrivee')) {
            $trajet->setPointArrivee($data['pointArrivee']);
        }

        if (isset($data['statut']) && in_array($data['statut'], ['OUVERT', 'BROUILLON'])) {
            $trajet->setStatut($data['statut']);
        }

        if (method_exists($trajet, 'setUpdatedAt')) {
            $trajet->setUpdatedAt(new \DateTimeImmutable());
        }

        $errors = $validator->validate($trajet);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], Response::HTTP_BAD_REQUEST);
        }

        $entityManager->flush();

        return $this->json([
            'message' => 'Trajet mis à jour avec succès',
            'trajet' => $this->formatTrajet($trajet)
        ]);
    }

    #[Route('/conducteur/trajets/{id}/completer', name: 'conducteur_completer', methods: ['PUT'])]
    public function completer(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        TrajetRepository $trajetRepository,
        NotificationService $notificationService
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);

        $trajet = $trajetRepository->find($id);
        if (!$trajet || $trajet->getConducteur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Trajet non trouvé ou non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $demande = $em->getRepository(\App\Entity\DemandeTrajet::class)->findOneBy(['trajetCree' => $trajet]);
        if (!$demande) {
            return $this->json(['error' => 'Aucune demande associée à ce trajet'], Response::HTTP_NOT_FOUND);
        }

        if (!in_array($demande->getStatut(), ['ACCEPTEE', 'CONFIRMEE'], true)) {
            return $this->json(['error' => 'Cette demande n\'est pas en attente de complétion.'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['vehiculeId'])) {
            $vehicule = $em->getRepository(\App\Entity\Vehicule::class)->find($data['vehiculeId']);
            if ($vehicule && $vehicule->getUtilisateur()->getId() === $user->getId()) {
                $trajet->setVehicule($vehicule);
                $placesTotales = (int) $vehicule->getPlaces();
                $placesDemandees = (int) $demande->getNbPlaces();
                if ($placesTotales < $placesDemandees) {
                    return $this->json(['error' => 'Le véhicule n\'a pas assez de places.'], Response::HTTP_BAD_REQUEST);
                }
                $trajet->setPlacesDisponibles($placesTotales - $placesDemandees);
            } else {
                return $this->json(['error' => 'Véhicule invalide.'], Response::HTTP_BAD_REQUEST);
            }
        }

        if (isset($data['heureArriveeEstimee']) && method_exists($trajet, 'setHeureArriveeEstimee')) {
            $heureArrivee = \DateTime::createFromFormat('H:i', $data['heureArriveeEstimee']);
            if ($heureArrivee) $trajet->setHeureArriveeEstimee($heureArrivee);
        }
        if (isset($data['description'])) $trajet->setDescription($data['description']);
        if (isset($data['bagagesAutorises']) && method_exists($trajet, 'setBagagesAutorises')) $trajet->setBagagesAutorises($data['bagagesAutorises']);
        if (isset($data['gpsEnabled']) && method_exists($trajet, 'setGpsEnabled')) $trajet->setGpsEnabled($data['gpsEnabled']);

        if (!$trajet->getVehicule()) {
            return $this->json(['error' => 'Veuillez sélectionner un véhicule.'], Response::HTTP_BAD_REQUEST);
        }

        $trajet->setStatut('OUVERT');
        $demande->setStatut('CONFIRMEE');

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

        $this->notificationService->notifier(
            $demande->getPassager(),
            'Trajet confirmé !',
            sprintf('Votre trajet %s → %s est maintenant confirmé. Votre place est réservée.', $trajet->getVilleDepart(), $trajet->getVilleArrivee()),
            'trajet_confirme',
            $trajet,
            $reservation,
            '/passager/reservations'
        );

        $em->flush();

        return $this->json([
            'message' => 'Trajet publié avec succès',
            'trajet' => $this->formatTrajet($trajet)
        ]);
    }

    // =========================================================================
    // 7. Annuler un trajet (Conducteur)
    // =========================================================================
    #[Route('/conducteur/trajets/{id}/annuler', name: 'conducteur_annuler', methods: ['POST'])]
    public function annuler(
        int $id,
        EntityManagerInterface $entityManager,
        TrajetRepository $trajetRepository,
        ReservationRepository $reservationRepository,
        TrajetLifecycleService $lifecycle,
        NotificationService $notificationService
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);

        $trajet = $trajetRepository->find($id);
        if (!$trajet || $trajet->getConducteur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $maintenant = new \DateTime();
        if ($trajet->getDateDepart() && $trajet->getDateDepart() < $maintenant) {
            return $this->json(['error' => 'Impossible d\'annuler un trajet déjà terminé.'], Response::HTTP_BAD_REQUEST);
        }

        $delaiLimite = clone $trajet->getDateDepart();
        $delaiLimite->modify('-2 hours');

        if ($maintenant > $delaiLimite) {
            $reservationsActives = $reservationRepository->findActivesByTrajet($trajet);
            return $this->json([
                'error' => sprintf('Il est trop tard pour annuler. Le départ est dans moins de 2h et %d passager(s) comptent sur vous.', count($reservationsActives))
            ], Response::HTTP_BAD_REQUEST);
        }

        // Transition atomique : seuls OUVERT, COMPLET, EN_ATTENTE_DEPART sont annulables
        if (!$lifecycle->annuler($trajet)) {
            return $this->json(['error' => 'Ce trajet ne peut plus être annulé'], Response::HTTP_BAD_REQUEST);
        }

        $reservationsActives = $reservationRepository->findActivesByTrajet($trajet);
        $notificationsCount = 0;

        foreach ($reservationsActives as $reservation) {
            $reservation->setStatut('ANNULEE');
            if (method_exists($reservation, 'setMotifAnnulation')) $reservation->setMotifAnnulation('Trajet annulé par le conducteur');

            $passager = $reservation->getPassager();
            if ($passager) {
                $notificationService->notifier(
                    $passager,
                    '⚠️ Trajet annulé',
                    sprintf('Le trajet %s → %s a été annulé.', $trajet->getVilleDepart(), $trajet->getVilleArrivee()),
                    'annulation_conducteur',
                    $trajet,
                    $reservation,
                    '/passager/reservations',
                    '❌',
                    '#dc2626'
                );
                $notificationsCount++;
            }
        }

        $entityManager->flush();
        return $this->json(['message' => 'Trajet annulé', 'notificationsEnvoyees' => $notificationsCount]);
    }

    // =========================================================================
    // 8. Mise à jour position GPS (Conducteur)
    // =========================================================================
    #[Route('/conducteur/trajets/{id}/position', name: 'conducteur_update_position', methods: ['PUT'])]
    public function updatePosition(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        TrajetRepository $trajetRepository,
        TrajetLifecycleService $lifecycle
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);

        $trajet = $trajetRepository->find($id);
        if (!$trajet || $trajet->getConducteur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['latitude']) || !isset($data['longitude'])) {
            return $this->json(['error' => 'Coordonnées manquantes'], Response::HTTP_BAD_REQUEST);
        }

        $trajet->setPositionActuelleLat((float)$data['latitude']);
        $trajet->setPositionActuelleLng((float)$data['longitude']);

        // Historique des positions pour le suivi temps réel
        $position = new PositionHistory();
        $position->setTrajet($trajet);
        $position->setUtilisateur($user);
        $position->setLatitude((float)$data['latitude']);
        $position->setLongitude((float)$data['longitude']);
        if (isset($data['vitesse'])) {
            $position->setVitesse((float)$data['vitesse']);
        }
        $entityManager->persist($position);

        // Premier ping GPS = démarrage effectif du trajet (OUVERT/COMPLET/EN_ATTENTE_DEPART → EN_COURS)
        if (!$trajet->isTrajetActive()) {
            $lifecycle->demarrer($trajet);
        }

        $entityManager->flush();

        return $this->json([
            'message' => 'Position mise à jour',
            'statut' => $trajet->getStatut(),
            'trajetActive' => $trajet->isTrajetActive()
        ]);
    }

    #[Route('/trajets/{id}/position', name: 'trajet_get_position', methods: ['GET'])]
    public function getPosition(
        int $id,
        TrajetRepository $trajetRepository,
        ReservationRepository $reservationRepository
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);

        $trajet = $trajetRepository->find($id);
        if (!$trajet) return $this->json(['error' => 'Trajet non trouvé'], Response::HTTP_NOT_FOUND);

        // Sécurité : seul le conducteur ou un passager à réservation active peut voir la position
        if ($trajet->getConducteur()->getId() !== $user->getId()) {
            $reservation = $reservationRepository->findActiveByTrajetEtPassager($trajet, $user);
            if (!$reservation) {
                return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
            }
        }

        $positionVisible = $trajet->isTrajetActive();

        // Si le trajet est terminé, la position reste visible 1h après
        if ($trajet->getStatut() === 'TERMINE' && $trajet->getDateTermine()) {
            $now = new \DateTimeImmutable();
            $oneHourAfter = $trajet->getDateTermine()->modify('+1 hour');
            if ($now <= $oneHourAfter) {
                $positionVisible = true;
            }
        }

        if (!$positionVisible) {
            return $this->json([
                'latitude' => null,
                'longitude' => null,
                'statut' => $trajet->getStatut(),
                'trajetActive' => false,
                'positionExpiriee' => $trajet->getStatut() === 'TERMINE',
            ]);
        }

        return $this->json([
            'latitude' => $trajet->getPositionActuelleLat(),
            'longitude' => $trajet->getPositionActuelleLng(),
            'statut' => $trajet->getStatut(),
            'trajetActive' => $trajet->isTrajetActive()
        ]);
    }

    // =========================================================================
    // 9. Réservations d'un trajet pour validation des présences (Conducteur)
    // =========================================================================
    #[Route('/conducteur/trajets/{id}/reservations', name: 'conducteur_trajets_reservations', methods: ['GET'])]
    public function getTrajetReservations(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);

        $trajet = $entityManager->getRepository(Trajet::class)->find($id);
        if (!$trajet || $trajet->getConducteur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $reservations = $entityManager->getRepository(Reservation::class)->findBy([
            'trajet' => $trajet
        ], ['dateReservation' => 'ASC']);

        $result = [];
        foreach ($reservations as $reservation) {
            $passager = $reservation->getPassager();
            if (!$passager) continue;

            $confirmation = $entityManager->getRepository(\App\Entity\ConfirmationPresence::class)->findOneBy([
                'trajet' => $trajet,
                'utilisateur' => $passager
            ]);

            $aConfirmePresence = $confirmation !== null && ($confirmation->isEstPresent() || (bool) $confirmation->getConfirmeParConducteur());

            $result[] = [
                'id' => $reservation->getId(),
                'statut' => $reservation->getStatut(),
                'placesReservees' => $reservation->getPlacesReservees(),
                'prixTotal' => $reservation->getPrixTotal(),
                'passager' => [
                    'id' => $passager->getId(),
                    'nom' => $passager->getNom(),
                    'prenom' => $passager->getPrenom(),
                    'photo' => $passager->getPhoto(),
                    'aConfirmePresence' => $aConfirmePresence,
                    'estPresent' => $aConfirmePresence
                ]
            ];
        }

        return $this->json($result);
    }

    // =========================================================================
    // 10. Valider les présences (Conducteur)
    // =========================================================================
    #[Route('/conducteur/trajets/{id}/valider-presences', name: 'conducteur_valider_presences', methods: ['POST'])]
    public function validerPresences(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        TrajetRepository $trajetRepository,
        ReservationRepository $reservationRepository,
        TrajetLifecycleService $lifecycle,
        NotificationService $notificationService,
        \App\Service\TrajetFinanceService $financeService
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);

        $trajet = $trajetRepository->find($id);
        if (!$trajet || $trajet->getConducteur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        if (!in_array($trajet->getStatut(), ['EN_COURS', 'EN_ATTENTE_VALIDATION'])) {
            return $this->json(['error' => 'Ce trajet n\'est pas en cours de validation'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $presences = $data['presences'] ?? [];

        if (empty($presences)) {
            return $this->json(['error' => 'Aucune donnée fournie'], Response::HTTP_BAD_REQUEST);
        }

        $nbPassagersPresents = 0;
        $prixParPlace = $trajet->getPrixParPlace();
        $tauxCommission = 0.10;

        foreach ($presences as $presenceData) {
            // Accepte {reservationId, present} (page présence) OU {passagerId, estPresent} (page détail)
            $reservation = null;
            if (!empty($presenceData['reservationId'])) {
                $reservation = $reservationRepository->find($presenceData['reservationId']);
            } elseif (!empty($presenceData['passagerId'])) {
                $passager = $entityManager->getRepository(\App\Entity\Utilisateur::class)->find($presenceData['passagerId']);
                if ($passager) {
                    $reservation = $reservationRepository->findOneBy([
                        'trajet' => $trajet,
                        'passager' => $passager
                    ]);
                }
            }

            if ($reservation && $reservation->getTrajet()->getId() === $trajet->getId()) {
                $present = (bool) ($presenceData['present'] ?? $presenceData['estPresent'] ?? false);
                if ($present) {
                    $reservation->setStatut('TERMINEE');
                    $nbPassagersPresents++;

                    // Inviter le passager présent à évaluer le conducteur
                    $passager = $reservation->getPassager();
                    if ($passager && $passager->getId() !== $user->getId()) {
                        $notificationService->notifier(
                            $passager,
                            '⭐ Trajet terminé',
                            sprintf('Votre trajet %s → %s est terminé. Évaluez le conducteur !', $trajet->getVilleDepart(), $trajet->getVilleArrivee()),
                            'trajet_termine',
                            $trajet,
                            $reservation,
                            '/passager/reservations',
                            '⭐',
                            '#f59e0b'
                        );
                    }
                } else {
                    $reservation->setStatut('NON_PRESENT');
                    $absentPassager = $reservation->getPassager();
                    if ($absentPassager && $absentPassager->getId() !== $user->getId()) {
                        $notificationService->notifier(
                            $absentPassager,
                            '❌ Trajet manqué',
                            sprintf(
                                'Vous avez manqué le trajet %s → %s du %s à %s. Votre réservation a été annulée.',
                                $trajet->getVilleDepart(),
                                $trajet->getVilleArrivee(),
                                $trajet->getDateDepart()?->format('d/m/Y') ?: '',
                                $trajet->getHeureDepart()?->format('H:i') ?: ''
                            ),
                            'trajet_manque',
                            $trajet,
                            $reservation,
                            '/passager/reservations',
                            '❌',
                            '#EF4444'
                        );
                    }
                }
            }
        }

        $chiffreAffaires = $nbPassagersPresents * $prixParPlace;
        $commission = $chiffreAffaires * $tauxCommission;
        $gainNet = $chiffreAffaires - $commission;

        // Transition atomique : EN_COURS / EN_ATTENTE_VALIDATION → TERMINE
        if (!$lifecycle->terminer($trajet)) {
            return $this->json(['error' => 'Impossible de terminer ce trajet dans son état actuel'], Response::HTTP_BAD_REQUEST);
        }

        // Paiements Campay + email admin (non bloquant)
        $finance = $financeService->traiter($trajet);

        $entityManager->flush();

        return $this->json([
            'message' => 'Présences validées et trajet terminé.',
            'statut' => $trajet->getStatut(),
            'resumeFinancier' => [
                'passagersPresents' => $nbPassagersPresents,
                'chiffreAffaires' => $chiffreAffaires,
                'commission' => $commission,
                'gainNet' => $gainNet
            ],
            'paiements' => $finance['recapitulatif']
        ], Response::HTTP_OK);
    }

    // =========================================================================
    // MÉTHODE PRIVÉE : Formatage d'un trajet pour l'API ✅ NETTOYÉE
    // =========================================================================
    private function formatTrajet(Trajet $trajet): array
    {
        $reservationsCount = 0;
        $confirmedReservations = 0;
        
        if (method_exists($trajet, 'getReservations')) {
            foreach ($trajet->getReservations() as $reservation) {
                $reservationsCount++;
                $statut = strtoupper($reservation->getStatut());
                if (in_array($statut, ['CONFIRMEE', 'A_PAYER', 'TERMINEE'])) {
                    $confirmedReservations++;
                }
            }
        }

        $data = [
            'id' => $trajet->getId(),
            'villeDepart' => $trajet->getVilleDepart(),
            'villeArrivee' => $trajet->getVilleArrivee(),
            'dateDepart' => $trajet->getDateDepart() ? $trajet->getDateDepart()->format('Y-m-d H:i:s') : null,
            'statut' => $trajet->getStatut(),
            'nbReservations' => $reservationsCount,
            'nbReservationsConfirmees' => $confirmedReservations,
            
            // Champs optionnels gérés proprement sans duplication
            'quartierDepart' => method_exists($trajet, 'getQuartierDepart') ? ($trajet->getQuartierDepart() ?? '') : '',
            'quartierArrivee' => method_exists($trajet, 'getQuartierArrivee') ? ($trajet->getQuartierArrivee() ?? '') : '',
            'pointDepart' => method_exists($trajet, 'getPointDepart') ? $trajet->getPointDepart() : null,
            'pointArrivee' => method_exists($trajet, 'getPointArrivee') ? $trajet->getPointArrivee() : null,
            'heureDepart' => method_exists($trajet, 'getHeureDepart') && $trajet->getHeureDepart() ? $trajet->getHeureDepart()->format('H:i') : null,
            'heureArriveeEstimee' => method_exists($trajet, 'getHeureArriveeEstimee') && $trajet->getHeureArriveeEstimee() ? $trajet->getHeureArriveeEstimee()->format('H:i') : null,
            'placesDisponibles' => method_exists($trajet, 'getPlacesDisponibles') ? $trajet->getPlacesDisponibles() : (method_exists($trajet, 'getNbPlaces') ? $trajet->getNbPlaces() : 0),
            'prixParPlace' => method_exists($trajet, 'getPrixParPlace') ? $trajet->getPrixParPlace() : (method_exists($trajet, 'getPrixParPassager') ? $trajet->getPrixParPassager() : 0),
            'description' => method_exists($trajet, 'getDescription') ? $trajet->getDescription() : null,

            // Champs GPS & suivi
            'pointDepartLat' => $trajet->getPointDepartLat(),
            'pointDepartLng' => $trajet->getPointDepartLng(),
            'pointArriveeLat' => $trajet->getPointArriveeLat(),
            'pointArriveeLng' => $trajet->getPointArriveeLng(),
            'positionActuelleLat' => $trajet->getPositionActuelleLat(),
            'positionActuelleLng' => $trajet->getPositionActuelleLng(),
            'trajetActive' => $trajet->isTrajetActive(),
        ];

        if ($trajet->getConducteur()) {
            $data['conducteur'] = [
                'id' => $trajet->getConducteur()->getId(),
                'nom' => $trajet->getConducteur()->getNom(),
                'prenom' => $trajet->getConducteur()->getPrenom(),
                'noteMoyenne' => $trajet->getConducteur()->getNoteMoyenne(),
                'photo' => $trajet->getConducteur()->getPhoto()
            ];
        }

        if ($trajet->getVehicule()) {
            $vehiculeData = [
                'id' => $trajet->getVehicule()->getId(),
                'marque' => $trajet->getVehicule()->getMarque(),
                'modele' => $trajet->getVehicule()->getModele(),
            ];
            if (method_exists($trajet->getVehicule(), 'getCouleur')) $vehiculeData['couleur'] = $trajet->getVehicule()->getCouleur();
            if (method_exists($trajet->getVehicule(), 'getPlaqueImmatriculation')) $vehiculeData['plaqueImmatriculation'] = $trajet->getVehicule()->getPlaqueImmatriculation();
            if (method_exists($trajet->getVehicule(), 'getPhotoAvant')) $vehiculeData['photo'] = $trajet->getVehicule()->getPhotoAvant();
            if (method_exists($trajet->getVehicule(), 'getPlaces')) $vehiculeData['places'] = $trajet->getVehicule()->getPlaces();
            
            $data['vehicule'] = $vehiculeData;
        }

        if (method_exists($trajet, 'getCreatedAt')) {
            $data['createdAt'] = $trajet->getCreatedAt() ? $trajet->getCreatedAt()->format('Y-m-d H:i:s') : null;
        }

        return $data;
    }
}