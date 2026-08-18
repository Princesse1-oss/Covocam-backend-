<?php

namespace App\Controller;

use App\Service\PrixService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/trajets', name: 'api_trajets_calc_')]
class CalculPrixController extends AbstractController
{
    public function __construct(private PrixService $prixService)
    {
    }

    #[Route('/calculer-prix', name: 'calculer_prix', methods: ['POST'])]
    public function calculerPrix(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $villeDepart = $data['villeDepart'] ?? '';
        $villeArrivee = $data['villeArrivee'] ?? '';
        $nbPlaces = max(1, (int) ($data['nbPlaces'] ?? 1));

        if ($villeDepart === '' || $villeArrivee === '') {
            return $this->json(['error' => 'La ville de départ et la ville d\'arrivée sont obligatoires'], Response::HTTP_BAD_REQUEST);
        }

        $resultat = $this->prixService->calculer(
            $villeDepart,
            $villeArrivee,
            $nbPlaces,
            isset($data['latDepart']) ? (float) $data['latDepart'] : null,
            isset($data['lngDepart']) ? (float) $data['lngDepart'] : null,
            isset($data['latArrivee']) ? (float) $data['latArrivee'] : null,
            isset($data['lngArrivee']) ? (float) $data['lngArrivee'] : null
        );

        return $this->json([
            'distance' => $resultat['distance'],
            'coutTotal' => $resultat['coutTotal'],
            'prixConseille' => $resultat['prixConseille'],
            'prixMax' => $resultat['prixMax'],
            'plafondApplique' => $resultat['plafondApplique'],
        ]);
    }
}
