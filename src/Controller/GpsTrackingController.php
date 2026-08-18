<?php

namespace App\Controller;

use App\Entity\PositionHistory;
use App\Entity\Trajet;
use App\Service\TrajetLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/tracking', name: 'api_tracking_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class GpsTrackingController extends AbstractController
{
    public function __construct(private TrajetLifecycleService $lifecycle)
    {
    }

    #[Route('/update-position', name: 'update_position', methods: ['POST'])]
    public function updatePosition(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        if (!isset($data['trajetId'], $data['lat'], $data['lng'])) {
            return $this->json(['error' => 'Données GPS ou ID du trajet manquants'], 400);
        }

        $trajet = $em->getRepository(Trajet::class)->find($data['trajetId']);
        
        // Vérification de sécurité : seul le conducteur du trajet peut mettre à jour sa position
        if (!$trajet || $trajet->getConducteur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        // 1. Enregistrer dans l'historique des positions
        $positionHistory = new PositionHistory();
        $positionHistory->setTrajet($trajet);
        $positionHistory->setUtilisateur($user);
        $positionHistory->setLatitude((float) $data['lat']);
        $positionHistory->setLongitude((float) $data['lng']);
        
        if (isset($data['vitesse'])) {
            $positionHistory->setVitesse((float) $data['vitesse']);
        }

        $em->persist($positionHistory);

        // 2. Mettre à jour la position actuelle sur le trajet (pour un accès rapide)
        $trajet->setPositionActuelleLat((float) $data['lat']);
        $trajet->setPositionActuelleLng((float) $data['lng']);
        
        // Si c'est le premier envoi, on démarre réellement le trajet (transition atomique)
        if (!$trajet->isTrajetActive()) {
            $this->lifecycle->demarrer($trajet);
        }

        $em->flush();

        return $this->json(['success' => true, 'message' => 'Position mise à jour', 'statut' => $trajet->getStatut()]);
    }
}