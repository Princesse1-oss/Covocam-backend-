<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\TrajetRepository;
use App\Repository\ReservationRepository;
use App\Repository\VehiculeRepository;
use App\Repository\EvaluationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/conducteur', name: 'api_conducteur_')]
class ConducteurController extends AbstractController
{
    private function getConducteurOrDeny(): Utilisateur
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            throw $this->createAccessDeniedException('Non authentifié');
        }

        // Accès réservé aux profils conducteur / les_deux (voir décision 1.4)
        $type = strtolower((string) $user->getTypeUtilisateur());
        if (!in_array($type, ['conducteur', 'les_deux'], true)) {
            throw $this->createAccessDeniedException('Accès réservé aux conducteurs');
        }

        return $user;
    }

    #[Route('/dashboard/stats', name: 'dashboard_stats', methods: ['GET'])]
    public function getDashboardStats(
        TrajetRepository $trajetRepository,
        ReservationRepository $reservationRepository,
        EvaluationRepository $evaluationRepository
    ): JsonResponse {
        $conducteur = $this->getConducteurOrDeny();

        $startOfMonth = new \DateTime('first day of this month');
        $endOfMonth = new \DateTime('last day of this month 23:59:59');

        // Trajets du mois
        $trajetsMois = (int) $trajetRepository->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.conducteur = :conducteur')
            ->andWhere('t.dateDepart BETWEEN :start AND :end')
            ->setParameter('conducteur', $conducteur)
            ->setParameter('start', $startOfMonth)
            ->setParameter('end', $endOfMonth)
            ->getQuery()
            ->getSingleScalarResult();

        // Trajets terminés ce mois (pour calculer les gains)
        $trajetsTerminesMois = (int) $trajetRepository->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.conducteur = :conducteur')
            ->andWhere('t.statut = :statut')
            ->andWhere('t.dateDepart BETWEEN :start AND :end')
            ->setParameter('conducteur', $conducteur)
            ->setParameter('statut', 'TERMINE')
            ->setParameter('start', $startOfMonth)
            ->setParameter('end', $endOfMonth)
            ->getQuery()
            ->getSingleScalarResult();

        // Gains : somme des prix totaux des réservations confirmées/terminées du mois
        $gainsMois = (float) $reservationRepository->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.prixTotal), 0)')
            ->innerJoin('r.trajet', 't')
            ->where('t.conducteur = :conducteur')
            ->andWhere('t.statut = :statut')
            ->andWhere('t.dateDepart BETWEEN :start AND :end')
            ->andWhere('r.statut IN (:statutsResa)')
            ->setParameter('conducteur', $conducteur)
            ->setParameter('statut', 'TERMINE')
            ->setParameter('statutsResa', ['TERMINEE', 'CONFIRMEE'])
            ->setParameter('start', $startOfMonth)
            ->setParameter('end', $endOfMonth)
            ->getQuery()
            ->getSingleScalarResult();

        // Demandes en attente (statuts en MAJUSCULES en base)
        $demandesEnAttente = (int) $reservationRepository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->innerJoin('r.trajet', 't')
            ->where('t.conducteur = :conducteur')
            ->andWhere('r.statut IN (:statuts)')
            ->setParameter('conducteur', $conducteur)
            ->setParameter('statuts', ['EN_ATTENTE', 'A_PAYER'])
            ->getQuery()
            ->getSingleScalarResult();

        // Note moyenne (cible = le conducteur, champ cible au lieu de conducteurEvalue)
        $noteMoyenne = $evaluationRepository->createQueryBuilder('e')
            ->select('AVG(e.note)')
            ->where('e.cible = :conducteur')
            ->setParameter('conducteur', $conducteur)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->json([
            'trajetsMois' => $trajetsMois,
            'trajetsTerminesMois' => $trajetsTerminesMois,
            'gainsMois' => (int) round($gainsMois),
            'demandesEnAttente' => $demandesEnAttente,
            'noteMoyenne' => $noteMoyenne ? round((float) $noteMoyenne, 1) : 0,
        ]);
    }

    #[Route('/dashboard/prochains-trajets', name: 'dashboard_prochains_trajets', methods: ['GET'])]
    public function getProchainsTrajets(TrajetRepository $trajetRepository): JsonResponse
    {
        $conducteur = $this->getConducteurOrDeny();

        $now = new \DateTime();

        $trajets = $trajetRepository->createQueryBuilder('t')
            ->where('t.conducteur = :conducteur')
            ->andWhere('t.dateDepart > :now')
            ->andWhere('t.statut IN (:statuts)')
            ->setParameter('conducteur', $conducteur)
            ->setParameter('now', $now)
            ->setParameter('statuts', ['OUVERT', 'COMPLET', 'EN_ATTENTE_DEPART'])
            ->orderBy('t.dateDepart', 'ASC')
            ->setMaxResults(3)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($trajets as $trajet) {
            $result[] = [
                'id' => $trajet->getId(),
                'villeDepart' => $trajet->getVilleDepart(),
                'quartierDepart' => $trajet->getQuartierDepart(),
                'villeArrivee' => $trajet->getVilleArrivee(),
                'quartierArrivee' => $trajet->getQuartierArrivee(),
                'dateDepart' => $trajet->getDateDepart()?->format('Y-m-d H:i'),
                'prixParPlace' => $trajet->getPrixParPlace(),
                'placesDisponibles' => $trajet->getPlacesDisponibles(),
                'nbReservations' => count($trajet->getReservations()),
                'statut' => $trajet->getStatut(),
            ];
        }

        return $this->json($result);
    }

    #[Route('/dashboard/demandes-en-attente', name: 'dashboard_demandes', methods: ['GET'])]
    public function getDemandesEnAttente(ReservationRepository $reservationRepository): JsonResponse
    {
        $conducteur = $this->getConducteurOrDeny();

        $demandes = $reservationRepository->createQueryBuilder('r')
            ->innerJoin('r.trajet', 't')
            ->innerJoin('r.passager', 'p')
            ->where('t.conducteur = :conducteur')
            ->andWhere('r.statut = :statut')
            ->setParameter('conducteur', $conducteur)
            ->setParameter('statut', 'EN_ATTENTE')
            ->orderBy('r.dateReservation', 'DESC')
            ->setMaxResults(3)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($demandes as $demande) {
            $passager = $demande->getPassager();
            $trajet = $demande->getTrajet();

            $result[] = [
                'id' => $demande->getId(),
                'passagerNom' => $passager->getPrenom() . ' ' . $passager->getNom(),
                'passagerNote' => $passager->getNoteMoyenne(),
                'trajetId' => $trajet->getId(),
                'villeDepart' => $trajet->getVilleDepart(),
                'villeArrivee' => $trajet->getVilleArrivee(),
                'dateDepart' => $trajet->getDateDepart()?->format('Y-m-d H:i'),
                'dateCreation' => $demande->getDateReservation()?->format('Y-m-d H:i'),
            ];
        }

        return $this->json($result);
    }

    #[Route('/dashboard/verifications', name: 'dashboard_verifications', methods: ['GET'])]
    public function getVerifications(VehiculeRepository $vehiculeRepository): JsonResponse
    {
        $conducteur = $this->getConducteurOrDeny();

        // ✅ CORRIGÉ : la relation est vehicule.utilisateur (pas vehicule.conducteur)
        $vehicule = $vehiculeRepository->findOneBy(['utilisateur' => $conducteur]);

        $alertes = [];

        if (!$vehicule) {
            $alertes[] = [
                'type' => 'vehicule_manquant',
                'message' => 'Vous devez ajouter un véhicule pour publier des trajets',
                'action' => '/conducteur/vehicule',
                'priorite' => 'haute',
            ];
        }

        return $this->json([
            'aVehicule' => $vehicule !== null,
            'alertes' => $alertes,
        ]);
    }
}
