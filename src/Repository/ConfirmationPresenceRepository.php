<?php

namespace App\Repository;

use App\Entity\ConfirmationPresence;
use App\Entity\Trajet;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ConfirmationPresenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfirmationPresence::class);
    }

    public function findByTrajetEtUtilisateur(Trajet $trajet, Utilisateur $utilisateur): ?ConfirmationPresence
    {
        return $this->findOneBy([
            'trajet' => $trajet,
            'utilisateur' => $utilisateur
        ]);
    }

    public function findAllByTrajet(Trajet $trajet): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.trajet = :trajet')
            ->setParameter('trajet', $trajet)
            ->orderBy('c.timestampConfirmation', 'DESC')
            ->getQuery()
            ->getResult();
    }
}