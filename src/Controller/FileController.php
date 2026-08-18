<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Annotation\Route;

class FileController extends AbstractController
{
    #[Route('/uploads/profils/{filename}', name: 'serve_profil', methods: ['GET'])]
    public function serveProfil(string $filename): BinaryFileResponse
    {
        return $this->serveFile('profils', $filename);
    }

    #[Route('/uploads/vehicules/{filename}', name: 'serve_vehicule', methods: ['GET'])]
    public function serveVehicule(string $filename): BinaryFileResponse
    {
        return $this->serveFile('vehicules', $filename);
    }

    #[Route('/uploads/trajets/{filename}', name: 'serve_trajet', methods: ['GET'])]
    public function serveTrajet(string $filename): BinaryFileResponse
    {
        return $this->serveFile('trajets', $filename);
    }

    private function serveFile(string $type, string $filename): BinaryFileResponse
    {
        $filePath = $this->getParameter('kernel.project_dir') . '/public/uploads/' . $type . '/' . $filename;

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('Fichier introuvable');
        }

        $response = new BinaryFileResponse($filePath);
        $mimeType = MimeTypes::guessMimeType($filePath);
        $response->headers->set('Content-Type', $mimeType ?: 'application/octet-stream');
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }
}
