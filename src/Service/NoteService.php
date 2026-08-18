<?php

namespace App\Service;

use App\Entity\Evaluation;
use App\Entity\Reservation;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gestion des évaluations et des notes moyennes.
 *
 * - Recalcul transactionnel : la note moyenne est recalculée en SQL (AVG) après
 *   chaque ajout, jamais cumulée à la main (fiabilité en concurrence).
 * - Double-aveugle : une note reste cachée tant que les deux parties du trajet
 *   n'ont pas évalué (ou après un délai, géré par les contrôleurs).
 */
class NoteService
{
    public const TYPE_CONDUCTEUR = 'CONDUCTEUR';
    public const TYPE_PASSAGER = 'PASSAGER';
    public const TYPE_PLATEFORME = 'PLATEFORME';

    public const TYPES_UTILISATEUR = [
        self::TYPE_CONDUCTEUR,
        self::TYPE_PASSAGER,
    ];

    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /** Recalcule la note moyenne reçue par un utilisateur (notes CONDUCTEUR + PASSAGER). */
    public function recalculerNoteUtilisateur(Utilisateur $utilisateur): void
    {
        $result = $this->em->createQueryBuilder()
            ->select('AVG(e.note) AS moyenne', 'COUNT(e.id) AS total')
            ->from(Evaluation::class, 'e')
            ->where('e.cible = :cible')
            ->andWhere('e.type IN (:types)')
            ->setParameter('cible', $utilisateur)
            ->setParameter('types', self::TYPES_UTILISATEUR)
            ->getQuery()
            ->getSingleResult();

        $moyenne = $result['moyenne'] !== null ? round((float) $result['moyenne'], 1) : 0.0;

        $utilisateur->setNoteMoyenne($moyenne);
        $utilisateur->setTotalEvaluations((int) $result['total']);
    }

    /** Moyenne globale attribuée à la plateforme (type PLATEFORME). */
    public function moyennePlateforme(): array
    {
        $result = $this->em->createQueryBuilder()
            ->select('AVG(e.note) AS moyenne', 'COUNT(e.id) AS total')
            ->from(Evaluation::class, 'e')
            ->where('e.type = :type')
            ->setParameter('type', self::TYPE_PLATEFORME)
            ->getQuery()
            ->getSingleResult();

        return [
            'moyenne' => $result['moyenne'] !== null ? round((float) $result['moyenne'], 1) : 0.0,
            'total' => (int) $result['total'],
        ];
    }

    /**
     * Double-aveugle : dès que le conducteur ET le passager ont évalué la même
     * réservation, les deux notes sont révélées simultanément.
     * Ne flush pas (l'appelant gère le flush).
     */
    public function revelerEvaluationsMutuelles(Reservation $reservation): void
    {
        $evaluationPassager = null;
        $evaluationConducteur = null;

        foreach ($reservation->getEvaluations() as $evaluation) {
            if ($evaluation->getType() === self::TYPE_CONDUCTEUR) {
                $evaluationPassager = $evaluation;
            } elseif ($evaluation->getType() === self::TYPE_PASSAGER) {
                $evaluationConducteur = $evaluation;
            }
        }

        if ($evaluationPassager !== null && $evaluationConducteur !== null) {
            $evaluationPassager->setRevele(true);
            $evaluationConducteur->setRevele(true);
        }
    }
}
