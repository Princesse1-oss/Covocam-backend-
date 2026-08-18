<?php

namespace App\Controller;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/messages', name: 'api_messages_')]
class MessageController extends AbstractController
{
    // 1. Envoyer un message
    #[Route('', name: 'send', methods: ['POST'])]
    public function send(
        Request $request,
        EntityManagerInterface $entityManager,
        UtilisateurRepository $utilisateurRepository
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        // Vérifier que le destinataire existe
        $destinataire = $utilisateurRepository->find($data['destinataireId']);
        if (!$destinataire) {
            return $this->json(['error' => 'Destinataire non trouvé'], Response::HTTP_NOT_FOUND);
        }

        // Vérifier qu'on n'envoie pas à soi-même
        if ($destinataire->getId() === $user->getId()) {
            return $this->json(['error' => 'Vous ne pouvez pas vous envoyer un message à vous-même'], Response::HTTP_BAD_REQUEST);
        }

        // Validation: message max 1000 caractères
        $contenu = $data['contenu'] ?? '';
        if (strlen($contenu) > 1000) {
            return $this->json(['error' => 'Le message ne peut pas dépasser 1000 caractères'], Response::HTTP_BAD_REQUEST);
        }

        // Vérification: messagerie uniquement entre conducteur et passager avec réservation active
        $hasReservation = false;
        $userReservations = $user->getReservations();
        foreach ($userReservations as $reservation) {
            if (in_array($reservation->getStatut(), ['EN_ATTENTE', 'A_PAYER', 'CONFIRMEE'])) {
                $trajet = $reservation->getTrajet();
                if ($trajet) {
                    $conducteur = $trajet->getConducteur();
                    if ($conducteur && $conducteur->getId() === $destinataire->getId()) {
                        $hasReservation = true;
                        break;
                    }
                }
            }
        }

        // Vérifier aussi si l'utilisateur est conducteur et le destinataire est passager
        if (!$hasReservation && method_exists($user, 'getTrajetsConduits')) {
            foreach ($user->getTrajetsConduits() as $trajet) {
                foreach ($trajet->getReservations() as $reservation) {
                    if (in_array($reservation->getStatut(), ['EN_ATTENTE', 'A_PAYER', 'CONFIRMEE'])) {
                        if ($reservation->getPassager()->getId() === $destinataire->getId()) {
                            $hasReservation = true;
                            break 2;
                        }
                    }
                }
            }
        }

        if (!$hasReservation) {
            return $this->json(['error' => 'Vous ne pouvez envoyer des messages qu\'aux utilisateurs avec qui vous avez une réservation active'], Response::HTTP_FORBIDDEN);
        }

        // Créer le message
        $message = new Message();
        $message->setContenu($contenu);
        $message->setTypeMessage($data['typeMessage'] ?? 'simple');
        $message->setExpediteur($user);
        $message->setDestinataire($destinataire);

        $entityManager->persist($message);
        $entityManager->flush();

        return $this->json([
            'message' => 'Message envoyé avec succès',
            'data' => $this->formatMessage($message)
        ], Response::HTTP_CREATED);
    }

    // 2. Mes messages
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(
        MessageRepository $messageRepository,
        Request $request
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $limit = $request->query->get('limit', 50);
        $offset = $request->query->get('offset', 0);

        $messages = $messageRepository->findByUtilisateur($user->getId(), $limit, $offset);

        $result = [];
        foreach ($messages as $message) {
            $result[] = $this->formatMessage($message);
        }

        return $this->json([
            'data' => $result,
            'pagination' => [
                'limit' => (int) $limit,
                'offset' => (int) $offset,
                'total' => count($messages)
            ]
        ]);
    }

    // 3. Conversation avec un utilisateur
    #[Route('/conversation/{userId}', name: 'conversation', methods: ['GET'])]
    public function conversation(
        int $userId,
        MessageRepository $messageRepository,
        UtilisateurRepository $utilisateurRepository,
        Request $request
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $autreUser = $utilisateurRepository->find($userId);
        if (!$autreUser) {
            return $this->json(['error' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $limit = $request->query->get('limit', 50);
        $offset = $request->query->get('offset', 0);

        $messages = $messageRepository->findConversation(
            $user->getId(),
            $userId,
            $limit,
            $offset
        );

        // Marquer les messages comme lus
        foreach ($messages as $message) {
            if ($message->getDestinataire()->getId() === $user->getId() && !$message->isEstLu()) {
                $message->setEstLu(true);
                $message->setDateLu(new \DateTimeImmutable());
            }
        }

        $entityManager = $messageRepository->getEntityManager();
        $entityManager->flush();

        $result = [];
        foreach ($messages as $message) {
            $result[] = $this->formatMessage($message);
        }

        return $this->json([
            'data' => $result,
            'pagination' => [
                'limit' => (int) $limit,
                'offset' => (int) $offset,
                'total' => count($messages)
            ]
        ]);
    }

    // 4. Messages non lus
    #[Route('/non-lus', name: 'non_lus', methods: ['GET'])]
    public function getNonLus(MessageRepository $messageRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $messages = $messageRepository->findNonLusByUtilisateur($user->getId());
        $count = count($messages);

        $result = [];
        foreach ($messages as $message) {
            $result[] = $this->formatMessage($message);
        }

        return $this->json([
            'count' => $count,
            'messages' => $result
        ]);
    }

    // 5. Marquer un message comme lu
    #[Route('/{id}/lu', name: 'mark_read', methods: ['PUT'])]
    public function marquerLu(
        Message $message,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        // Vérifier que l'utilisateur est le destinataire
        if ($message->getDestinataire()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Vous n\'êtes pas autorisé'], Response::HTTP_FORBIDDEN);
        }

        $message->setEstLu(true);
        $message->setDateLu(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->json([
            'message' => 'Message marqué comme lu',
            'data' => $this->formatMessage($message)
        ]);
    }

    // 6. Nombre de messages non lus
    #[Route('/non-lus/count', name: 'count_non_lus', methods: ['GET'])]
    public function countNonLus(MessageRepository $messageRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        $count = $messageRepository->countNonLusByUtilisateur($user->getId());

        return $this->json(['count' => $count]);
    }

    // 7. Supprimer un message
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(
        Message $message,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }

        // Vérifier que l'utilisateur est l'expéditeur
        if ($message->getExpediteur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Vous n\'êtes pas autorisé'], Response::HTTP_FORBIDDEN);
        }

        $entityManager->remove($message);
        $entityManager->flush();

        return $this->json(['message' => 'Message supprimé avec succès']);
    }

    private function formatMessage(Message $message): array
    {
        return [
            'id' => $message->getId(),
            'contenu' => $message->getContenu(),
            'typeMessage' => $message->getTypeMessage(),
            'estLu' => $message->isEstLu(),
            'dateEnvoi' => $message->getDateEnvoi()?->format('Y-m-d H:i:s'),
            'dateLu' => $message->getDateLu()?->format('Y-m-d H:i:s'),
            'expediteur' => [
                'id' => $message->getExpediteur()->getId(),
                'nom' => $message->getExpediteur()->getNom(),
                'prenom' => $message->getExpediteur()->getPrenom(),
                'photo' => $message->getExpediteur()->getPhoto()
            ],
            'destinataire' => [
                'id' => $message->getDestinataire()->getId(),
                'nom' => $message->getDestinataire()->getNom(),
                'prenom' => $message->getDestinataire()->getPrenom(),
                'photo' => $message->getDestinataire()->getPhoto()
            ]
        ];
    }
}