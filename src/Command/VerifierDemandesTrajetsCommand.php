<?php

namespace App\Command;

use App\Entity\DemandeTrajet;
use App\Entity\Utilisateur;
use App\Entity\Notification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:verifier-demandes-trajets',
    description: 'Gère l\'expiration des demandes de trajets et les pénalités',
)]
class VerifierDemandesTrajetsCommand extends Command
{
    private int $nbExpirees = 0;
    private int $nbPenalites = 0;

    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTime();
        
        $io->title('📋 Vérification des demandes de trajets');
        $io->info("Heure actuelle : " . $now->format('Y-m-d H:i:s'));

        try {
            $this->expirerDemandes($io, $now);
            $this->gererPenalitesAnnulations($io, $now);
            
            $this->em->flush();

            $io->success(sprintf(
                '✅ %d demande(s) expirée(s), %d pénalité(s) appliquée(s).',
                $this->nbExpirees,
                $this->nbPenalites
            ));
            
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Erreur : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    // ═══════════════════════════════════════════════════════
    // 1️⃣ Expirer les demandes non répondues
    // ═══════════════════════════════════════════════════════
    private function expirerDemandes(SymfonyStyle $io, \DateTime $now): void
    {
        $demandesAExpirer = $this->em->getRepository(DemandeTrajet::class)
            ->createQueryBuilder('d')
            ->where('d.statut = :statut')
            ->andWhere('d.dateExpiration <= :now')
            ->setParameter('statut', 'EN_ATTENTE')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        foreach ($demandesAExpirer as $demande) {
            try {
                $demande->setStatut('EXPIREE');
                
                // Notifier le passager
                $notif = new Notification();
                $notif->setTitre('⏰ Votre demande a expiré');
                $notif->setMessage(sprintf(
                    'Votre demande %s → %s du %s n\'a reçu aucune réponse et a expiré.',
                    $demande->getVilleDepart(),
                    $demande->getVilleArrivee(),
                    $demande->getDateDepart()?->format('d/m/Y')
                ));
                $notif->setType('demande_expiree');
                $notif->setDestinataire($demande->getPassager());
                $notif->setIcone('⏰');
                $notif->setCouleur('#F59E0B');
                $notif->setUrl('/passager/demandes');
                $this->em->persist($notif);

                $this->nbExpirees++;
                $io->writeln("  ⏰ Demande #" . $demande->getId() . " expirée");
            } catch (\Throwable $e) {
                $io->warning("  ⚠️ Erreur demande #" . $demande->getId());
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // 2️⃣ Gérer les pénalités pour annulations répétées
    // ═══════════════════════════════════════════════════════
    private function gererPenalitesAnnulations(SymfonyStyle $io, \DateTime $now): void
    {
        $unMoisArriere = (clone $now)->modify('-30 days');
        
        // Trouver les passagers qui ont annulé 3+ fois ce mois
        $passagersProblem = $this->em->getRepository(DemandeTrajet::class)
            ->createQueryBuilder('d')
            ->select('d.passager, COUNT(d.id) as nbAnnulations')
            ->where('d.statut = :statut')
            ->andWhere('d.dateCreation >= :unMois')
            ->setParameter('statut', 'ANNULEE')
            ->setParameter('unMois', $unMoisArriere)
            ->groupBy('d.passager')
            ->having('COUNT(d.id) >= 3')
            ->getQuery()
            ->getResult();

        foreach ($passagersProblem as $row) {
            $passager = $row['passager'];
            $nbAnnulations = $row['nbAnnulations'];
            
            if (!$passager instanceof Utilisateur) continue;

            // ✅ Vérifier si le passager n'est pas déjà bloqué
            if ($passager->isEstActif() === false) continue;

            // ✅ Bloquer le passager pour 7 jours
            $passager->setEstActif(false);
            $passager->setDateDeblocage((clone $now)->modify('+7 days'));
            
            // Notifier le passager
            $notif = new Notification();
            $notif->setTitre('⚠️ Compte temporairement suspendu');
            $notif->setMessage(sprintf(
                'Vous avez annulé %d demandes ce mois-ci. Votre compte est suspendu pour 7 jours pour prévenir les abus. Vous pourrez toujours vous connecter et consulter vos trajets.',
                $nbAnnulations
            ));
            $notif->setType('penalite_annulation');
            $notif->setDestinataire($passager);
            $notif->setIcone('⚠️');
            $notif->setCouleur('#DC2626');
            $notif->setUrl('/passager/profil');
            $this->em->persist($notif);

            $this->nbPenalites++;
            $io->writeln("  ⚠️ Passager #" . $passager->getId() . " bloqué (3+ annulations)");
        }
    }
}
