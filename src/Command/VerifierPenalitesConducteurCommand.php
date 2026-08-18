<?php

namespace App\Command;

use App\Entity\Notification;
use App\Entity\Trajet;
use App\Entity\Utilisateur;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:verifier-penalites-conducteur',
    description: 'Vérifie les annulations de conducteurs et applique les pénalités / réactivations',
)]
class VerifierPenalitesConducteurCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private EmailService $emailService,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTime();

        $io->title('🚦 Vérification des pénalités conducteur');
        $io->info("Heure actuelle : " . $now->format('Y-m-d H:i:s'));

        $nbSuspensions = 0;
        $nbReactivations = 0;

        try {
            // ═══════════════════════════════════════════════════════
            // 1️⃣ Suspendre les conducteurs avec 3+ annulations
            // ═══════════════════════════════════════════════════════
            $trenteJours = (clone $now)->modify('-30 days');
            $seuilDepart = (clone $now)->modify('+24 hours');

            $conducteursProblem = $this->em->getRepository(Trajet::class)
                ->createQueryBuilder('t')
                ->select('IDENTITY(t.conducteur) as conducteurId, COUNT(t.id) as nbAnnulations')
                ->where('t.statut = :statut')
                ->andWhere('t.dateDepart >= :trenteJours')
                ->andWhere('t.dateDepart <= :seuilDepart')
                ->setParameter('statut', 'ANNULE')
                ->setParameter('trenteJours', $trenteJours)
                ->setParameter('seuilDepart', $seuilDepart)
                ->groupBy('t.conducteur')
                ->having('COUNT(t.id) >= 3')
                ->getQuery()
                ->getResult();

            foreach ($conducteursProblem as $row) {
                try {
                    $conducteur = $this->em->getRepository(Utilisateur::class)->find($row['conducteurId']);
                    $nbAnnulations = (int) $row['nbAnnulations'];

                    if (!$conducteur instanceof Utilisateur) {
                        continue;
                    }

                    // Ne pas re-suspendre un conducteur déjà inactif
                    if ($conducteur->isEstActif() === false) {
                        continue;
                    }

                    // Suspendre
                    $conducteur->setEstActif(false);
                    $conducteur->setDateDeblocage((clone $now)->modify('+7 days'));

                    // Notification in-app
                    $notif = new Notification();
                    $notif->setTitre('⚠️ Compte conducteur temporairement suspendu');
                    $notif->setMessage(sprintf(
                        "⚠️ Votre compte conducteur a été temporairement suspendu.\nRaison : Vous avez annulé %d trajet(s) au cours des 30 derniers jours.\nDurée de la suspension : 7 jours.\nPendant cette période, vous ne pourrez plus publier de trajets.\nVous pouvez toujours vous connecter et consulter vos informations.",
                        $nbAnnulations
                    ));
                    $notif->setType('penalite_annulation_conducteur');
                    $notif->setDestinataire($conducteur);
                    $notif->setIcone('⚠️');
                    $notif->setCouleur('#DC2626');
                    $notif->setUrl('/conducteur/profil');
                    $this->em->persist($notif);

                    // Email
                    try {
                        $this->emailService->sendDriverSuspensionEmail(
                            $conducteur->getEmail(),
                            $conducteur->getPrenom(),
                            $nbAnnulations
                        );
                    } catch (\Throwable $e) {
                        $this->logger->warning('Erreur envoi email suspension conducteur #{id}: {error}', [
                            'id' => $conducteur->getId(),
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $nbSuspensions++;
                    $io->writeln("  ⚠️ Conducteur #" . $conducteur->getId() . " suspendu (" . $nbAnnulations . " annulations)");
                } catch (\Throwable $e) {
                    $this->logger->error('Erreur traitement suspension conducteur: {error}', [
                        'error' => $e->getMessage(),
                    ]);
                    $io->warning("  ❌ Erreur lors du traitement d'un conducteur");
                }
            }

            // ═══════════════════════════════════════════════════════
            // 2️⃣ Réactiver les conducteurs dont la suspension est expirée
            // ═══════════════════════════════════════════════════════
            $conducteursASuspendre = $this->em->getRepository(Utilisateur::class)
                ->createQueryBuilder('u')
                ->where('u.estActif = false')
                ->andWhere('u.dateDeblocage <= :now')
                ->andWhere('u.typeUtilisateur = :type')
                ->setParameter('now', $now)
                ->setParameter('type', 'conducteur')
                ->getQuery()
                ->getResult();

            foreach ($conducteursASuspendre as $conducteur) {
                try {
                    $conducteur->setEstActif(true);
                    $conducteur->setDateDeblocage(null);

                    // Notification in-app
                    $notif = new Notification();
                    $notif->setTitre('✅ Compte conducteur réactivé');
                    $notif->setMessage(
                        "✅ Votre compte conducteur a été réactivé !\nVous pouvez à nouveau publier des trajets et recevoir des réservations.\nMerci de respecter notre charte de qualité envers les passagers."
                    );
                    $notif->setType('compte_conducteur_reactive');
                    $notif->setDestinataire($conducteur);
                    $notif->setIcone('✅');
                    $notif->setCouleur('#16A34A');
                    $notif->setUrl('/conducteur');
                    $this->em->persist($notif);

                    // Email
                    try {
                        $this->emailService->sendDriverReactivationEmail(
                            $conducteur->getEmail(),
                            $conducteur->getPrenom()
                        );
                    } catch (\Throwable $e) {
                        $this->logger->warning('Erreur envoi email réactivation conducteur #{id}: {error}', [
                            'id' => $conducteur->getId(),
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $nbReactivations++;
                    $io->writeln("  ✅ Conducteur #" . $conducteur->getId() . " réactivé");
                } catch (\Throwable $e) {
                    $this->logger->error('Erreur réactivation conducteur: {error}', [
                        'error' => $e->getMessage(),
                    ]);
                    $io->warning("  ❌ Erreur lors de la réactivation d'un conducteur");
                }
            }

            $this->em->flush();

            $io->success(sprintf(
                '✅ %d suspension(s) appliquée(s), %d réactivation(s).',
                $nbSuspensions,
                $nbReactivations
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->critical('Erreur fatale dans app:verifier-penalites-conducteur: {error}', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $io->error('Erreur fatale : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
