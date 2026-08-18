<?php

namespace App\Controller;

use App\Entity\ConfirmationPresence;
use App\Entity\Reservation;
use App\Entity\Trajet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/trajets', name: 'api_trajets_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class PresenceController extends AbstractController
{
    // 1. Le passager confirme sa propre présence
    #[Route('/{id}/je-suis-la', name: 'passager_confirme_presence', methods: ['POST'])]
    public function passagerConfirme(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $trajet = $em->getRepository(Trajet::class)->find($id);

        if (!$trajet) {
            return $this->json(['error' => 'Trajet non trouvé'], 404);
        }

        // Vérifier que l'utilisateur a bien une réservation pour ce trajet
        $reservation = $em->getRepository(Reservation::class)->findOneBy([
            'trajet' => $trajet,
            'passager' => $user
        ]);

        if (!$reservation) {
            return $this->json(['error' => 'Vous n\'avez pas de réservation pour ce trajet'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $lat = $data['lat'] ?? null;
        $lng = $data['lng'] ?? null;

        // Créer ou mettre à jour la confirmation
        $confirmation = $em->getRepository(ConfirmationPresence::class)->findOneBy([
            'trajet' => $trajet,
            'utilisateur' => $user
        ]) ?: new ConfirmationPresence();

        $confirmation->setTrajet($trajet);
        $confirmation->setUtilisateur($user);
        $confirmation->setEstPresent(true);
        $confirmation->setGpsVerifie($lat !== null && $lng !== null);
        // On ne met pas confirmeParConducteur à true ici, c'est le conducteur qui le fera

        $em->persist($confirmation);
        $em->flush();

        return $this->json(['success' => true, 'message' => 'Présence enregistrée']);
    }
}