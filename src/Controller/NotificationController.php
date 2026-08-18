<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class NotificationController extends AbstractController
{
    // 1. Mes notifications
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(
        NotificationRepository $notificationRepository,
        Request $request
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $limit = $request->query->get('limit', 50);
        $offset = $request->query->get('offset', 0);

        $notifications = $notificationRepository->findByUtilisateur($user->getId());

        // Pagination manuelle
        $total = count($notifications);
        $notifications = array_slice($notifications, $offset, $limit);

        $result = [];
        foreach ($notifications as $notification) {
            $result[] = $this->formatNotification($notification);
        }

        return $this->json([
            'data' => $result,
            'pagination' => [
                'limit' => (int) $limit,
                'offset' => (int) $offset,
                'total' => $total
            ]
        ]);
    }

    // 2. Notifications non lues
    #[Route('/non-lues', name: 'non_lues', methods: ['GET'])]
    public function getNonLues(NotificationRepository $notificationRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $notifications = $notificationRepository->findNonLuesByUtilisateur($user->getId());

        $result = [];
        foreach ($notifications as $notification) {
            $result[] = $this->formatNotification($notification);
        }

        return $this->json([
            'count' => count($result),
            'data' => $result
        ]);
    }

    // 3. Nombre de notifications non lues
       #[Route('/non-lues/count', name: 'count_non_lues', methods: ['GET'])]
    public function countNonLues(NotificationRepository $notificationRepository): JsonResponse
    {
        $user = $this->getUser();
        
        // ✅ SÉCURITÉ : On vérifie que c'est bien ton entité Utilisateur
        if (!$user instanceof \App\Entity\Utilisateur) {
            return new JsonResponse(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            // ✅ SÉCURITÉ : On force le cast en entier pour éviter tout type mismatch
            $count = $notificationRepository->countNonLuesByUtilisateur((int) $user->getId());
            return new JsonResponse(['count' => $count]);
        } catch (\Exception $e) {
            // En cas d'erreur, on renvoie 0 au lieu de faire planter toute la page
            return new JsonResponse(['count' => 0, 'warning' => 'Erreur de comptage']);
        }
    }

    // 4. Marquer une notification comme lue
    #[Route('/{id}/lire', name: 'read', methods: ['POST'])]
    public function marquerCommeLue(
        Notification $notification,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        // Vérifier que la notification appartient à l'utilisateur
        if ($notification->getDestinataire()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Vous n\'êtes pas autorisé'], Response::HTTP_FORBIDDEN);
        }

        $notification->setEstLu(true);
        $notification->setDateLecture(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->json([
            'message' => 'Notification marquée comme lue',
            'notification' => $this->formatNotification($notification)
        ]);
    }

    // 5. Marquer toutes les notifications comme lues
    #[Route('/lire-tout', name: 'read_all', methods: ['POST'])]
    public function marquerToutCommeLu(
        NotificationRepository $notificationRepository
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $updated = $notificationRepository->marquerToutCommeLu($user->getId());

        return $this->json([
            'message' => 'Toutes les notifications ont été marquées comme lues',
            'updated' => $updated
        ]);
    }

    // 6. Supprimer une notification
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(
        Notification $notification,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        // Vérifier que la notification appartient à l'utilisateur
        if ($notification->getDestinataire()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Vous n\'êtes pas autorisé'], Response::HTTP_FORBIDDEN);
        }

        $entityManager->remove($notification);
        $entityManager->flush();

        return $this->json(['message' => 'Notification supprimée avec succès']);
    }

    // 7. Supprimer toutes les notifications lues
    #[Route('/lues/supprimer', name: 'delete_read', methods: ['DELETE'])]
    public function deleteRead(
        NotificationRepository $notificationRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $notifications = $notificationRepository->findBy([
            'destinataire' => $user,
            'estLu' => true
        ]);

        $count = count($notifications);
        foreach ($notifications as $notification) {
            $entityManager->remove($notification);
        }
        $entityManager->flush();

        return $this->json([
            'message' => 'Notifications lues supprimées avec succès',
            'deleted' => $count
        ]);
    }

    private function formatNotification(Notification $notification): array
    {
        $data = [
            'id' => $notification->getId(),
            'titre' => $notification->getTitre(),
            'message' => $notification->getMessage(),
            'type' => $notification->getType(),
            'estLu' => $notification->isEstLu(),
            'dateEnvoi' => $notification->getDateEnvoi()?->format('Y-m-d H:i:s'),
            'dateLecture' => $notification->getDateLecture()?->format('Y-m-d H:i:s'),
            'icone' => $notification->getIcone(),
            'couleur' => $notification->getCouleur(),
            'url' => $notification->getUrl(),
            'reservationId' => $notification->getReservation()?->getId(), 
            'trajetId' => $notification->getTrajet()?->getId()
        ];

        if ($notification->getTrajet()) {
            $data['trajet'] = [
                'id' => $notification->getTrajet()->getId(),
                'villeDepart' => $notification->getTrajet()->getVilleDepart(),
                'villeArrivee' => $notification->getTrajet()->getVilleArrivee()
            ];
        }

        if ($notification->getReservation()) {
            $data['reservation'] = [
                'id' => $notification->getReservation()->getId(),
                'statut' => $notification->getReservation()->getStatut()
            ];
        }

        return $data;
    }
}