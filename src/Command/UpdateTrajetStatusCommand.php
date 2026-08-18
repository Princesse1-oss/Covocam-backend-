<?php

namespace App\Command;

use App\Entity\Trajet;
use App\Service\NotificationService;
use App\Service\TrajetLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:update-trajet-status')]
class UpdateTrajetStatusCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TrajetLifecycleService $lifecycle,
        private NotificationService $notificationService,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Passe les trajets à venir en EN_ATTENTE_DEPART 30 minutes avant le départ et notifie');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info('Démarrage de la commande app:update-trajet-status');
        $count = 0;
        $expired = 0;
        $notifies = 0;

        try {
            // Fenêtre de départ : OUVERT / COMPLET → EN_ATTENTE_DEPART quand le départ est dans ≤ 30 min
            $seuil = (new \DateTime())->modify('+30 minutes');
            $trajetRepository = $this->entityManager->getRepository(Trajet::class);

            $trajets = $trajetRepository->createQueryBuilder('t')
                ->where('t.statut IN (:statuts)')
                ->setParameter('statuts', [TrajetLifecycleService::STATUT_OUVERT, TrajetLifecycleService::STATUT_COMPLET])
                ->andWhere('t.dateDepart IS NOT NULL')
                ->andWhere('t.dateDepart <= :seuil')
                ->setParameter('seuil', $seuil)
                ->getQuery()
                ->getResult();

            foreach ($trajets as $trajet) {
                try {
                    if (!$this->lifecycle->passerEnAttenteDepart($trajet)) {
                        continue;
                    }

                    // Notifier le conducteur
                    $conducteur = $trajet->getConducteur();
                    if ($conducteur) {
                        $this->notificationService->notifier(
                            $conducteur,
                            '🚗 Départ imminent',
                            sprintf('Votre trajet %s → %s démarre dans moins de 30 minutes.', $trajet->getVilleDepart(), $trajet->getVilleArrivee()),
                            'depart_imminent',
                            $trajet,
                            null,
                            '/conducteur/trajets/' . $trajet->getId() . '/demarrer',
                            '🚗',
                            '#0D9E7E'
                        );
                    }

                    // Notifier les passagers confirmés
                    foreach ($trajet->getReservations() as $reservation) {
                        if (!in_array($reservation->getStatut(), ['CONFIRMEE', 'A_PAYER'], true)) {
                            continue;
                        }
                        $passager = $reservation->getPassager();
                        if ($passager) {
                            $this->notificationService->notifier(
                                $passager,
                                '🚗 Votre trajet arrive bientôt',
                                sprintf('Votre trajet %s → %s démarre dans moins de 30 minutes. Soyez ponctuel !', $trajet->getVilleDepart(), $trajet->getVilleArrivee()),
                                'depart_imminent',
                                $trajet,
                                $reservation,
                                '/passager/reservations',
                                '⏰',
                                '#f59e0b'
                            );
                        }
                    }

                    $count++;
                } catch (\Exception $e) {
                    $this->logger->error('Erreur traitement trajet imminent #{trajet_id}: {error}', [
                        'trajet_id' => $trajet->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Expiration paresseuse des trajets manqués (départ passé de plus de 24h sans démarrage)
            $expires = $trajetRepository->createQueryBuilder('t')
                ->where('t.statut IN (:statuts)')
                ->setParameter('statuts', [
                    TrajetLifecycleService::STATUT_OUVERT,
                    TrajetLifecycleService::STATUT_COMPLET,
                    TrajetLifecycleService::STATUT_EN_ATTENTE_DEPART,
                ])
                ->andWhere('t.dateDepart IS NOT NULL')
                ->andWhere('t.dateDepart < :limite')
                ->setParameter('limite', (new \DateTime())->modify('-24 hours'))
                ->getQuery()
                ->getResult();

            foreach ($expires as $trajet) {
                try {
                    if ($this->lifecycle->annuler($trajet)) {
                        $expired++;
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Erreur annulation trajet expiré #{trajet_id}: {error}', [
                        'trajet_id' => $trajet->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Notification "départ passé sans signal"
            $notificationRepository = $this->entityManager->getRepository(\App\Entity\Notification::class);
            $sansSignal = $trajetRepository->createQueryBuilder('t')
                ->where('t.statut IN (:statuts)')
                ->setParameter('statuts', [
                    TrajetLifecycleService::STATUT_OUVERT,
                    TrajetLifecycleService::STATUT_COMPLET,
                    TrajetLifecycleService::STATUT_EN_ATTENTE_DEPART,
                ])
                ->andWhere('t.dateDepart IS NOT NULL')
                ->andWhere('t.dateDepart < :maintenant')
                ->setParameter('maintenant', new \DateTime())
                ->getQuery()
                ->getResult();

            foreach ($sansSignal as $trajet) {
                foreach ($trajet->getReservations() as $reservation) {
                    if (!in_array($reservation->getStatut(), ['CONFIRMEE', 'A_PAYER'], true)) {
                        continue;
                    }
                    $passager = $reservation->getPassager();
                    if (!$passager) {
                        continue;
                    }
                    if ($notificationRepository->existsByTrajetPassagerType($trajet->getId(), $passager->getId(), 'depart_non_signe')) {
                        continue;
                    }
                    try {
                        $this->notificationService->notifier(
                            $passager,
                            '⏰ L\'heure de départ est passée',
                            sprintf('Votre trajet %s → %s devait partir à %s mais le conducteur n\'a pas encore signalé le début. Contactez-le pour confirmer.', $trajet->getVilleDepart(), $trajet->getVilleArrivee(), $trajet->getHeureDepart()?->format('H:i') ?: ''),
                            'depart_non_signe',
                            $trajet,
                            $reservation,
                            '/passager/trajets/' . $trajet->getId() . '/suivi',
                            '⏰',
                            '#d97706'
                        );
                        $notifies++;
                    } catch (\Exception $e) {
                        $this->logger->error('Erreur notification depart_non_signe: {error}', [
                            'trajet_id' => $trajet->getId(),
                            'passager_id' => $passager->getId(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $this->entityManager->flush();
        } catch (\Exception $e) {
            $this->logger->critical('Erreur fatale dans app:update-trajet-status: {error}', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $output->writeln('<error>Erreur fatale : ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '%d trajet(s) passé(s) en attente de départ, %d trajet(s) expiré(s), %d passager(s) notifié(s) (départ passé sans signal).',
            $count,
            $expired,
            $notifies
        ));

        return Command::SUCCESS;
    }
}
