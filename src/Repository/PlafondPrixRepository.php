<?php

namespace App\Repository;

use App\Entity\PlafondPrix;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlafondPrix>
 */
class PlafondPrixRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlafondPrix::class);
    }

    /**
     * Plafond pour une paire de villes (insensible à la casse et à l'ordre).
     */
    public function trouverPlafond(string $villeDepart, string $villeArrivee): ?PlafondPrix
    {
        return $this->createQueryBuilder('p')
            ->andWhere('LOWER(p.villeDepart) = LOWER(:depart)')
            ->andWhere('LOWER(p.villeArrivee) = LOWER(:arrivee)')
            ->setParameter('depart', trim($villeDepart))
            ->setParameter('arrivee', trim($villeArrivee))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() ?? $this->createQueryBuilder('p')
            ->andWhere('LOWER(p.villeDepart) = LOWER(:arrivee)')
            ->andWhere('LOWER(p.villeArrivee) = LOWER(:depart)')
            ->setParameter('depart', trim($villeDepart))
            ->setParameter('arrivee', trim($villeArrivee))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
