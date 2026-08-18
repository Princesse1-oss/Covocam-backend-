<?php
namespace App\Repository;

use App\Entity\Trajet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TrajetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trajet::class);
    }

    public function findBySearch(?string $villeDepart, ?string $villeArrivee, ?string $date, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.statut IN (:statuts)')
            ->setParameter('statuts', \App\Service\TrajetLifecycleService::STATUTS_RESERVABLES)
            ->andWhere('t.placesDisponibles > 0')
            ->orderBy('t.dateDepart', 'ASC')
            ->setMaxResults($limit);

        if ($villeDepart) {
            $qb->andWhere('LOWER(t.villeDepart) LIKE LOWER(:villeDepart)')
               ->setParameter('villeDepart', '%' . $villeDepart . '%');
        }

        if ($villeArrivee) {
            $qb->andWhere('LOWER(t.villeArrivee) LIKE LOWER(:villeArrivee)')
               ->setParameter('villeArrivee', '%' . $villeArrivee . '%');
        }

        if ($date) {
            $qb->andWhere('t.dateDepart >= :dateDebut')
               ->andWhere('t.dateDepart < :dateFin')
               ->setParameter('dateDebut', new \DateTime($date . ' 00:00:00'))
               ->setParameter('dateFin', new \DateTime($date . ' 23:59:59'));
        }

        return $qb->getQuery()->getResult();
    }
}