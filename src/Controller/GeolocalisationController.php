<?php

namespace App\Controller;

use App\Entity\PositionHistory;
use App\Entity\ConfirmationPresence;
use App\Entity\Reservation;
use App\Entity\Trajet;
use App\Repository\ReservationRepository;
use App\Service\TrajetLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/trajets', name: 'api_trajet_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class GeolocalisationController extends AbstractController
{
    // Position du conducteur (suivi temps réel pour le passager)
    // Sécurisé : réservé au conducteur ou à un passager ayant une réservation active.
    #[Route('/{id}/position-conducteur', name: 'position_conducteur', methods: ['GET'])]
    public function getPositionConducteur(int $id, EntityManagerInterface $em, ReservationRepository $reservationRepository): JsonResponse
    {
        $user = $this->getUser();
        $trajet = $em->getRepository(Trajet::class)->find($id);

        if (!$trajet) {
            return $this->json(['error' => 'Trajet non trouvé'], 404);
        }

        // Sécurité : seul le conducteur ou un passager à réservation active peut suivre
        if ($trajet->getConducteur()->getId() !== $user->getId()) {
            $reservation = $reservationRepository->findActiveByTrajetEtPassager($trajet, $user);
            if (!$reservation) {
                return $this->json(['error' => 'Non autorisé'], 403);
            }
        }

        $lastPosition = $em->getRepository(PositionHistory::class)
            ->findDernierePositionParTrajet($trajet);

        if (!$lastPosition) {
            return $this->json(['position' => null, 'message' => 'Aucune position disponible']);
        }

        return $this->json([
            'position' => [
                'lat' => $lastPosition->getLatitude(),
                'lng' => $lastPosition->getLongitude()
            ],
            'timestamp' => $lastPosition->getTimestamp()->format('Y-m-d H:i:s'),
            'trajet_active' => $trajet->isTrajetActive(),
            'statut' => $trajet->getStatut()
        ]);
    }

    // Endpoint de secours (le PUT /api/conducteur/trajets/{id}/position reste le chemin principal)
    #[Route('/{id}/update-position', name: 'update_position', methods: ['POST'])]
    public function updatePosition(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        TrajetLifecycleService $lifecycle
    ): JsonResponse {
        $user = $this->getUser();
        $trajet = $em->getRepository(Trajet::class)->find($id);

        if (!$trajet || $trajet->getConducteur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['lat']) || !isset($data['lng'])) {
            return $this->json(['error' => 'Coordonnées manquantes'], 400);
        }

        $position = new PositionHistory();
        $position->setTrajet($trajet);
        $position->setUtilisateur($user);
        $position->setLatitude($data['lat']);
        $position->setLongitude($data['lng']);

        if (isset($data['vitesse'])) {
            $position->setVitesse($data['vitesse']);
        }

        $em->persist($position);

        $trajet->setPositionActuelleLat($data['lat']);
        $trajet->setPositionActuelleLng($data['lng']);

        if (!$trajet->isTrajetActive()) {
            $lifecycle->demarrer($trajet);
        }

        $em->flush();

        return $this->json(['success' => true, 'statut' => $trajet->getStatut()]);
    }

    #[Route('/{id}/demarrer', name: 'demarrer', methods: ['POST'])]
    public function demarrerTrajet(int $id, EntityManagerInterface $em, TrajetLifecycleService $lifecycle): JsonResponse
    {
        $user = $this->getUser();
        $trajet = $em->getRepository(Trajet::class)->find($id);

        if (!$trajet || $trajet->getConducteur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        if (!$lifecycle->demarrer($trajet)) {
            return $this->json(['error' => 'Ce trajet ne peut pas être démarré dans son état actuel (' . $trajet->getStatut() . ')'], 400);
        }

        $em->flush();

        return $this->json(['success' => true, 'message' => 'Trajet démarré', 'statut' => $trajet->getStatut()]);
    }

    #[Route('/{id}/terminer', name: 'terminer', methods: ['POST'])]
    public function terminerTrajet(int $id, EntityManagerInterface $em, TrajetLifecycleService $lifecycle): JsonResponse
    {
        $user = $this->getUser();
        $trajet = $em->getRepository(Trajet::class)->find($id);

        if (!$trajet || $trajet->getConducteur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        if (!$lifecycle->arriver($trajet)) {
            return $this->json(['error' => 'Ce trajet ne peut pas être clôturé dans son état actuel (' . $trajet->getStatut() . ')'], 400);
        }

        $em->flush();

        return $this->json(['success' => true, 'message' => 'Arrivée enregistrée, validation des présences requise', 'statut' => $trajet->getStatut()]);
    }

    #[Route('/{id}/confirmer-presence', name: 'confirmer_presence', methods: ['POST'])]
    public function confirmerPresence(
        int $id,
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();
        $trajet = $em->getRepository(Trajet::class)->find($id);

        if (!$trajet) {
            return $this->json(['error' => 'Trajet non trouvé'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $estPresent = $data['est_present'] ?? true;
        $gpsVerifie = $data['gps_verifie'] ?? false;

        $confirmation = $em->getRepository(ConfirmationPresence::class)
            ->findByTrajetEtUtilisateur($trajet, $user);

        if (!$confirmation) {
            $confirmation = new ConfirmationPresence();
            $confirmation->setTrajet($trajet);
            $confirmation->setUtilisateur($user);
        }

        $confirmation->setEstPresent($estPresent);
        $confirmation->setGpsVerifie($gpsVerifie);

        $em->persist($confirmation);
        $em->flush();

        return $this->json(['success' => true, 'message' => 'Présence confirmée']);
    }
}
