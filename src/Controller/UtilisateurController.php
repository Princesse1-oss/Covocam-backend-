<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/utilisateurs', name: 'api_utilisateurs_')]
class UtilisateurController extends AbstractController
{
    // 1. Mettre à jour mon profil (Version complète et robuste)
    #[Route('/profil', name: 'update', methods: ['PUT'])]
    public function updateProfile(
        Request $request,
        EntityManagerInterface $entityManager,
        \Symfony\Component\Validator\Validator\ValidatorInterface $validator // ✅ Ajouté
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $data = $request->toArray();
        } catch (\JsonException $e) {
            return $this->json(['error' => 'Format JSON invalide dans la requête'], Response::HTTP_BAD_REQUEST);
        }

        // Mise à jour des données
        if (isset($data['nom'])) $user->setNom($data['nom']);
        if (isset($data['prenom'])) $user->setPrenom($data['prenom']);
        if (isset($data['telephone'])) $user->setTelephone($data['telephone']);
        if (isset($data['typeUtilisateur'])) $user->setTypeUtilisateur($data['typeUtilisateur']);
        if (isset($data['photo'])) $user->setPhoto($data['photo']);
        if (isset($data['biographie'])) $user->setBiographie($data['biographie']);
        if (isset($data['preferencesVoyage'])) $user->setPreferencesVoyage($data['preferencesVoyage']);

        $user->setDateModification(new \DateTimeImmutable());

        // ✅ Validation des contraintes (dont UniqueEntity)
        $errors = $validator->validate($user);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], Response::HTTP_BAD_REQUEST);
        }

        $entityManager->flush();

        return $this->json([
            'message' => 'Profil mis à jour avec succès',
            'user' => [
                'id' => $user->getId(),
                'nom' => $user->getNom(),
                'prenom' => $user->getPrenom(),
                'email' => $user->getEmail(),
                'telephone' => $user->getTelephone(),
                'typeUtilisateur' => $user->getTypeUtilisateur(),
                'photo' => $user->getPhoto(),
                'biographie' => $user->getBiographie(),
                'preferencesVoyage' => $user->getPreferencesVoyage()
            ]
        ]);
    }

    // 2. Récupérer un utilisateur par email
    #[Route('/email', name: 'by_email', methods: ['GET'])]
    public function findByEmail(
        Request $request,
        UtilisateurRepository $utilisateurRepository
    ): JsonResponse {
        $email = $request->query->get('email');
        
        if (!$email) {
            return $this->json(['error' => 'Email requis'], Response::HTTP_BAD_REQUEST);
        }

        $utilisateur = $utilisateurRepository->findOneBy(['email' => $email]);
        
        if (!$utilisateur) {
            return $this->json(['error' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id' => $utilisateur->getId(),
            'nom' => $utilisateur->getNom(),
            'prenom' => $utilisateur->getPrenom(),
            'email' => $utilisateur->getEmail(),
            'telephone' => $utilisateur->getTelephone(),
            'typeUtilisateur' => $utilisateur->getTypeUtilisateur(),
            'photo' => $utilisateur->getPhoto(),
            'biographie' => $utilisateur->getBiographie(),
            'noteMoyenne' => $utilisateur->getNoteMoyenne(),
            'roles' => $utilisateur->getRoles(),
            'estActif' => $utilisateur->isEstActif(),
            'dateCreation' => $utilisateur->getDateCreation()?->format('Y-m-d H:i:s')
        ]);
    }

    // 3. Route pour que le frontend sache "Qui suis-je ?"
    #[Route('/me', name: 'me', methods: ['GET'])]
    public function getMe(): JsonResponse {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'nom' => $user->getNom(),
            'prenom' => $user->getPrenom(),
            'telephone' => $user->getTelephone(),
            'typeUtilisateur' => $user->getTypeUtilisateur(),
            'photo' => $user->getPhoto(), 
            'biographie' => $user->getBiographie(),
            'noteMoyenne' => $user->getNoteMoyenne(),
            'roles' => $user->getRoles(), 
        ]);
    }
    
    // 4. Consulter un profil utilisateur
    #[Route('/{id}', name: 'show', methods: ['GET'],  requirements: ['id' => '\d+'])]
    public function show(
        int $id,
        UtilisateurRepository $utilisateurRepository
    ): JsonResponse {
        $utilisateur = $utilisateurRepository->find($id);
        if (!$utilisateur) {
            return $this->json(['error' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id' => $utilisateur->getId(),
            'nom' => $utilisateur->getNom(),
            'prenom' => $utilisateur->getPrenom(),
            'email' => $utilisateur->getEmail(),
            'telephone' => $utilisateur->getTelephone(),
            'typeUtilisateur' => $utilisateur->getTypeUtilisateur(),
            'photo' => $utilisateur->getPhoto(),
            'biographie' => $utilisateur->getBiographie(),
            'noteMoyenne' => $utilisateur->getNoteMoyenne(),
            'dateCreation' => $utilisateur->getDateCreation()?->format('Y-m-d H:i:s')
        ]);
    }

    // 5. Compter les conducteurs actifs (Pour le dashboard passager)
    #[Route('/conducteurs/actifs/count', name: 'conducteurs_actifs_count', methods: ['GET'])]
    public function getActiveConducteursCount(UtilisateurRepository $utilisateurRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $qb = $utilisateurRepository->createQueryBuilder('u');
        $count = $qb->select('COUNT(u.id)')
            ->where('u.typeUtilisateur IN (:types)')
            ->andWhere('u.estActif = :actif')
            ->setParameter('types', ['conducteur', 'les_deux'])
            ->setParameter('actif', true)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->json(['count' => (int)$count]);
    }
}