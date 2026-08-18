<?php

namespace App\Repository;

use App\Entity\Lieu;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Lieu>
 */
class LieuRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Lieu::class);
    }

    /**
     * Récupère les lieux par type
     */
    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.type = :type')
            ->andWhere('l.estActif = :estActif')
            ->setParameter('type', $type)
            ->setParameter('estActif', true)
            ->orderBy('l.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère toutes les villes
     */
    public function findVilles(): array
    {
        return $this->findByType('ville');
    }

    /**
     * Récupère tous les quartiers
     */
    public function findQuartiers(): array
    {
        return $this->findByType('quartier');
    }

    /**
     * Récupère les points de rendez-vous
     */
    public function findPointsRendezVous(): array
    {
        return $this->findByType('point_rendezvous');
    }

    /**
     * Récupère les lieux par région
     */
    public function findByRegion(string $region): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.region = :region')
            ->andWhere('l.estActif = :estActif')
            ->setParameter('region', $region)
            ->setParameter('estActif', true)
            ->orderBy('l.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les quartiers d'une ville (par parent)
     */
    public function findQuartiersByVille(int $villeId): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.lieuParent = :villeId')
            ->andWhere('l.type = :type')
            ->andWhere('l.estActif = :estActif')
            ->setParameter('villeId', $villeId)
            ->setParameter('type', 'quartier')
            ->setParameter('estActif', true)
            ->orderBy('l.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche des lieux par nom
     */
    public function searchByName(string $search): array
    {
        return $this->createQueryBuilder('l')
            ->where('LOWER(l.nom) LIKE LOWER(:search)')
            ->andWhere('l.estActif = :estActif')
            ->setParameter('search', '%' . $search . '%')
            ->setParameter('estActif', true)
            ->orderBy('l.nom', 'ASC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les lieux principaux
     */
    public function findPrincipaux(): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.estPrincipal = :estPrincipal')
            ->andWhere('l.estActif = :estActif')
            ->setParameter('estPrincipal', true)
            ->setParameter('estActif', true)
            ->orderBy('l.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les lieux par pays
     */
    public function findByPays(string $pays): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.pays = :pays')
            ->andWhere('l.estActif = :estActif')
            ->setParameter('pays', $pays)
            ->setParameter('estActif', true)
            ->orderBy('l.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les lieux avec coordonnées GPS
     */
    public function findAvecCoordonnees(): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.latitude IS NOT NULL')
            ->andWhere('l.longitude IS NOT NULL')
            ->andWhere('l.estActif = :estActif')
            ->setParameter('estActif', true)
            ->orderBy('l.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}