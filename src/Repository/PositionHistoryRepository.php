<?php

namespace App\Repository;

use App\Entity\PositionHistory;
use App\Entity\Trajet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PositionHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PositionHistory::class);
    }

    public function findDernierePositionParTrajet(Trajet $trajet): ?PositionHistory
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.trajet = :trajet')
            ->setParameter('trajet', $trajet)
            ->orderBy('p.timestamp', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findHistoriqueParTrajet(Trajet $trajet, int $limit = 100): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.trajet = :trajet')
            ->setParameter('trajet', $trajet)
            ->orderBy('p.timestamp', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}