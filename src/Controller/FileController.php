<?php

namespace App\Controller;

use App\Entity\FichierUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Annotation\Route;

class FileController extends AbstractController
{
    private const MIME_MAP = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    #[Route('/uploads/profils/{filename}', name: 'serve_profil', methods: ['GET'])]
    public function serveProfil(string $filename, EntityManagerInterface $em): Response
    {
        return $this->serveFile('profils', $filename, $em);
    }

    #[Route('/uploads/vehicules/{filename}', name: 'serve_vehicule', methods: ['GET'])]
    public function serveVehicule(string $filename, EntityManagerInterface $em): Response
    {
        return $this->serveFile('vehicules', $filename, $em);
    }

    #[Route('/uploads/trajets/{filename}', name: 'serve_trajet', methods: ['GET'])]
    public function serveTrajet(string $filename, EntityManagerInterface $em): Response
    {
        return $this->serveFile('trajets', $filename, $em);
    }

    private function serveFile(string $type, string $filename, EntityManagerInterface $em): Response
    {
        $filePath = $this->getParameter('kernel.project_dir') . '/public/uploads/' . $type . '/' . $filename;

        // 1. Repli disque (fichiers présents dans l'image ou partagés)
        if (file_exists($filePath)) {
            $response = new BinaryFileResponse($filePath);
            $mimeType = MimeTypes::guessMimeType($filePath);
            $response->headers->set('Content-Type', $mimeType ?: 'application/octet-stream');
            $response->headers->set('Cache-Control', 'public, max-age=86400');

            return $response;
        }

        // 2. Libre serveur : fichier stocké en base (Neon, persistant entre redéploiements)
        /** @var FichierUpload|null $fichier */
        $fichier = $em->getRepository(FichierUpload::class)
            ->findOneBy(['dossier' => $type, 'nom' => $filename]);

        if (!$fichier) {
            throw $this->createNotFoundException('Fichier introuvable');
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = self::MIME_MAP[$ext] ?? $fichier->getTypeMime() ?? 'application/octet-stream';

        return new Response($fichier->getDonneesString(), 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $fichier->getTaille(),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
