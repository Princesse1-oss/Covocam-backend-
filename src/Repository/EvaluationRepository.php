<?php

namespace App\Repository;

use App\Entity\Evaluation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evaluation>
 */
class EvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evaluation::class);
    }

    /**
     * Récupère les évaluations données par un utilisateur (auteur)
     */
    public function findEvaluationsDonnees(int $utilisateurId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.auteur = :utilisateurId')
            ->setParameter('utilisateurId', $utilisateurId)
            ->orderBy('e.dateEvaluation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les évaluations reçues par un utilisateur (cible)
     */
    public function findEvaluationsRecues(int $utilisateurId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.cible = :utilisateurId')
            ->setParameter('utilisateurId', $utilisateurId)
            ->orderBy('e.dateEvaluation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les évaluations d'une réservation
     */
    public function findByReservation(int $reservationId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.reservation = :reservationId')
            ->setParameter('reservationId', $reservationId)
            ->orderBy('e.dateEvaluation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère la note moyenne d'un utilisateur (cible)
     */
    public function getNoteMoyenneByUtilisateur(int $utilisateurId): ?float
    {
        $result = $this->createQueryBuilder('e')
            ->select('AVG(e.note) as moyenne')
            ->where('e.cible = :utilisateurId')
            ->setParameter('utilisateurId', $utilisateurId)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? (float) $result : null;
    }

    /**
     * Récupère les évaluations avec une note supérieure ou égale à une valeur
     */
    public function findByNoteMinimale(int $utilisateurId, int $noteMin): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.cible = :utilisateurId')
            ->andWhere('e.note >= :noteMin')
            ->setParameter('utilisateurId', $utilisateurId)
            ->setParameter('noteMin', $noteMin)
            ->orderBy('e.note', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les évaluations avec une note spécifique
     */
    public function findByNote(int $utilisateurId, int $note): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.cible = :utilisateurId')
            ->andWhere('e.note = :note')
            ->setParameter('utilisateurId', $utilisateurId)
            ->setParameter('note', $note)
            ->orderBy('e.dateEvaluation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les évaluations avec commentaire
     */
    public function findAvecCommentaire(int $utilisateurId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.cible = :utilisateurId')
            ->andWhere('e.commentaire IS NOT NULL')
            ->andWhere('e.commentaire != :empty')
            ->setParameter('utilisateurId', $utilisateurId)
            ->setParameter('empty', '')
            ->orderBy('e.dateEvaluation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifie si un utilisateur a déjà évalué un autre utilisateur pour une réservation
     */
    public function hasEvaluated(int $auteurId, int $cibleId, int $reservationId): bool
    {
        $result = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.auteur = :auteurId')
            ->andWhere('e.cible = :cibleId')
            ->andWhere('e.reservation = :reservationId')
            ->setParameter('auteurId', $auteurId)
            ->setParameter('cibleId', $cibleId)
            ->setParameter('reservationId', $reservationId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result > 0;
    }

    /**
     * Récupère les statistiques des notes pour un utilisateur
     */
    public function getStatistiquesNotes(int $utilisateurId): array
    {
        $total = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.cible = :utilisateurId')
            ->setParameter('utilisateurId', $utilisateurId)
            ->getQuery()
            ->getSingleScalarResult();

        $moyenne = $this->getNoteMoyenneByUtilisateur($utilisateurId);

        // Nombre par note (1 à 5)
        $notes = [];
        for ($i = 1; $i <= 5; $i++) {
            $count = $this->createQueryBuilder('e')
                ->select('COUNT(e.id)')
                ->where('e.cible = :utilisateurId')
                ->andWhere('e.note = :note')
                ->setParameter('utilisateurId', $utilisateurId)
                ->setParameter('note', $i)
                ->getQuery()
                ->getSingleScalarResult();
            $notes[$i] = (int) $count;
        }

        return [
            'total' => (int) $total,
            'moyenne' => $moyenne,
            'repartition' => $notes
        ];
    }

    /**
     * Récupère les 5 dernières évaluations reçues par un utilisateur
     */
    public function findDernieresEvaluationsRecues(int $utilisateurId, int $limit = 5): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.cible = :utilisateurId')
            ->setParameter('utilisateurId', $utilisateurId)
            ->orderBy('e.dateEvaluation', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifie si une évaluation existe déjà pour une réservation spécifique
     */
    public function findExistingEvaluation(int $auteurId, int $cibleId, int $reservationId): ?Evaluation
    {
        return $this->createQueryBuilder('e')
            ->where('e.auteur = :auteurId')
            ->andWhere('e.cible = :cibleId')
            ->andWhere('e.reservation = :reservationId')
            ->setParameter('auteurId', $auteurId)
            ->setParameter('cibleId', $cibleId)
            ->setParameter('reservationId', $reservationId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}