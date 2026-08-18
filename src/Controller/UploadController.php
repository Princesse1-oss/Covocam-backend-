<?php

namespace App\Controller;

use App\Entity\Vehicule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/api/upload', name: 'api_upload_')]
class UploadController extends AbstractController
{
    private const MAX_FILE_SIZE = 5242880; // 5MB
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    // 1. Upload photo de profil
        #[Route('/profil', name: 'profil', methods: ['POST'])]
    public function uploadProfil(
        Request $request,
        SluggerInterface $slugger,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $request->files->get('photo');
        if (!$file) {
            return $this->json(['error' => 'Aucun fichier envoyé'], Response::HTTP_BAD_REQUEST);
        }

        // ✅ 1. Validation stricte : on force l'extension originale, pas celle devinée
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $slugger->slug($originalFilename);
        
        // On prend l'extension du client, en minuscule
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        
        if (!in_array($extension, $allowedExtensions)) {
            return $this->json(['error' => 'Format de fichier non autorisé. Utilisez JPG, PNG ou WEBP.'], Response::HTTP_BAD_REQUEST);
        }

        $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/profils';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        try {
            // ✅ 2. Déplacement du fichier
            $file->move($uploadDir, $newFilename);
            
            // ✅ 3. Suppression de l'ancienne photo si elle existe
            if ($user->getPhoto()) {
                $oldFilePath = $uploadDir . '/' . $user->getPhoto();
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }
            }

            // ✅ 4. Mise à jour en base de données
            $user->setPhoto($newFilename);
            $entityManager->flush();

            return $this->json([
                'message' => 'Photo de profil mise à jour avec succès',
                'filename' => $newFilename,
                'url' => '/uploads/profils/' . $newFilename
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Erreur interne lors de l\'upload : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // 2. Upload photo de véhicule
    #[Route('/vehicule/{id}', name: 'vehicule', methods: ['POST'])]
    public function uploadVehicule(
        Vehicule $vehicule,
        Request $request,
        SluggerInterface $slugger,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        if ($vehicule->getUtilisateur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Vous n\'êtes pas autorisé'], Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('photo');
        if (!$file) {
            return $this->json(['error' => 'Aucun fichier envoyé'], Response::HTTP_BAD_REQUEST);
        }

        $validationError = $this->validateFile($file);
        if ($validationError) {
            return $this->json(['error' => $validationError], Response::HTTP_BAD_REQUEST);
        }

        try {
            $filename = $this->uploadFile($file, 'vehicules', $slugger);
            
            if ($vehicule->getPhotoAvant()) {
                $this->deleteFile($vehicule->getPhotoAvant());
            }

            $vehicule->setPhotoAvant($filename);
            $entityManager->flush();

            return $this->json([
                'message' => 'Photo du véhicule mise à jour avec succès',
                'filename' => $filename,
                'url' => '/uploads/vehicules/' . $filename
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Erreur lors du téléchargement du fichier'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // 3. Upload photo de trajet
    #[Route('/trajet', name: 'trajet', methods: ['POST'])]
    public function uploadTrajet(
        Request $request,
        SluggerInterface $slugger
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $request->files->get('photo');
        if (!$file) {
            return $this->json(['error' => 'Aucun fichier envoyé'], Response::HTTP_BAD_REQUEST);
        }

        $validationError = $this->validateFile($file);
        if ($validationError) {
            return $this->json(['error' => $validationError], Response::HTTP_BAD_REQUEST);
        }

        try {
            $filename = $this->uploadFile($file, 'trajets', $slugger);

            return $this->json([
                'message' => 'Photo du trajet téléchargée avec succès',
                'filename' => $filename,
                'url' => '/uploads/trajets/' . $filename
            ]);
        } catch (FileException $e) {
            return $this->json(['error' => 'Erreur lors du téléchargement du fichier'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // 4. Supprimer une photo (CORRIGÉ : EntityManagerInterface injecté)
    #[Route('/supprimer', name: 'delete', methods: ['DELETE'])]
    public function deletePhoto(
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $filename = $data['filename'] ?? null;

        if (!$filename) {
            return $this->json(['error' => 'Nom du fichier requis'], Response::HTTP_BAD_REQUEST);
        }

        if ($user->getPhoto() === $filename) {
            $this->deleteFile($filename);
            $user->setPhoto(null);
            
            $entityManager->flush();

            return $this->json(['message' => 'Photo supprimée avec succès']);
        }

        return $this->json(['error' => 'Vous n\'êtes pas autorisé à supprimer ce fichier'], Response::HTTP_FORBIDDEN);
    }

    // --- MÉTHODES PRIVÉES (Elles doivent être TOUT EN BAS du fichier) ---

    private function validateFile(UploadedFile $file): ?string
    {
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return 'Le fichier est trop volumineux (maximum 5MB)';
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            return 'Extension de fichier non autorisée. Types autorisés: ' . implode(', ', self::ALLOWED_EXTENSIONS);
        }

        return null;
    }

       private function uploadFile(UploadedFile $file, string $subdirectory, SluggerInterface $slugger): string
    {
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $slugger->slug($originalFilename);
        $newFilename = $safeFilename . '-' . uniqid() . '.' . $file->getClientOriginalExtension();

        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/' . $subdirectory;
        
        // ✅ Créer le dossier s'il n'existe pas
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $file->move($uploadDir, $newFilename);

        return $newFilename;
    }

    private function deleteFile(string $filename): void
    {
        $paths = ['profils', 'vehicules', 'trajets'];
        foreach ($paths as $path) {
            $filePath = $this->getParameter('kernel.project_dir') . '/public/uploads/' . $path . '/' . $filename;
            if (file_exists($filePath)) {
                unlink($filePath);
                return;
            }
        }
    }

    
}