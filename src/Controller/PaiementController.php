<?php

namespace App\Controller;

use App\Entity\Paiement;
use App\Entity\Reservation;
use App\Entity\Notification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/paiements', name: 'api_paiements_')]
class PaiementController extends AbstractController
{
    private string $campayAppCode;
    private string $campayAppPassword;
    private float $commissionRate;
    private string $paymentMode;
    private string $adminPhone;

    public function __construct()
    {
        // On utilise les variables corrigées du .env
        $this->campayAppCode = $_ENV['CAMPAY_APP_CODE'] ?? '';
        $this->campayAppPassword = $_ENV['CAMPAY_APP_PASSWORD'] ?? '';
        $this->commissionRate = (float)($_ENV['PLATFORM_COMMISSION_RATE'] ?? 0.10);
        $this->paymentMode = $_ENV['PAYMENT_MODE'] ?? 'production';
        $this->adminPhone = $_ENV['ADMIN_PHONE'] ?? '';

        error_log("🔧 Mode de paiement actif : " . $this->paymentMode);
    }

    #[Route('/initier', name: 'initier', methods: ['POST'])]
    public function initierPaiement(Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user) return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);

            $data = json_decode($request->getContent(), true);
            
            // ✅ 1. Validation stricte et cast en entier de l'ID
            $reservationId = isset($data['reservationId']) ? (int) $data['reservationId'] : 0;
            $telephone = !empty($data['telephone']) ? trim($data['telephone']) : $user->getTelephone();

            if ($reservationId <= 0 || empty($telephone)) {
                return $this->json([
                    'error' => 'ID de réservation invalide ou numéro de téléphone manquant.'
                ], Response::HTTP_BAD_REQUEST);
            }

            $reservation = $em->getRepository(Reservation::class)->find($reservationId);
            if (!$reservation) {
                return $this->json(['error' => 'Réservation introuvable.'], Response::HTTP_NOT_FOUND);
            }

            if ($reservation->getPassager()->getId() !== $user->getId()) {
                return $this->json(['error' => 'Vous n\'êtes pas autorisé à payer cette réservation.'], Response::HTTP_FORBIDDEN);
            }

            // ✅ 2. Vérification CRUCIALE du statut avec message explicite
            if ($reservation->getStatut() !== 'A_PAYER') {
                $msg = 'Cette réservation n\'est pas en attente de paiement (Statut actuel : ' . $reservation->getStatut() . ').';
                
                if ($reservation->getStatut() === 'EN_ATTENTE') {
                    $msg .= ' Le conducteur doit d\'abord accepter votre demande.';
                } elseif ($reservation->getStatut() === 'CONFIRMEE') {
                    $msg .= ' Cette réservation est déjà payée.';
                }
                
                return $this->json(['error' => $msg], Response::HTTP_BAD_REQUEST);
            }
            $montantTotal = $reservation->getPrixTotal();
            $commission = round($montantTotal * $this->commissionRate, 0);
            $montantNet = $montantTotal - $commission;
            $externalRef = 'RES-' . $reservation->getId() . '-' . time();

            // ✅ MODE SIMULATION : On simule un paiement réussi après 3 secondes
            if ($this->paymentMode === 'simulation') {
                error_log("🎭 MODE SIMULATION ACTIVÉ - Pas d'appel à Campay");
                
                $paiement = new Paiement();
                $paiement->setReservation($reservation);
                $paiement->setCampayReference('SIM-' . uniqid());
                $paiement->setMontantTotal($montantTotal);
                $paiement->setCommission($commission);
                $paiement->setMontantNetConducteur($montantNet);
                $paiement->setStatut('REUSSI'); // ✅ Directement réussi en simulation
                $paiement->setDatePaiement(new \DateTimeImmutable());
                
                // Mettre à jour la réservation directement
                $reservation->setStatut('CONFIRMEE');
                
                // Notification au conducteur
                $this->creerNotification($em, $reservation->getTrajet()->getConducteur(), '💰 Paiement reçu', 
                    sprintf('Le passager %s %s a payé %s FCFA pour votre trajet %s → %s. Réservation confirmée !',
                        $user->getPrenom(),
                        $user->getNom(),
                        number_format($montantTotal, 0, ',', ' '),
                        $reservation->getTrajet()->getVilleDepart(),
                        $reservation->getTrajet()->getVilleArrivee()
                    ), 
                    'paiement', '#16a34a', '/conducteur/reservations', $reservation);

                $em->persist($paiement);
                $em->flush();

                return $this->json([
                    'message' => '✅ Paiement simulé avec succès !',
                    'paiement' => [
                        'id' => $paiement->getId(),
                        'campayReference' => $paiement->getCampayReference(),
                        'statut' => $paiement->getStatut()
                    ]
                ]);
            }

            // ✅ MODE PRODUCTION : Vrai appel à Campay
            error_log("🚀 Appel Campay pour la réservation: " . $reservationId);

            $campayResponse = $this->callCampayApi('/collect/', [
                'amount' => $montantTotal,
                'currency' => 'XAF',
                'from' => $telephone,
                'description' => 'Paiement trajet ' . $reservation->getTrajet()->getVilleDepart() . ' -> ' . $reservation->getTrajet()->getVilleArrivee(),
                'external_reference' => $externalRef
            ]);

            error_log("📡 Réponse Campay: " . json_encode($campayResponse));

            if (!isset($campayResponse['reference']) || ($campayResponse['status'] ?? '') === 'FAILED') {
                return $this->json([
                    'error' => 'Échec de l\'initiation Campay: ' . ($campayResponse['detail'] ?? 'Erreur inconnue de l\'API')
                ], Response::HTTP_BAD_REQUEST);
            }

            $paiement = new Paiement();
            $paiement->setReservation($reservation);
            $paiement->setCampayReference($campayResponse['reference']);
            $paiement->setMontantTotal($montantTotal);
            $paiement->setCommission($commission);
            $paiement->setMontantNetConducteur($montantNet);
            $paiement->setStatut('EN_ATTENTE');
            
            $em->persist($paiement);
            $em->flush();

            return $this->json([
                'message' => 'Veuillez valider le paiement sur votre téléphone.',
                'paiement' => [
                    'id' => $paiement->getId(),
                    'campayReference' => $paiement->getCampayReference(),
                    'statut' => $paiement->getStatut()
                ]
            ]);

        } catch (\Exception $e) {
            error_log("💥 ERREUR FATALE PAIEMENT: " . $e->getMessage());
            error_log("💥 Fichier: " . $e->getFile() . " | Ligne: " . $e->getLine());
            
            return $this->json([
                'error' => 'Erreur interne: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}/statut', name: 'verifier_statut', methods: ['GET'])]
    public function verifierStatut(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);

        $paiement = $em->getRepository(Paiement::class)->find($id);
        if (!$paiement || $paiement->getReservation()->getPassager()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Paiement non trouvé'], Response::HTTP_NOT_FOUND);
        }

        // En mode simulation, le statut est déjà REUSSI
        if (in_array($paiement->getStatut(), ['REUSSI', 'REMBOURSE'])) {
            return $this->json(['statut' => $paiement->getStatut(), 'paiement' => $this->formatPaiement($paiement)]);
        }

        // Mode production : on interroge Campay
        $campayResponse = $this->callCampayApi('/collect/' . $paiement->getCampayReference() . '/');

        if (isset($campayResponse['status']) && $campayResponse['status'] === 'SUCCESSFUL') {
            $paiement->setStatut('REUSSI');
            $paiement->setDatePaiement(new \DateTimeImmutable());
            
            $reservation = $paiement->getReservation();
            $reservation->setStatut('CONFIRMEE');
            
            $this->creerNotification($em, $reservation->getTrajet()->getConducteur(), '💰 Paiement reçu', 
                sprintf('Le passager a payé %s FCFA. Réservation confirmée !', number_format($paiement->getMontantTotal(), 0, ',', ' ')), 
                'paiement', '#16a34a', '/conducteur/reservations', $reservation);

            $em->flush();
        } elseif (isset($campayResponse['status']) && $campayResponse['status'] === 'FAILED') {
            $paiement->setStatut('ECHEC');
            $em->flush();
        }

        return $this->json(['statut' => $paiement->getStatut(), 'paiement' => $this->formatPaiement($paiement)]);
    }

    #[Route('/{id}/rembourser', name: 'rembourser', methods: ['POST'])]
    public function rembourser(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);

        $paiement = $em->getRepository(Paiement::class)->find($id);
        if (!$paiement) {
            return $this->json(['error' => 'Paiement non trouvé'], Response::HTTP_NOT_FOUND);
        }

        if ($paiement->getStatut() !== 'REUSSI') {
            return $this->json(['error' => 'Ce paiement ne peut pas être remboursé'], Response::HTTP_BAD_REQUEST);
        }

        // En mode simulation, on rembourse directement
        if ($this->paymentMode === 'simulation') {
            $paiement->setStatut('REMBOURSE');
            $paiement->setDateRemboursement(new \DateTimeImmutable());
            
            $reservation = $paiement->getReservation();
            $reservation->setStatut('REMBOURSEE');
            
            $this->creerNotification($em, $user, '💸 Remboursement effectué', 
                sprintf('Vous avez été remboursé de %s FCFA.', number_format($paiement->getMontantTotal(), 0, ',', ' ')), 
                'remboursement', '#16a34a', '/passager/reservations', $reservation);

            $em->flush();

            return $this->json(['message' => 'Remboursement simulé avec succès.']);
        }

        // Mode production : appel à Campay
        $campayResponse = $this->callCampayApi('/refund/', [
            'reference' => $paiement->getCampayReference(),
            'amount' => $paiement->getMontantTotal()
        ]);

        if (isset($campayResponse['status']) && in_array($campayResponse['status'], ['SUCCESSFUL', 'PENDING'])) {
            $paiement->setStatut('REMBOURSE');
            $paiement->setDateRemboursement(new \DateTimeImmutable());
            
            $reservation = $paiement->getReservation();
            $reservation->setStatut('REMBOURSEE');
            
            $this->creerNotification($em, $user, '💸 Remboursement effectué', 
                sprintf('Vous avez été remboursé de %s FCFA.', number_format($paiement->getMontantTotal(), 0, ',', ' ')), 
                'remboursement', '#16a34a', '/passager/reservations', $reservation);

            $em->flush();

            return $this->json(['message' => 'Remboursement Campay initié avec succès.']);
        }

        return $this->json(['error' => 'Échec du remboursement: ' . ($campayResponse['detail'] ?? 'Erreur inconnue')], Response::HTTP_BAD_REQUEST);
    }

    private function callCampayApi(string $endpoint, array $payload = []): array
    {
        // ✅ URL DE PRODUCTION CAMPAY (Débite de l'argent réel)
        // Note: Si tu veux encore tester sans argent réel, remplace par 'https://demo.campay.net/api'
         $url = 'https://campay.net/api' . $endpoint;

        error_log("🌐 Appel cURL vers (PROD): " . $url);
        error_log("📦 Payload: " . json_encode($payload));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        // ✅ AUTHENTIFICATION CORRECTE : APP_CODE : APP_PASSWORD
        $authString = $this->campayAppCode . ':' . $this->campayAppPassword;
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($authString)
        ]);
        
        // ✅ TIMEOUT : 30 secondes pour laisser le temps au client de taper son code USSD
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        error_log("📡 Réponse HTTP: " . $httpCode . " | Erreur cURL: " . $curlError);
        error_log("📡 Réponse brute: " . $response);

        if ($curlError) {
            return ['status' => 'FAILED', 'detail' => 'Erreur réseau: ' . $curlError];
        }

        $decoded = json_decode($response, true);
        if (!$decoded) {
            return ['status' => 'FAILED', 'detail' => 'Réponse JSON invalide de Campay'];
        }

        return $decoded;
    }

    private function creerNotification(EntityManagerInterface $em, $destinataire, string $titre, string $message, string $type, string $couleur, string $url, $reservation): void
    {
        if (!class_exists(Notification::class)) return;
        
        $notification = new Notification();
        $notification->setTitre($titre);
        $notification->setMessage($message);
        $notification->setType($type);
        $notification->setDestinataire($destinataire);
        $notification->setReservation($reservation);
        $notification->setTrajet($reservation->getTrajet());
        $notification->setCouleur($couleur);
        $notification->setUrl($url);
        $notification->setIcone($type === 'paiement' ? '💰' : '💸');
        
        $em->persist($notification);
    }

    private function formatPaiement(Paiement $p): array
    {
        return [
            'id' => $p->getId(),
            'montantTotal' => $p->getMontantTotal(),
            'commission' => $p->getCommission(),
            'montantNetConducteur' => $p->getMontantNetConducteur(),
            'statut' => $p->getStatut(),
            'datePaiement' => $p->getDatePaiement()?->format('Y-m-d H:i:s'),
        ];
    }

    #[Route('', name: 'all', methods: ['GET'])]
    public function getAll(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\Utilisateur) {
            return new JsonResponse(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }
        if (!in_array('ROLE_ADMIN', $user->getRoles())) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        try {
            // 1. On récupère tous les paiements de la base de données
            $listePaiements = $em->getRepository(\App\Entity\Paiement::class)->findAll();
            
            $resultatFinal = [];
            
            // 2. On formate les données une par une
            foreach ($listePaiements as $p) {
                $reservation = $p->getReservation();
                $passager = $reservation ? $reservation->getPassager() : null;
                $trajet = $reservation ? $reservation->getTrajet() : null;
                $conducteur = $trajet ? $trajet->getConducteur() : null;

                $nbPlaces = 0;
                if ($reservation && method_exists($reservation, 'getPlacesReservees')) {
                    $nbPlaces = $reservation->getPlacesReservees();
                } elseif ($reservation && method_exists($reservation, 'getNbPlaces')) {
                    $nbPlaces = $reservation->getNbPlaces();
                }

                $resultatFinal[] = [
                    'id' => $p->getId(),
                    'montantTotal' => $p->getMontantTotal(),
                    'commission' => $p->getCommission(),
                    'montantNet' => $p->getMontantNetConducteur(),
                    'statut' => $p->getStatut(),
                    'datePaiement' => $p->getDatePaiement() ? $p->getDatePaiement()->format('Y-m-d H:i:s') : 'En attente',
                    'campayReference' => $p->getCampayReference(),
                    'modePaiement' => 'MOBILE_MONEY',
                    'passager' => $passager ? [
                        'nom' => $passager->getNom(),
                        'prenom' => $passager->getPrenom(),
                        'email' => $passager->getEmail(),
                        'telephone' => $passager->getTelephone(), // ✅ AJOUTÉ
                        'photo' => $passager->getPhoto(),
                    ] : null,
                    'reservation' => $reservation ? [
                        'id' => $reservation->getId(),
                        'nbPlaces' => $nbPlaces,
                        'trajet' => $trajet ? [
                            'villeDepart' => $trajet->getVilleDepart(),
                            'villeArrivee' => $trajet->getVilleArrivee(),
                            'dateDepart' => $trajet->getDateDepart() ? $trajet->getDateDepart()->format('Y-m-d') : null,
                            'conducteur' => $conducteur ? [
                                'nom' => $conducteur->getNom(),
                                'prenom' => $conducteur->getPrenom(),
                                'telephone' => $conducteur->getTelephone(), 
                                'photo' => $conducteur->getPhoto(),
                            ] : null
                        ] : null
                    ] : null,
                ];
            }

            // 3. On renvoie le tableau directement
            return new JsonResponse($resultatFinal);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Erreur PHP: ' . $e->getMessage() . ' dans ' . $e->getFile() . ' ligne ' . $e->getLine()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/conducteur/mes-gains', name: 'conducteur_mes_gains', methods: ['GET'])]
    public function getMesGainsConducteur(EntityManagerInterface $em): JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user instanceof \App\Entity\Utilisateur) {
                return new JsonResponse(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
            }

            // ✅ Requête ultra-sécurisée : on trie par ID pour éviter tout problème de colonne manquante
            $paiements = $em->createQueryBuilder()
                ->select('p', 'r', 't', 'passager')
                ->from(\App\Entity\Paiement::class, 'p')
                ->join('p.reservation', 'r')
                ->join('r.trajet', 't')
                ->join('r.passager', 'passager')
                ->where('t.conducteur = :conducteur')
                ->setParameter('conducteur', $user)
                ->orderBy('p.id', 'DESC') // ✅ Tri par ID, infaillible
                ->getQuery()
                ->getResult();

            $data = [];
            $totalNet = 0.0;
            $totalCommission = 0.0;
            $totalEnAttente = 0.0;

            foreach ($paiements as $p) {
                $reservation = $p->getReservation();
                $trajet = $reservation ? $reservation->getTrajet() : null;
                $passager = $reservation ? $reservation->getPassager() : null;

                $montantNet = (float)($p->getMontantNetConducteur() ?? 0);
                $commission = (float)($p->getCommission() ?? 0);
                $statut = $p->getStatut();

                if ($statut === 'REUSSI') {
                    $totalNet += $montantNet;
                    $totalCommission += $commission;
                } elseif ($statut === 'EN_ATTENTE') {
                    $totalEnAttente += $montantNet;
                }

                // ✅ Sécurisation de la date : on vérifie que la méthode existe avant de l'appeler
                $dateAffichee = 'Inconnue';
                if (method_exists($p, 'getDatePaiement') && $p->getDatePaiement()) {
                    $dateAffichee = $p->getDatePaiement()->format('Y-m-d H:i:s');
                } elseif (method_exists($p, 'getDateCreation') && $p->getDateCreation()) {
                    $dateAffichee = $p->getDateCreation()->format('Y-m-d H:i:s');
                }

                $data[] = [
                    'id' => $p->getId(),
                    'montantTotal' => (float)($p->getMontantTotal() ?? 0),
                    'commission' => $commission,
                    'montantNet' => $montantNet,
                    'statut' => $statut,
                    'date' => $dateAffichee,
                    'campayReference' => $p->getCampayReference(),
                    'passager' => $passager ? [
                        'prenom' => $passager->getPrenom(),
                        'nom' => $passager->getNom(),
                        'photo' => $passager->getPhoto(),
                    ] : null,
                    'trajet' => $trajet ? [
                        'villeDepart' => $trajet->getVilleDepart(),
                        'villeArrivee' => $trajet->getVilleArrivee(),
                        'dateDepart' => $trajet->getDateDepart() ? $trajet->getDateDepart()->format('Y-m-d') : null,
                    ] : null,
                ];
            }

            return new JsonResponse([
                'stats' => [
                    'totalNet' => $totalNet,
                    'totalCommission' => $totalCommission,
                    'totalEnAttente' => $totalEnAttente,
                    'nombreTransactions' => count($paiements)
                ],
                'paiements' => $data
            ]);

        } catch (\Throwable $e) {
            // ✅ On loggue l'erreur pour la voir dans le terminal si ça plante encore
            error_log("💥 ERREUR getMesGainsConducteur: " . $e->getMessage() . " dans " . $e->getFile() . " ligne " . $e->getLine());
            
            return new JsonResponse([
                'error' => 'ERREUR FATALE PHP: ' . $e->getMessage(),
                'fichier' => $e->getFile(),
                'ligne' => $e->getLine()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/admin/retirer-commission', name: 'admin_retirer_commission', methods: ['POST'])]
    public function retirerCommission(Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user instanceof \App\Entity\Utilisateur) {
                return new JsonResponse(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
            }

            if (!in_array('ROLE_ADMIN', $user->getRoles())) {
                return new JsonResponse(['error' => 'Accès réservé aux administrateurs'], Response::HTTP_FORBIDDEN);
            }

            if (empty($this->adminPhone)) {
                return new JsonResponse(['error' => 'Numéro de téléphone admin non configuré'], Response::HTTP_BAD_REQUEST);
            }

            $data = json_decode($request->getContent(), true);
            $montant = $data['montant'] ?? null;

            if (!$montant || $montant <= 0) {
                return new JsonResponse(['error' => 'Montant invalide'], Response::HTTP_BAD_REQUEST);
            }

            // Calculer le total des commissions disponibles
            $totalCommissions = $em->createQueryBuilder()
                ->select('SUM(p.commission)')
                ->from(\App\Entity\Paiement::class, 'p')
                ->where('p.statut = :statut')
                ->setParameter('statut', 'REUSSI')
                ->getQuery()
                ->getSingleScalarResult() ?? 0;

            if ($montant > $totalCommissions) {
                return new JsonResponse([
                    'error' => 'Montant supérieur aux commissions disponibles',
                    'disponible' => $totalCommissions
                ], Response::HTTP_BAD_REQUEST);
            }

            // Mode simulation
            if ($this->paymentMode === 'simulation') {
                error_log("🎭 MODE SIMULATION - Retrait commission de " . $montant . " FCFA");
                return new JsonResponse([
                    'message' => '✅ Retrait simulé avec succès',
                    'montant' => $montant,
                    'telephone' => $this->adminPhone
                ]);
            }

            // Mode production : appel Campay pour transférer l'argent à l'admin
            $campayResponse = $this->callCampayApi('/withdraw/', [
                'amount' => $montant,
                'currency' => 'XAF',
                'to' => $this->adminPhone,
                'description' => 'Retrait commission plateforme CovoCam',
                'external_reference' => 'ADMIN-COMMISSION-' . time()
            ]);

            error_log("📡 Réponse Campay retrait: " . json_encode($campayResponse));

            if (!isset($campayResponse['reference']) || ($campayResponse['status'] ?? '') === 'FAILED') {
                return new JsonResponse([
                    'error' => 'Échec du retrait: ' . ($campayResponse['detail'] ?? 'Erreur inconnue')
                ], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse([
                'message' => 'Demande de retrait envoyée avec succès',
                'reference' => $campayResponse['reference'],
                'montant' => $montant,
                'telephone' => $this->adminPhone
            ]);

        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'ERREUR FATALE: ' . $e->getMessage(),
                'fichier' => $e->getFile(),
                'ligne' => $e->getLine()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/admin/commissions', name: 'admin_commissions', methods: ['GET'])]
    public function getAdminCommissions(EntityManagerInterface $em): JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user instanceof \App\Entity\Utilisateur) {
                return new JsonResponse(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
            }

            if (!in_array('ROLE_ADMIN', $user->getRoles())) {
                return new JsonResponse(['error' => 'Accès réservé aux administrateurs'], Response::HTTP_FORBIDDEN);
            }

            // Calculer le total des commissions
            $totalCommissions = $em->createQueryBuilder()
                ->select('SUM(p.commission)')
                ->from(\App\Entity\Paiement::class, 'p')
                ->where('p.statut = :statut')
                ->setParameter('statut', 'REUSSI')
                ->getQuery()
                ->getSingleScalarResult() ?? 0;

            // Récupérer les détails des paiements avec commissions
            $paiements = $em->createQueryBuilder()
                ->select('p', 'r', 't', 'passager')
                ->from(\App\Entity\Paiement::class, 'p')
                ->join('p.reservation', 'r')
                ->join('r.trajet', 't')
                ->join('r.passager', 'passager')
                ->where('p.statut = :statut')
                ->setParameter('statut', 'REUSSI')
                ->orderBy('p.datePaiement', 'DESC')
                ->getQuery()
                ->getResult();

            $data = [];
            foreach ($paiements as $p) {
                $reservation = $p->getReservation();
                $trajet = $reservation ? $reservation->getTrajet() : null;
                $passager = $reservation ? $reservation->getPassager() : null;

                $data[] = [
                    'id' => $p->getId(),
                    'commission' => $p->getCommission(),
                    'montantTotal' => $p->getMontantTotal(),
                    'datePaiement' => $p->getDatePaiement() ? $p->getDatePaiement()->format('Y-m-d H:i:s') : null,
                    'campayReference' => $p->getCampayReference(),
                    'passager' => $passager ? [
                        'nom' => $passager->getNom(),
                        'prenom' => $passager->getPrenom(),
                    ] : null,
                    'trajet' => $trajet ? [
                        'villeDepart' => $trajet->getVilleDepart(),
                        'villeArrivee' => $trajet->getVilleArrivee(),
                    ] : null,
                ];
            }

            return new JsonResponse([
                'totalCommissions' => $totalCommissions,
                'nombreTransactions' => count($paiements),
                'paiements' => $data
            ]);

        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'ERREUR FATALE: ' . $e->getMessage(),
                'fichier' => $e->getFile(),
                'ligne' => $e->getLine()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}