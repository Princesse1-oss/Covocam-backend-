<?php

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\Trajet;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    // ✅ Version simplifiée SANS la jointure "paiement" qui cause le plantage
    public function findByPassagerAvecPaiement(int $passagerId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.passager = :passagerId')
            ->setParameter('passagerId', $passagerId)
            ->orderBy('r.dateReservation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByConducteurAvecDetails(int $conducteurId): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.trajet', 't')
            ->addSelect('t')
            ->join('r.passager', 'u')
            ->addSelect('u')
            ->where('t.conducteur = :conducteurId')
            ->setParameter('conducteurId', $conducteurId)
            ->orderBy('r.dateReservation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findReservationsConfirmeesParDate(\DateTimeInterface $dateDebut, \DateTimeInterface $dateFin): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.passager', 'u')
            ->addSelect('u')
            ->where('r.statut = :statut')
            ->andWhere('r.dateReservation >= :dateDebut')
            ->andWhere('r.dateReservation <= :dateFin')
            ->setParameter('statut', 'CONFIRMEE')
            ->setParameter('dateDebut', $dateDebut)
            ->setParameter('dateFin', $dateFin)
            ->getQuery()
            ->getResult();
    }

    public function findActiveByTrajetEtPassager(Trajet $trajet, Utilisateur $passager): ?Reservation
    {
        return $this->createQueryBuilder('r')
            ->where('r.trajet = :trajet')
            ->andWhere('r.passager = :passager')
            ->andWhere('r.statut IN (:statuts)')
            ->setParameter('trajet', $trajet)
            ->setParameter('passager', $passager)
            ->setParameter('statuts', ['CONFIRMEE', 'A_PAYER', 'TERMINEE'])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActivesByTrajet(Trajet $trajet): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.trajet = :trajet')
            ->andWhere('r.statut IN (:statuts)')
            ->setParameter('trajet', $trajet)
            ->setParameter('statuts', ['EN_ATTENTE', 'A_PAYER', 'CONFIRMEE'])
            ->getQuery()
            ->getResult();
    }
}