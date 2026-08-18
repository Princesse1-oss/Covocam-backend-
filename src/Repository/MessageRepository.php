<?php

namespace App\Repository;

use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Récupère tous les messages d'un utilisateur (pour la liste des contacts)
     */
    public function findByUtilisateur(int $userId, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.expediteur = :userId OR m.destinataire = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('m.dateEnvoi', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère la conversation entre deux utilisateurs spécifiques
     */
    public function findConversation(int $user1Id, int $user2Id, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('m')
            ->where('(m.expediteur = :user1 AND m.destinataire = :user2) OR (m.expediteur = :user2 AND m.destinataire = :user1)')
            ->setParameter('user1', $user1Id)
            ->setParameter('user2', $user2Id)
            ->orderBy('m.dateEnvoi', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre de messages non lus pour un utilisateur
     */
    public function countNonLusByUtilisateur(int $userId): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.destinataire = :userId')
            ->andWhere('m.estLu = false')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Récupère les messages non lus d'un utilisateur (pour la notification)
     */
    public function findNonLusByUtilisateur(int $userId): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.destinataire = :userId')
            ->andWhere('m.estLu = false')
            ->setParameter('userId', $userId)
            ->orderBy('m.dateEnvoi', 'DESC')
            ->getQuery()
            ->getResult();
    }
}