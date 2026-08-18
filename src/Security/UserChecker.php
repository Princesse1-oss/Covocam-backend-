<?php

namespace App\Security;

use App\Entity\Utilisateur;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof Utilisateur) {
            return;
        }

        $estActif = $user->isEstActif();
        
        // ✅ CORRECTION : On bloque si c'est explicitement false OU si c'est null
        if ($estActif === false || $estActif === null) {
            throw new CustomUserMessageAccountStatusException(
                'Votre compte a été suspendu par l\'administrateur. Veuillez contacter le support CovoCam.'
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // Rien à vérifier après l'authentification
    }
}