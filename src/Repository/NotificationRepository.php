<?php

namespace App\Repository;

use App\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function findByUtilisateur(int $userId): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.destinataire = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('n.dateEnvoi', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findNonLuesByUtilisateur(int $userId): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.destinataire = :userId')
            ->andWhere('n.estLu = false')
            ->setParameter('userId', $userId)
            ->orderBy('n.dateEnvoi', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countNonLuesByUtilisateur(int $userId): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.destinataire = :userId')
            ->andWhere('n.estLu = false')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function marquerToutCommeLu(int $userId): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.estLu', ':estLu')
            ->set('n.dateLecture', ':dateLecture')
            ->where('n.destinataire = :userId')
            ->setParameter('estLu', true)
            ->setParameter('dateLecture', new \DateTimeImmutable())
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    public function existsByTrajetPassagerType(int $trajetId, int $passagerId, string $type): bool
    {
        return $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.trajet = :trajetId')
            ->andWhere('n.destinataire = :passagerId')
            ->andWhere('n.type = :type')
            ->setParameter('trajetId', $trajetId)
            ->setParameter('passagerId', $passagerId)
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}