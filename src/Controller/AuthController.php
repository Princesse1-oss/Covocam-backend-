<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api', name: 'api_')]
class AuthController extends AbstractController
{
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        UtilisateurRepository $utilisateurRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['email']) || !isset($data['motDePasse'])) {
            return $this->json(['error' => 'Email et mot de passe requis'], Response::HTTP_BAD_REQUEST);
        }

        $existingUser = $utilisateurRepository->findOneBy(['email' => $data['email']]);
        if ($existingUser) {
            return $this->json(['error' => 'Cet email est deja utilise'], Response::HTTP_CONFLICT);
        }

        $utilisateur = new Utilisateur();
        $utilisateur->setNom($data['nom'] ?? '');
        $utilisateur->setPrenom($data['prenom'] ?? '');
        $utilisateur->setEmail($data['email']);
        $utilisateur->setTelephone($data['telephone'] ?? null);
        
        $typeUtilisateur = $data['typeUtilisateur'] ?? 'passager';
        // ✅ SÉCURITÉ : whitelist stricte — impossible de s'auto-enregistrer en admin
        if (!in_array($typeUtilisateur, ['passager', 'conducteur'], true)) {
            $typeUtilisateur = 'passager';
        }
        $utilisateur->setTypeUtilisateur($typeUtilisateur);
        $utilisateur->setEstActif(true);

        // ✅ CORRECTION : Attribuer les rôles Symfony selon le type d'utilisateur
        $roles = ['ROLE_USER']; // Rôle de base pour tout le monde
        
        if ($typeUtilisateur === 'conducteur') {
            $roles[] = 'ROLE_CONDUCTEUR';
        }
        
        $utilisateur->setRoles($roles);

        $hashedPassword = $passwordHasher->hashPassword($utilisateur, $data['motDePasse']);
        $utilisateur->setMotDePasse($hashedPassword);

        $errors = $validator->validate($utilisateur);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], Response::HTTP_BAD_REQUEST);
        }

        $entityManager->persist($utilisateur);
        $entityManager->flush();

        return $this->json([
            'message' => 'Utilisateur cree avec succes',
            'user' => [
                'id' => $utilisateur->getId(),
                'email' => $utilisateur->getEmail(),
                'nom' => $utilisateur->getNom(),
                'prenom' => $utilisateur->getPrenom(),
                'telephone' => $utilisateur->getTelephone(),  
                'photo' => $utilisateur->getPhoto(),
                'typeUtilisateur' => $utilisateur->getTypeUtilisateur(),
                'roles' => $utilisateur->getRoles(),
            ]
        ], Response::HTTP_CREATED);
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Non authentifié (Token manquant ou invalide)'], Response::HTTP_UNAUTHORIZED);
        }

        // ✅ VÉRIFICATION CRUCIALE : Empêche le crash 500 si Symfony renvoie un objet User générique
        if (!$user instanceof \App\Entity\Utilisateur) {
            return $this->json([
                'error' => 'Erreur système: Type d utilisateur inattendu (' . get_class($user) . ')'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            return $this->json([
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'nom' => $user->getNom(),
                'prenom' => $user->getPrenom(),
                'telephone' => $user->getTelephone(),
                'biographie' => $user->getBiographie(),
                'photo' => $user->getPhoto(),
                'roles' => $user->getRoles(),
                'typeUtilisateur' => $user->getTypeUtilisateur(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Erreur lors de la lecture des données',
                'details' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json(['status' => 'ok']);
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(
        Request $request,
        UtilisateurRepository $utilisateurRepository,
        \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $passwordHasher,
        \Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface $jwtManager
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['email']) || !isset($data['motDePasse'])) {
            return $this->json(['error' => 'Email et mot de passe requis'], Response::HTTP_BAD_REQUEST);
        }

        // 1. Chercher l'utilisateur par email
        $user = $utilisateurRepository->findOneBy(['email' => $data['email']]);

        if (!$user || !$passwordHasher->isPasswordValid($user, $data['motDePasse'])) {
            return $this->json(['error' => 'Identifiants incorrects'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->isEstActif()) {
            return $this->json(['error' => 'Compte désactivé'], Response::HTTP_FORBIDDEN);
        }

        // 2. Générer le token JWT
        $token = $jwtManager->create($user);

        // 3. Renvoyer le token et les infos utilisateur
        return $this->json([
            'message' => 'Connexion réussie',
            'token' => $token,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'nom' => $user->getNom(),
                'prenom' => $user->getPrenom(),
                'telephone' => $user->getTelephone(),
                'photo' => $user->getPhoto(),
                'typeUtilisateur' => $user->getTypeUtilisateur(),
                'roles' => $user->getRoles(),
            ]
        ], Response::HTTP_OK);
    }

}