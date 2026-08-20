<?php

namespace App\Controller;

use App\Entity\Vehicule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/api/conducteur/vehicule', name: 'api_conducteur_vehicule_')]
class VehiculeController extends AbstractController
{
    private function getVehiculeUtilisateur($user): ?Vehicule
    {
        if (method_exists($user, 'getVehicule')) {
            return $user->getVehicule();
        }
        if (method_exists($user, 'getVehicules') && $user->getVehicules()->count() > 0) {
            return $user->getVehicules()->first();
        }
        return null;
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function getVehicule(): JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user) return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);

            $vehicule = $this->getVehiculeUtilisateur($user);

            if (!$vehicule) {
                return $this->json(['hasVehicule' => false, 'vehicule' => null]);
            }

            return $this->json([
                'hasVehicule' => true,
                'vehicule' => $this->formatVehicule($vehicule)
            ]);
        } catch (\Throwable $e) {
            error_log("❌ ERREUR GET vehicule: " . $e->getMessage());
            return $this->json(['hasVehicule' => false, 'vehicule' => null]);
        }
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        SluggerInterface $slugger
    ): JsonResponse {
        try {
        return $this->doCreate($request, $em, $validator, $slugger);
        } catch (\Throwable $e) {
            error_log("❌ ERREUR CREATE VEHICULE: " . $e->getMessage() . " | " . $e->getTraceAsString());
            return $this->json(['error' => 'Erreur interne: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function doCreate(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        SluggerInterface $slugger
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            error_log("❌ ERREUR: Utilisateur non authentifié");
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        error_log("🔍 UTILISATEUR CONNECTÉ: ID=" . $user->getId());

        // 1. Vérifier si l'utilisateur a déjà un véhicule
        $existingVehicule = $this->getVehiculeUtilisateur($user);
        if ($existingVehicule !== null) {
            error_log("⚠️ BLOCAGE: L'utilisateur a déjà un véhicule (ID: " . $existingVehicule->getId() . ")");
            return $this->json([
                'error' => 'Vous avez déjà un véhicule enregistré. Veuillez aller dans "Mon Véhicule" pour le modifier ou le supprimer avant d\'en créer un nouveau.'
            ], Response::HTTP_BAD_REQUEST);
        }

        error_log("✅ PAS DE VÉHICULE EXISTANT, ON CONTINUE LA CRÉATION...");

        // 2. GESTION FLEXIBLE DES DONNÉES (FormData plat OU JSON)
        $contentType = $request->getContentTypeFormat();
        error_log("🔍 TYPE DE REQUÊTE : " . $contentType);

        if ($contentType === 'json') {
            $data = json_decode($request->getContent(), true) ?? [];
        } else {
            $dataJson = $request->request->get('data');
            if ($dataJson) {
                $data = json_decode($dataJson, true) ?? [];
            } else {
                // Récupération des champs plats du FormData
                $data = [
                    'marque' => $request->request->get('marque'),
                    'modele' => $request->request->get('modele'),
                    'couleur' => $request->request->get('couleur'),
                    'immatriculation' => $request->request->get('immatriculation') ?: $request->request->get('plaqueImmatriculation'),
                    'nbPlaces' => $request->request->get('nbPlaces'),
                    'annee' => $request->request->get('annee'),
                    'carburant' => $request->request->get('carburant'),
                    'boiteVitesse' => $request->request->get('boiteVitesse'),
                    'climatisation' => $request->request->get('climatisation') === 'true' || $request->request->get('climatisation') === '1',
                    'gps' => $request->request->get('gps') === 'true' || $request->request->get('gps') === '1',
                    'description' => $request->request->get('description'),
                ];
            }
        }

        error_log("🔍 DONNÉES REÇUES : " . print_r($data, true));

        $immat = strtoupper($data['immatriculation'] ?? '');
        error_log("🔍 IMMATRICULATION : '" . $immat . "'");

        if ($immat !== '' && !preg_match('/^[A-Z]{2}-\d{4}-[A-Z]{2}$/', $immat)) {
            error_log("❌ ERREUR FORMAT IMMATRICULATION");
            return $this->json([
                'error' => 'Format immatriculation invalide. Exemple attendu : LT-1234-BA (Reçu : ' . $immat . ')'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Vérification: plaque immatriculation unique
        if ($immat !== '') {
            $existingVehicule = $em->getRepository(Vehicule::class)->findOneBy([
                'plaqueImmatriculation' => $immat
            ]);
            if ($existingVehicule) {
                return $this->json([
                    'error' => 'Cette plaque d\'immatriculation est déjà utilisée par un autre véhicule'
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        $vehicule = new Vehicule();
        $vehicule->setUtilisateur($user);
        $vehicule->setMarque($data['marque'] ?? '');
        $vehicule->setModele($data['modele'] ?? '');
        $vehicule->setCouleur($data['couleur'] ?? '');
        
        if (method_exists($vehicule, 'setPlaqueImmatriculation')) $vehicule->setPlaqueImmatriculation($immat);
        elseif (method_exists($vehicule, 'setImmatriculation')) $vehicule->setImmatriculation($immat);

        if (method_exists($vehicule, 'setPlaces')) $vehicule->setPlaces((int)($data['nbPlaces'] ?? 4));
        elseif (method_exists($vehicule, 'setNbPlaces')) $vehicule->setNbPlaces((int)($data['nbPlaces'] ?? 4));

        if (method_exists($vehicule, 'setAnnee')) $vehicule->setAnnee((int)($data['annee'] ?? date('Y')));
        if (method_exists($vehicule, 'setCarburant')) $vehicule->setCarburant($data['carburant'] ?? null);
        if (method_exists($vehicule, 'setBoiteVitesse')) $vehicule->setBoiteVitesse($data['boiteVitesse'] ?? null);
        if (method_exists($vehicule, 'setClimatisation')) $vehicule->setClimatisation((bool)($data['climatisation'] ?? false));
        if (method_exists($vehicule, 'setGps')) $vehicule->setGps((bool)($data['gps'] ?? false));
        if (method_exists($vehicule, 'setDescription')) $vehicule->setDescription($data['description'] ?? null);
        if (method_exists($vehicule, 'setEstDefaut')) $vehicule->setEstDefaut(true);

        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/vehicules';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $photo = $request->files->get('photoAvant') ?: $request->files->get('photo');
        if ($photo) {
            $path = $this->uploadPhoto($photo, 'principal', $user->getId(), $uploadDir, $slugger);
            if (method_exists($vehicule, 'setPhotoAvant')) $vehicule->setPhotoAvant($path);
            else $vehicule->setPhoto($path);
        }

        $photoArriere = $request->files->get('photoArriere');
        if ($photoArriere && method_exists($vehicule, 'setPhotoArriere')) {
            $vehicule->setPhotoArriere($this->uploadPhoto($photoArriere, 'arriere', $user->getId(), $uploadDir, $slugger));
        }

        $errors = $validator->validate($vehicule);
        if (count($errors) > 0) {
            $msgs = [];
            foreach ($errors as $error) $msgs[] = $error->getMessage();
            error_log("❌ ERREUR VALIDATION DOCTRINE : " . implode(', ', $msgs));
            return $this->json(['errors' => $msgs], Response::HTTP_BAD_REQUEST);
        }

        $em->persist($vehicule);
        $em->flush();

        error_log("✅ VÉHICULE CRÉÉ AVEC SUCCÈS !");
        return $this->json(['message' => 'Véhicule ajouté avec succès', 'vehicule' => $this->formatVehicule($vehicule)], Response::HTTP_CREATED);
    }

    #[Route('', name: 'update', methods: ['PUT'])]
    public function update(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);

        $vehicule = $this->getVehiculeUtilisateur($user);
        if (!$vehicule) return $this->json(['error' => 'Aucun véhicule à modifier'], Response::HTTP_NOT_FOUND);

        $dataJson = $request->request->get('data');
        $data = $dataJson ? json_decode($dataJson, true) : [];

        if (isset($data['immatriculation'])) {
            $immat = strtoupper($data['immatriculation']);
            if (!preg_match('/^[A-Z]{2}-\d{4}-[A-Z]{2}$/', $immat)) {
                return $this->json(['error' => 'Format immatriculation invalide'], Response::HTTP_BAD_REQUEST);
            }
            if (method_exists($vehicule, 'setPlaqueImmatriculation')) $vehicule->setPlaqueImmatriculation($immat);
            elseif (method_exists($vehicule, 'setImmatriculation')) $vehicule->setImmatriculation($immat);
        }

        if (isset($data['marque'])) $vehicule->setMarque($data['marque']);
        if (isset($data['modele'])) $vehicule->setModele($data['modele']);
        if (isset($data['couleur'])) $vehicule->setCouleur($data['couleur']);
        if (isset($data['nbPlaces']) && method_exists($vehicule, 'setPlaces')) $vehicule->setPlaces((int)$data['nbPlaces']);
        if (isset($data['nbPlaces']) && method_exists($vehicule, 'setNbPlaces')) $vehicule->setNbPlaces((int)$data['nbPlaces']);
        
        if (method_exists($vehicule, 'setAnnee') && isset($data['annee'])) $vehicule->setAnnee((int)$data['annee']);
        if (method_exists($vehicule, 'setCarburant') && isset($data['carburant'])) $vehicule->setCarburant($data['carburant']);
        if (method_exists($vehicule, 'setBoiteVitesse') && isset($data['boiteVitesse'])) $vehicule->setBoiteVitesse($data['boiteVitesse']);
        if (method_exists($vehicule, 'setClimatisation') && isset($data['climatisation'])) $vehicule->setClimatisation((bool)$data['climatisation']);
        if (method_exists($vehicule, 'setGps') && isset($data['gps'])) $vehicule->setGps((bool)$data['gps']);
        if (method_exists($vehicule, 'setDescription') && array_key_exists('description', $data)) $vehicule->setDescription($data['description']);

        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/vehicules';

        $photo = $request->files->get('photoAvant') ?: $request->files->get('photo');
        if ($photo) {
            $path = $this->uploadPhoto($photo, 'principal', $user->getId(), $uploadDir, $slugger);
            if (method_exists($vehicule, 'setPhotoAvant')) $vehicule->setPhotoAvant($path);
            else $vehicule->setPhoto($path);
        }

        $photoArriere = $request->files->get('photoArriere');
        if ($photoArriere && method_exists($vehicule, 'setPhotoArriere')) {
            $vehicule->setPhotoArriere($this->uploadPhoto($photoArriere, 'arriere', $user->getId(), $uploadDir, $slugger));
        }

        $em->flush();

        return $this->json(['message' => 'Véhicule mis à jour avec succès', 'vehicule' => $this->formatVehicule($vehicule)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);

        $vehicule = $em->getRepository(Vehicule::class)->find($id);
        if (!$vehicule || $vehicule->getUtilisateur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Ce véhicule ne vous appartient pas'], Response::HTTP_FORBIDDEN);
        }

        if (method_exists($vehicule, 'getTrajets') && $vehicule->getTrajets()->count() > 0) {
            return $this->json(['error' => 'Ce véhicule est utilisé dans des trajets'], Response::HTTP_BAD_REQUEST);
        }

        $em->remove($vehicule);
        $em->flush();

        return $this->json(['message' => 'Véhicule supprimé avec succès']);
    }

    private function formatVehicule(Vehicule $v): array
    {
        $data = [
            'id' => $v->getId(),
            'marque' => $v->getMarque(),
            'modele' => $v->getModele(),
            'couleur' => $v->getCouleur(),
            'estDefaut' => method_exists($v, 'isEstDefaut') ? $v->isEstDefaut() : true
        ];

        if (method_exists($v, 'getPlaqueImmatriculation')) { $data['plaqueImmatriculation'] = $v->getPlaqueImmatriculation(); $data['immatriculation'] = $v->getPlaqueImmatriculation(); }
        elseif (method_exists($v, 'getImmatriculation')) { $data['immatriculation'] = $v->getImmatriculation(); }
        
        if (method_exists($v, 'getPlaces')) { $data['places'] = $v->getPlaces(); $data['nbPlaces'] = $v->getPlaces(); }
        elseif (method_exists($v, 'getNbPlaces')) { $data['nbPlaces'] = $v->getNbPlaces(); }

        if (method_exists($v, 'getPhotoAvant')) $data['photo'] = $v->getPhotoAvant();
        if (method_exists($v, 'getPhotoAvant')) $data['photoAvant'] = $v->getPhotoAvant();
        if (method_exists($v, 'getPhotoArriere')) $data['photoArriere'] = $v->getPhotoArriere();
        if (method_exists($v, 'getAnnee')) $data['annee'] = $v->getAnnee();
        if (method_exists($v, 'getCarburant')) $data['carburant'] = $v->getCarburant();
        if (method_exists($v, 'getBoiteVitesse')) $data['boiteVitesse'] = $v->getBoiteVitesse();
        if (method_exists($v, 'isClimatisation')) $data['climatisation'] = $v->isClimatisation();
        if (method_exists($v, 'isGps')) $data['gps'] = $v->isGps();
        if (method_exists($v, 'getDescription')) $data['description'] = $v->getDescription();

        return $data;
    }

    private function uploadPhoto(UploadedFile $file, string $type, int $userId, string $uploadDir, SluggerInterface $slugger): string
    {
        $safeName = $slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))->lower();
        $newName = $safeName . '-' . $userId . '-' . $type . '-' . uniqid() . '.' . $file->guessExtension();
        $file->move($uploadDir, $newName);
        return '/uploads/vehicules/' . $newName;
    }
      
       // Ajoute cette route pour récupérer LA LISTE de tous les véhicules du conducteur
    #[Route('s', name: 'list', methods: ['GET'])] // Note le 's' à la fin : /api/conducteur/vehicules
    public function getList(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        // ✅ LOG 1 : On voit QUI est connecté
        error_log("🔍 DEBUG LISTE VÉHICULES | User ID: " . $user->getId() . " | Email: " . $user->getEmail());

        $vehicules = [];
        if (method_exists($user, 'getVehicules')) {
            foreach ($user->getVehicules() as $v) {
                $vehicules[] = $this->formatVehicule($v);
            }
        } elseif (method_exists($user, 'getVehicule') && $user->getVehicule() !== null) {
            // Fallback si l'utilisateur n'a qu'un seul véhicule (OneToOne)
            $vehicules[] = $this->formatVehicule($user->getVehicule());
        }

        // ✅ LOG 2 : On voit COMBIEN de véhicules Symfony trouve pour cet utilisateur
        error_log("🔍 NOMBRE DE VÉHICULES TROUVÉS POUR CET UTILISATEUR : " . count($vehicules));

        return $this->json($vehicules);
    }
}