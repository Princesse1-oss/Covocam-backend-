<?php

namespace App\Controller;

use App\Entity\Trajet;
use App\Service\TrajetLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fin de course (arrivée du conducteur).
 *
 * Étape 1/2 : EN_COURS → EN_ATTENTE_VALIDATION (présences à valider).
 * Étape 2/2 : POST /conducteur/trajets/{id}/valider-presences → TERMINE,
 *            puis déclenche les paiements Campay et l'email admin
 *            (voir TrajetFinanceService).
 */
#[Route('/api/conducteur/trajets', name: 'api_conducteur_trajets_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class TerminerTrajetController extends AbstractController
{
    public function __construct(private TrajetLifecycleService $lifecycle)
    {
    }

    #[Route('/{id}/terminer', name: 'terminer', methods: ['POST'])]
    public function terminerTrajet(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $trajet = $em->getRepository(Trajet::class)->find($id);

        if (!$trajet || $trajet->getConducteur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }

        if ($trajet->getStatut() === 'TERMINE') {
            return $this->json(['error' => 'Ce trajet est déjà terminé'], 400);
        }

        // Transition atomique : EN_COURS / EN_ATTENTE_DEPART / COMPLET → EN_ATTENTE_VALIDATION
        if (!$this->lifecycle->arriver($trajet)) {
            return $this->json(['error' => 'Ce trajet ne peut pas être clôturé dans son état actuel (' . $trajet->getStatut() . ')'], 400);
        }

        $em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Arrivée enregistrée. Veuillez valider les présences pour finaliser le trajet.',
            'statut' => $trajet->getStatut()
        ]);
    }
}
