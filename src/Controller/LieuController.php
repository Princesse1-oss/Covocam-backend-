<?php

namespace App\Controller;

use App\Entity\Lieu;
use App\Repository\LieuRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/lieux', name: 'api_lieux_')]
class LieuController extends AbstractController
{
    // 1. Liste des lieux
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(LieuRepository $lieuRepository): JsonResponse
    {
        $lieux = $lieuRepository->findBy(['estActif' => true], ['nom' => 'ASC']);
        $result = [];
        foreach ($lieux as $lieu) {
            $result[] = $this->formatLieu($lieu);
        }
        return $this->json($result);
    }

    // 2. Liste des villes
    #[Route('/villes', name: 'villes', methods: ['GET'])]
    public function getVilles(LieuRepository $lieuRepository): JsonResponse
    {
        $villes = $lieuRepository->findVilles();
        $result = [];
        foreach ($villes as $ville) {
            $result[] = $this->formatLieu($ville);
        }
        return $this->json($result);
    }

    // 3. Liste des quartiers
    #[Route('/quartiers', name: 'quartiers', methods: ['GET'])]
    public function getQuartiers(LieuRepository $lieuRepository): JsonResponse
    {
        $quartiers = $lieuRepository->findQuartiers();
        $result = [];
        foreach ($quartiers as $quartier) {
            $result[] = $this->formatLieu($quartier);
        }
        return $this->json($result);
    }

    // 4. Quartiers d'une ville
    #[Route('/villes/{id}/quartiers', name: 'quartiers_ville', methods: ['GET'])]
    public function getQuartiersByVille(int $id, LieuRepository $lieuRepository): JsonResponse
    {
        $quartiers = $lieuRepository->findQuartiersByVille($id);
        $result = [];
        foreach ($quartiers as $quartier) {
            $result[] = $this->formatLieu($quartier);
        }
        return $this->json($result);
    }

    // 5. Recherche de lieux
    #[Route('/recherche', name: 'search', methods: ['GET'])]
    public function search(Request $request, LieuRepository $lieuRepository): JsonResponse
    {
        $term = $request->query->get('q', '');
        if (strlen($term) < 2) {
            return $this->json([]);
        }
        $lieux = $lieuRepository->searchByName($term);
        $result = [];
        foreach ($lieux as $lieu) {
            $result[] = $this->formatLieu($lieu);
        }
        return $this->json($result);
    }

    // 6. Détails d'un lieu
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Lieu $lieu): JsonResponse
    {
        return $this->json($this->formatLieu($lieu));
    }

    // 7. Créer un lieu (Admin)
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        LieuRepository $lieuRepository
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        $lieu = new Lieu();
        $lieu->setNom($data['nom']);
        $lieu->setType($data['type']);
        $lieu->setAdresse($data['adresse'] ?? null);
        $lieu->setLatitude($data['latitude'] ?? null);
        $lieu->setLongitude($data['longitude'] ?? null);
        $lieu->setCodePostal($data['codePostal'] ?? null);
        $lieu->setRegion($data['region'] ?? null);
        $lieu->setDepartement($data['departement'] ?? null);
        $lieu->setPays($data['pays'] ?? 'Cameroun');
        $lieu->setDescription($data['description'] ?? null);
        $lieu->setEstActif($data['estActif'] ?? true);
        $lieu->setEstPrincipal($data['estPrincipal'] ?? false);

        if (isset($data['lieuParentId']) && $data['lieuParentId']) {
            $parent = $lieuRepository->find($data['lieuParentId']);
            if ($parent) {
                $lieu->setLieuParent($parent);
            }
        }

        $errors = $validator->validate($lieu);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], Response::HTTP_BAD_REQUEST);
        }

        $entityManager->persist($lieu);
        $entityManager->flush();

        return $this->json([
            'message' => 'Lieu créé avec succès',
            'lieu' => $this->formatLieu($lieu)
        ], Response::HTTP_CREATED);
    }

    // 8. Modifier un lieu (Admin)
    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(
        Lieu $lieu,
        Request $request,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        LieuRepository $lieuRepository
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['nom'])) $lieu->setNom($data['nom']);
        if (isset($data['type'])) $lieu->setType($data['type']);
        if (isset($data['adresse'])) $lieu->setAdresse($data['adresse']);
        if (isset($data['latitude'])) $lieu->setLatitude($data['latitude']);
        if (isset($data['longitude'])) $lieu->setLongitude($data['longitude']);
        if (isset($data['codePostal'])) $lieu->setCodePostal($data['codePostal']);
        if (isset($data['region'])) $lieu->setRegion($data['region']);
        if (isset($data['departement'])) $lieu->setDepartement($data['departement']);
        if (isset($data['pays'])) $lieu->setPays($data['pays']);
        if (isset($data['description'])) $lieu->setDescription($data['description']);
        if (isset($data['estActif'])) $lieu->setEstActif($data['estActif']);
        if (isset($data['estPrincipal'])) $lieu->setEstPrincipal($data['estPrincipal']);

        if (isset($data['lieuParentId'])) {
            if ($data['lieuParentId']) {
                $parent = $lieuRepository->find($data['lieuParentId']);
                if ($parent) {
                    $lieu->setLieuParent($parent);
                }
            } else {
                $lieu->setLieuParent(null);
            }
        }

        $lieu->setDateModification(new \DateTimeImmutable());

        $errors = $validator->validate($lieu);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], Response::HTTP_BAD_REQUEST);
        }

        $entityManager->flush();

        return $this->json([
            'message' => 'Lieu modifié avec succès',
            'lieu' => $this->formatLieu($lieu)
        ]);
    }

    // 9. Supprimer un lieu (Admin)
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(
        Lieu $lieu,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['error' => 'Accès non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $lieu->setEstActif(false);
        $lieu->setDateModification(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->json(['message' => 'Lieu désactivé avec succès']);
    }

    private function formatLieu(Lieu $lieu): array
    {
        return [
            'id' => $lieu->getId(),
            'nom' => $lieu->getNom(),
            'type' => $lieu->getType(),
            'adresse' => $lieu->getAdresse(),
            'latitude' => $lieu->getLatitude(),
            'longitude' => $lieu->getLongitude(),
            'codePostal' => $lieu->getCodePostal(),
            'region' => $lieu->getRegion(),
            'departement' => $lieu->getDepartement(),
            'pays' => $lieu->getPays(),
            'description' => $lieu->getDescription(),
            'estActif' => $lieu->isEstActif(),
            'estPrincipal' => $lieu->isEstPrincipal(),
            'lieuParent' => $lieu->getLieuParent() ? $this->formatLieuMini($lieu->getLieuParent()) : null,
            'dateCreation' => $lieu->getDateCreation()?->format('Y-m-d H:i:s')
        ];
    }

    private function formatLieuMini(Lieu $lieu): array
    {
        return [
            'id' => $lieu->getId(),
            'nom' => $lieu->getNom(),
            'type' => $lieu->getType()
        ];
    }
}