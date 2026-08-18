<?php

namespace App\Repository;

use App\Entity\Paiement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Paiement>
 */
class PaiementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Paiement::class);
    }

    /**
     * Récupère les paiements d'un utilisateur (via sa réservation)
     */
    public function findByUtilisateur(int $utilisateurId): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.reservation', 'r')
            ->where('r.passager = :utilisateurId')
            ->setParameter('utilisateurId', $utilisateurId)
            ->orderBy('p.datePaiement', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les paiements par statut
     */
    public function findByStatut(string $statut): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.statut = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('p.datePaiement', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les paiements d'une réservation
     */
    public function findByReservation(int $reservationId): ?Paiement
    {
        return $this->createQueryBuilder('p')
            ->where('p.reservation = :reservationId')
            ->setParameter('reservationId', $reservationId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Récupère les paiements par devise
     */
    public function findByMoyenPaiement(string $devise): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.devise = :devise')
            ->setParameter('devise', $devise)
            ->orderBy('p.datePaiement', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les paiements confirmés
     */
    public function findConfirmes(): array
    {
        return $this->findByStatut('REUSSI');
    }

    /**
     * Récupère les paiements en attente
     */
    public function findEnAttente(): array
    {
        return $this->findByStatut('EN_ATTENTE');
    }

    /**
     * Récupère les paiements échoués
     */
    public function findEchoues(): array
    {
        return $this->findByStatut('ECHEC');
    }

    /**
     * Récupère les paiements remboursés
     */
    public function findRembourses(): array
    {
        return $this->findByStatut('REMBOURSE');
    }

    /**
     * Récupère les paiements par période
     */
    public function findByPeriode(\DateTimeInterface $debut, \DateTimeInterface $fin): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.datePaiement BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->orderBy('p.datePaiement', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calcule le total des paiements par statut
     */
    public function getTotalByStatut(string $statut): float
    {
        $result = $this->createQueryBuilder('p')
            ->select('SUM(p.montantTotal)')
            ->where('p.statut = :statut')
            ->setParameter('statut', $statut)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? 0.0;
    }

    /**
     * Calcule le total des paiements d'un utilisateur
     */
    public function getTotalByUtilisateur(int $utilisateurId): float
    {
        $result = $this->createQueryBuilder('p')
            ->select('SUM(p.montantTotal)')
            ->innerJoin('p.reservation', 'r')
            ->where('r.passager = :utilisateurId')
            ->andWhere('p.statut = :statut')
            ->setParameter('utilisateurId', $utilisateurId)
            ->setParameter('statut', 'REUSSI')
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? 0.0;
    }

    /**
     * Récupère un paiement par référence Campay
     */
    public function findByReference(string $reference): ?Paiement
    {
        return $this->createQueryBuilder('p')
            ->where('p.campayReference = :reference')
            ->setParameter('reference', $reference)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Récupère un paiement par code de transaction (alias de campayReference)
     */
    public function findByCodeTransaction(string $codeTransaction): ?Paiement
    {
        return $this->createQueryBuilder('p')
            ->where('p.campayReference = :codeTransaction')
            ->setParameter('codeTransaction', $codeTransaction)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Statistiques des paiements
     */
    public function getStats(): array
    {
        $qb = $this->createQueryBuilder('p');

        $total = $qb->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $totalMontant = $qb->select('SUM(p.montantTotal)')
            ->where('p.statut = :statut')
            ->setParameter('statut', 'REUSSI')
            ->getQuery()
            ->getSingleScalarResult();

        $stats = [
            'total' => (int) $total,
            'total_montant' => (float) ($totalMontant ?? 0),
            'pending' => (int) $this->count(['statut' => 'EN_ATTENTE']),
            'completed' => (int) $this->count(['statut' => 'REUSSI']),
            'failed' => (int) $this->count(['statut' => 'ECHEC']),
            'refunded' => (int) $this->count(['statut' => 'REMBOURSE']),
        ];

        return $stats;
    }
}
