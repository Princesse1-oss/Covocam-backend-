<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/villes', name: 'api_villes_')]
class VilleController extends AbstractController
{
    /**
     * Liste des villes et de leurs quartiers.
     * Source : fichier data/villes-quartiers.json (déjà utilisé par /api/villes-quartiers).
     * ✅ CORRIGÉ : l'ancienne version injectait un VilleRepository inexistant (crash 500).
     */
    #[Route('/quartiers', name: 'list_with_quartiers', methods: ['GET'])]
    public function getVillesEtQuartiers(): JsonResponse
    {
        $jsonPath = $this->getParameter('kernel.project_dir') . '/data/villes-quartiers.json';

        if (!file_exists($jsonPath)) {
            return $this->json(['error' => 'Fichier des villes introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(json_decode((string) file_get_contents($jsonPath), true));
    }
}
