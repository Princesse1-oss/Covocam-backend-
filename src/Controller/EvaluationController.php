<?php

namespace App\Controller;

use App\Entity\Evaluation;
use App\Entity\Reservation;
use App\Entity\Trajet;
use App\Entity\Utilisateur;
use App\Repository\ReservationRepository;
use App\Repository\UtilisateurRepository;
use App\Service\NoteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/evaluations', name: 'api_evaluations_')]
class EvaluationController extends AbstractController
{
    public function __construct(private NoteService $noteService)
    {
    }

    // 1. Noter un conducteur (Passager)
    #[Route('/conducteur', name: 'conducteur', methods: ['POST'])]
    public function noterConducteur(
        Request $request,
        EntityManagerInterface $entityManager,
        UtilisateurRepository $utilisateurRepository,
        ReservationRepository $reservationRepository
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['conducteurId']) || !isset($data['note'])) {
            return $this->json(['error' => 'Données invalides ou manquantes'], Response::HTTP_BAD_REQUEST);
        }

        $conducteur = $utilisateurRepository->find($data['conducteurId']);
        if (!$conducteur) {
            return $this->json(['error' => 'Conducteur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        // ✅ REQUÊTE OPTIMISÉE : Trouver une réservation valide sans boucle lourde
        $reservationValide = $reservationRepository->createQueryBuilder('r')
            ->join('r.trajet', 't')
            ->where('r.passager = :passager')
            ->andWhere('t.conducteur = :conducteur')
            ->andWhere('r.statut IN (:statuts)')
            ->andWhere('t.dateDepart < :now')
            ->setParameter('passager', $user)
            ->setParameter('conducteur', $conducteur)
            ->setParameter('statuts', ['CONFIRMEE', 'TERMINEE'])
            ->setParameter('now', new \DateTime())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$reservationValide) {
            return $this->json(['error' => 'Vous ne pouvez pas noter ce conducteur (trajet non confirmé ou non terminé)'], Response::HTTP_BAD_REQUEST);
        }

        // Vérifier si l'utilisateur a déjà évalué cette réservation (une seule par type)
        $alreadyEvaluated = $entityManager->getRepository(Evaluation::class)->findOneBy([
            'reservation' => $reservationValide,
            'auteur' => $user,
            'type' => NoteService::TYPE_CONDUCTEUR
        ]);

        if ($alreadyEvaluated) {
            return $this->json(['error' => 'Vous avez déjà évalué ce trajet'], Response::HTTP_BAD_REQUEST);
        }

        $evaluation = $this->construireEvaluation($user, $request);
        if ($evaluation === null) {
            return $this->json(['error' => 'La note doit être comprise entre 1 et 5 et le commentaire limité à 500 caractères'], Response::HTTP_BAD_REQUEST);
        }

        $evaluation->setType(NoteService::TYPE_CONDUCTEUR);
        $evaluation->setCible($conducteur);
        $evaluation->setReservation($reservationValide);
        $evaluation->setTrajet($reservationValide->getTrajet());

        $entityManager->persist($evaluation);
        $entityManager->flush();

        // Recalcul transactionnel + double-aveugle
        $this->noteService->recalculerNoteUtilisateur($conducteur);
        $this->noteService->revelerEvaluationsMutuelles($reservationValide);
        $entityManager->flush();

        return $this->json([
            'message' => 'Évaluation ajoutée avec succès',
            'evaluation' => $this->formatEvaluation($evaluation),
            'nouvelleNoteMoyenne' => $conducteur->getNoteMoyenne(),
            'totalEvaluations' => $conducteur->getTotalEvaluations()
        ], Response::HTTP_CREATED);
    }

    // 2. Noter un passager (Conducteur)
    #[Route('/passager', name: 'passager', methods: ['POST'])]
    public function noterPassager(
        Request $request,
        EntityManagerInterface $entityManager,
        UtilisateurRepository $utilisateurRepository,
        ReservationRepository $reservationRepository
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['passagerId']) || !isset($data['note'])) {
            return $this->json(['error' => 'Données invalides ou manquantes'], Response::HTTP_BAD_REQUEST);
        }

        $passager = $utilisateurRepository->find($data['passagerId']);
        if (!$passager) {
            return $this->json(['error' => 'Passager non trouvé'], Response::HTTP_NOT_FOUND);
        }

        // ✅ REQUÊTE OPTIMISÉE : Trouver une réservation valide sans boucles imbriquées
        $reservationValide = $reservationRepository->createQueryBuilder('r')
            ->join('r.trajet', 't')
            ->where('r.passager = :passager')
            ->andWhere('t.conducteur = :conducteur')
            ->andWhere('r.statut IN (:statuts)')
            ->andWhere('t.dateDepart < :now')
            ->setParameter('passager', $passager)
            ->setParameter('conducteur', $user)
            ->setParameter('statuts', ['CONFIRMEE', 'TERMINEE'])
            ->setParameter('now', new \DateTime())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$reservationValide) {
            return $this->json(['error' => 'Vous ne pouvez pas noter ce passager (trajet non confirmé ou non terminé)'], Response::HTTP_BAD_REQUEST);
        }

        $alreadyEvaluated = $entityManager->getRepository(Evaluation::class)->findOneBy([
            'reservation' => $reservationValide,
            'auteur' => $user,
            'type' => NoteService::TYPE_PASSAGER
        ]);

        if ($alreadyEvaluated) {
            return $this->json(['error' => 'Vous avez déjà évalué ce passager'], Response::HTTP_BAD_REQUEST);
        }

        $evaluation = $this->construireEvaluation($user, $request);
        if ($evaluation === null) {
            return $this->json(['error' => 'La note doit être comprise entre 1 et 5 et le commentaire limité à 500 caractères'], Response::HTTP_BAD_REQUEST);
        }

        $evaluation->setType(NoteService::TYPE_PASSAGER);
        $evaluation->setCible($passager);
        $evaluation->setReservation($reservationValide);
        $evaluation->setTrajet($reservationValide->getTrajet());

        $entityManager->persist($evaluation);
        $entityManager->flush();

        $this->noteService->recalculerNoteUtilisateur($passager);
        $this->noteService->revelerEvaluationsMutuelles($reservationValide);
        $entityManager->flush();

        return $this->json([
            'message' => 'Évaluation ajoutée avec succès',
            'evaluation' => $this->formatEvaluation($evaluation),
            'nouvelleNoteMoyenne' => $passager->getNoteMoyenne(),
            'totalEvaluations' => $passager->getTotalEvaluations()
        ], Response::HTTP_CREATED);
    }

    // 3. Évaluer après un trajet (auto-détection du type)
    #[Route('', name: 'post', methods: ['POST'])]
    public function noter(
        Request $request,
        EntityManagerInterface $entityManager,
        UtilisateurRepository $utilisateurRepository,
        ReservationRepository $reservationRepository
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data || empty($data['trajetId']) || !isset($data['note'])) {
            return $this->json(['error' => 'Données invalides ou manquantes (trajetId, note requis)'], Response::HTTP_BAD_REQUEST);
        }

        $trajet = $entityManager->getRepository(Trajet::class)->find($data['trajetId']);
        if (!$trajet) {
            return $this->json(['error' => 'Trajet non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $estConducteur = $trajet->getConducteur() && $trajet->getConducteur()->getId() === $user->getId();

        $evaluation = $this->construireEvaluation($user, $request);
        if ($evaluation === null) {
            return $this->json(['error' => 'La note doit être comprise entre 1 et 5 et le commentaire limité à 500 caractères'], Response::HTTP_BAD_REQUEST);
        }

        if ($estConducteur) {
            // Le conducteur évalue le passager (un passagerId est requis)
            $passagerId = $data['passagerId'] ?? null;
            if (!$passagerId) {
                return $this->json(['error' => 'passagerId requis pour évaluer un passager'], Response::HTTP_BAD_REQUEST);
            }
            $cible = $utilisateurRepository->find($passagerId);
            if (!$cible) {
                return $this->json(['error' => 'Passager non trouvé'], Response::HTTP_NOT_FOUND);
            }

            $reservation = $reservationRepository->findOneBy([
                'trajet' => $trajet,
                'passager' => $cible
            ]);
            if (!$reservation || !in_array($reservation->getStatut(), ['CONFIRMEE', 'TERMINEE'])) {
                return $this->json(['error' => 'Vous ne pouvez pas noter ce passager (trajet non confirmé ou non terminé)'], Response::HTTP_BAD_REQUEST);
            }

            $alreadyEvaluated = $entityManager->getRepository(Evaluation::class)->findOneBy([
                'reservation' => $reservation,
                'auteur' => $user,
                'type' => NoteService::TYPE_PASSAGER
            ]);
            if ($alreadyEvaluated) {
                return $this->json(['error' => 'Vous avez déjà évalué ce passager'], Response::HTTP_BAD_REQUEST);
            }

            $evaluation->setType(NoteService::TYPE_PASSAGER);
            $evaluation->setCible($cible);
            $evaluation->setReservation($reservation);
            $evaluation->setTrajet($trajet);

            $entityManager->persist($evaluation);
            $entityManager->flush();
            $this->noteService->recalculerNoteUtilisateur($cible);
            $this->noteService->revelerEvaluationsMutuelles($reservation);
            $entityManager->flush();

            return $this->json([
                'message' => 'Évaluation ajoutée avec succès',
                'evaluation' => $this->formatEvaluation($evaluation),
                'nouvelleNoteMoyenne' => $cible->getNoteMoyenne()
            ], Response::HTTP_CREATED);
        }

        // Sinon : le passager évalue le conducteur
        $cible = $trajet->getConducteur();
        if (!$cible) {
            return $this->json(['error' => 'Conducteur introuvable'], Response::HTTP_NOT_FOUND);
        }

        $reservation = $reservationRepository->findOneBy([
            'trajet' => $trajet,
            'passager' => $user
        ]);
        if (!$reservation || !in_array($reservation->getStatut(), ['CONFIRMEE', 'TERMINEE', 'TERMINE'])) {
            return $this->json(['error' => 'Vous ne pouvez pas noter ce conducteur (trajet non confirmé ou non terminé)'], Response::HTTP_BAD_REQUEST);
        }

        $alreadyEvaluated = $entityManager->getRepository(Evaluation::class)->findOneBy([
            'reservation' => $reservation,
            'auteur' => $user,
            'type' => NoteService::TYPE_CONDUCTEUR
        ]);
        if ($alreadyEvaluated) {
            return $this->json(['error' => 'Vous avez déjà évalué ce trajet'], Response::HTTP_BAD_REQUEST);
        }

        $evaluation->setType(NoteService::TYPE_CONDUCTEUR);
        $evaluation->setCible($cible);
        $evaluation->setReservation($reservation);
        $evaluation->setTrajet($trajet);

        $entityManager->persist($evaluation);
        $this->noteService->recalculerNoteUtilisateur($cible);
        $this->noteService->revelerEvaluationsMutuelles($reservation);
        $entityManager->flush();

        return $this->json([
            'message' => 'Évaluation ajoutée avec succès',
            'evaluation' => $this->formatEvaluation($evaluation),
            'nouvelleNoteMoyenne' => $cible->getNoteMoyenne(),
            'totalEvaluations' => $cible->getTotalEvaluations()
        ], Response::HTTP_CREATED);
    }

    // 4. Évaluer la plateforme (tous les utilisateurs)
    #[Route('/plateforme', name: 'plateforme', methods: ['POST'])]
    public function noterPlateforme(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $evaluation = $this->construireEvaluation($user, $request);
        if ($evaluation === null) {
            return $this->json(['error' => 'La note doit être comprise entre 1 et 5 et le commentaire limité à 500 caractères'], Response::HTTP_BAD_REQUEST);
        }

        $alreadyEvaluated = $entityManager->getRepository(Evaluation::class)->findOneBy([
            'auteur' => $user,
            'type' => NoteService::TYPE_PLATEFORME,
            'cible' => null
        ]);

        if ($alreadyEvaluated) {
            return $this->json(['error' => 'Vous avez déjà évalué la plateforme'], Response::HTTP_BAD_REQUEST);
        }

        $evaluation->setType(NoteService::TYPE_PLATEFORME);
        $evaluation->setCible(null);
        $evaluation->setReservation(null);
        $evaluation->setTrajet(null);

        $entityManager->persist($evaluation);
        $entityManager->flush();

        return $this->json([
            'message' => 'Merci pour votre avis sur la plateforme',
            'evaluation' => $this->formatEvaluation($evaluation),
            'moyenne' => $this->noteService->moyennePlateforme()
        ], Response::HTTP_CREATED);
    }

    // 4. Moyenne plateforme
    #[Route('/plateforme/moyenne', name: 'plateforme_moyenne', methods: ['GET'])]
    public function moyennePlateforme(): JsonResponse
    {
        return $this->json($this->noteService->moyennePlateforme());
    }

    // 5. Mes évaluations reçues (ou d'un autre utilisateur pour l'admin)
    #[Route('/recues', name: 'recues', methods: ['GET'])]
    public function getMesEvaluationsRecues(Request $request, UtilisateurRepository $utilisateurRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $cible = $user;
        $userId = $request->query->get('userId');
        if ($userId !== null) {
            if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                return $this->json(['error' => 'Accès réservé à l\'administrateur'], Response::HTTP_FORBIDDEN);
            }
            $cible = $utilisateurRepository->find((int) $userId);
            if (!$cible) {
                return $this->json(['error' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
            }
        }

        $result = [];
        foreach ($cible->getEvaluationsRecues() as $evaluation) {
            $result[] = $this->formatEvaluation($evaluation);
        }

        return $this->json($result);
    }

    // 6. Mes évaluations données
    #[Route('/donnees', name: 'donnees', methods: ['GET'])]
    public function getMesEvaluationsDonnees(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $result = [];
        foreach ($user->getEvaluationsDonnees() as $evaluation) {
            $result[] = $this->formatEvaluation($evaluation);
        }

        return $this->json($result);
    }

    // 6bis. Toutes les évaluations (Admin)
    #[Route('', name: 'list_all', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function listAll(EntityManagerInterface $entityManager): JsonResponse
    {
        $evaluations = $entityManager->getRepository(Evaluation::class)->createQueryBuilder('e')
            ->orderBy('e.dateEvaluation', 'DESC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($evaluations as $evaluation) {
            $result[] = $this->formatEvaluation($evaluation);
        }

        return $this->json($result);
    }

    // 7. Supprimer une évaluation (Admin)
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function supprimer(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $evaluation = $entityManager->getRepository(Evaluation::class)->find($id);
        if (!$evaluation) {
            return $this->json(['error' => 'Évaluation non trouvée'], Response::HTTP_NOT_FOUND);
        }

        $cible = $evaluation->getCible();
        $entityManager->remove($evaluation);

        // Recalculer la note de l'utilisateur ciblé (si applicable)
        if ($cible) {
            $this->noteService->recalculerNoteUtilisateur($cible);
        }

        $entityManager->flush();

        return $this->json(['message' => 'Évaluation supprimée avec succès']);
    }

    // ✅ Méthode de construction commune : note 1-5 + commentaire ≤ 500
    private function construireEvaluation(Utilisateur $auteur, Request $request): ?Evaluation
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return null;
        }

        $note = (int) ($data['note'] ?? 0);
        if ($note < 1 || $note > 5) {
            return null;
        }

        $commentaire = $data['commentaire'] ?? null;
        if ($commentaire !== null && strlen($commentaire) > 500) {
            return null;
        }

        $evaluation = new Evaluation();
        $evaluation->setNote($note);
        $evaluation->setCommentaire($commentaire !== null && $commentaire !== '' ? $commentaire : null);
        $evaluation->setAuteur($auteur);

        return $evaluation;
    }

    // ✅ MÉTHODE DE FORMATAGE SÉCURISÉE
    private function formatEvaluation(Evaluation $evaluation): array
    {
        $reservation = $evaluation->getReservation();
        $trajet = $reservation ? $reservation->getTrajet() : $evaluation->getTrajet();

        $data = [
            'id' => $evaluation->getId(),
            'note' => $evaluation->getNote(),
            'commentaire' => $evaluation->getCommentaire(),
            'type' => $evaluation->getType(),
            'revele' => $evaluation->isRevele(),
            'dateEvaluation' => $evaluation->getDateEvaluation()?->format('Y-m-d H:i:s'),
            'auteur' => $evaluation->getAuteur() ? [
                'id' => $evaluation->getAuteur()->getId(),
                'nom' => $evaluation->getAuteur()->getNom(),
                'prenom' => $evaluation->getAuteur()->getPrenom(),
                'email' => $evaluation->getAuteur()->getEmail(),
                'photo' => $evaluation->getAuteur()->getPhoto()
            ] : null,
            'cible' => $evaluation->getCible() ? [
                'id' => $evaluation->getCible()->getId(),
                'nom' => $evaluation->getCible()->getNom(),
                'prenom' => $evaluation->getCible()->getPrenom(),
                'email' => $evaluation->getCible()->getEmail(),
                'photo' => $evaluation->getCible()->getPhoto()
            ] : null,
        ];

        if ($trajet) {
            $data['trajet'] = [
                'id' => $trajet->getId(),
                'villeDepart' => $trajet->getVilleDepart(),
                'villeArrivee' => $trajet->getVilleArrivee(),
                'dateDepart' => $trajet->getDateDepart()?->format('Y-m-d H:i:s')
            ];
        }

        if ($reservation) {
            $data['reservation'] = [
                'id' => $reservation->getId(),
                'trajet' => $trajet ? [
                    'id' => $trajet->getId(),
                    'villeDepart' => $trajet->getVilleDepart(),
                    'villeArrivee' => $trajet->getVilleArrivee(),
                    'dateDepart' => $trajet->getDateDepart()?->format('Y-m-d H:i:s')
                ] : null
            ];
        }

        return $data;
    }
}