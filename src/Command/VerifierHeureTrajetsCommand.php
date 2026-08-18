<?php

namespace App\Command;

use App\Entity\Trajet;
use App\Entity\Reservation;
use App\Entity\Notification;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:verifier-heure-trajets',
    description: 'Vérifie l\'heure des trajets et change automatiquement leurs statuts',
)]
class VerifierHeureTrajetsCommand extends Command
{
    private int $nbModifs = 0;
    private int $nbNotifs = 0;
    private int $nbErreurs = 0;

    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTime();
        
        $io->title('🕐 Vérification automatique des trajets');
        $io->info("Heure actuelle : " . $now->format('Y-m-d H:i:s'));
        $io->newLine();

        try {
            $this->passerEnAttenteDepart($io, $now);
            $this->passerEnCours($io, $now);
            $this->passerEnAttenteValidation($io, $now);
            $this->gererTrajetsBrouillonExpires($io, $now);
            $this->terminerTrajetsTropAnciens($io, $now);
            $this->debloquerPassagers($io, $now);
            
            $this->em->flush();

            $io->newLine();
            $io->success(sprintf(
                '✅ Vérification terminée : %d trajet(s) mis à jour, %d notification(s) envoyée(s), %d erreur(s).',
                $this->nbModifs,
                $this->nbNotifs,
                $this->nbErreurs
            ));
            
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Erreur fatale : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    // ═══════════════════════════════════════════════════════
    // 1️⃣ OUVERT/COMPLET → EN_ATTENTE_DEPART ou EN_COURS
    // ═══════════════════════════════════════════════════════
    private function passerEnAttenteDepart(SymfonyStyle $io, \DateTime $now): void
    {
        $seuil = (clone $now)->modify('+30 minutes');
        
        $trajets = $this->em->getRepository(Trajet::class)
            ->createQueryBuilder('t')
            ->where('t.statut IN (:statuts)')
            ->setParameter('statuts', ['OUVERT', 'COMPLET'])
            ->getQuery()
            ->getResult();

        $trajets = array_filter($trajets, function (Trajet $t) use ($now, $seuil) {
            if (!$t->getDateDepart() || !$t->getHeureDepart()) return false;
            $dateTimeDepart = (clone $t->getDateDepart())->setTime(
                (int) $t->getHeureDepart()->format('H'),
                (int) $t->getHeureDepart()->format('i'),
                0
            );
            return $dateTimeDepart <= $seuil;
        });

        foreach ($trajets as $trajet) {
            try {
                $notifExistante = $this->em->getRepository(Notification::class)->findOneBy([
                    'trajet' => $trajet,
                    'type' => 'rappel_depart_30min',
                    'destinataire' => $trajet->getConducteur()
                ]);

                if (!$notifExistante) {
                    $this->creerNotification(
                        $trajet->getConducteur(),
                        '⏰ Départ dans 30 minutes',
                        sprintf('Votre trajet %s → %s démarre dans 30 minutes.', $trajet->getVilleDepart(), $trajet->getVilleArrivee()),
                        'rappel_depart_30min',
                        $trajet,
                        '⏰',
                        '#F59E0B',
                        '/conducteur/trajets/' . $trajet->getId() . '/carte-ramassage'
                    );
                }

                $dateTimeDepart = (clone $trajet->getDateDepart())->setTime(
                    (int) $trajet->getHeureDepart()->format('H'),
                    (int) $trajet->getHeureDepart()->format('i'),
                    0
                );

                if ($dateTimeDepart <= $now) {
                    $trajet->setStatut('EN_COURS');
                    $this->nbModifs++;
                    $io->writeln("  🚗 Trajet #" . $trajet->getId() . " → EN_COURS (départ déjà passé)");
                } else {
                    $trajet->setStatut('EN_ATTENTE_DEPART');
                    $this->nbModifs++;
                    $io->writeln("  ✅ Trajet #" . $trajet->getId() . " → EN_ATTENTE_DEPART");
                }
            } catch (\Throwable $e) {
                $this->nbErreurs++;
                $io->warning("  ⚠️ Erreur trajet #" . $trajet->getId() . " : " . $e->getMessage());
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // 2️⃣ EN_ATTENTE_DEPART → EN_COURS (à l'heure de départ)
    // ═══════════════════════════════════════════════════════
    private function passerEnCours(SymfonyStyle $io, \DateTime $now): void
    {
        $trajets = $this->em->getRepository(Trajet::class)
            ->createQueryBuilder('t')
            ->where('t.statut = :statut')
            ->setParameter('statut', 'EN_ATTENTE_DEPART')
            ->getQuery()
            ->getResult();

        $trajets = array_filter($trajets, function (Trajet $t) use ($now) {
            if (!$t->getDateDepart() || !$t->getHeureDepart()) return true;
            $dateTimeDepart = (clone $t->getDateDepart())->setTime(
                (int) $t->getHeureDepart()->format('H'),
                (int) $t->getHeureDepart()->format('i'),
                0
            );
            return $dateTimeDepart <= $now;
        });

        foreach ($trajets as $trajet) {
            try {
                $notifExistante = $this->em->getRepository(Notification::class)->findOneBy([
                    'trajet' => $trajet,
                    'type' => 'trajet_demarre',
                    'destinataire' => $trajet->getConducteur()
                ]);

                if (!$notifExistante) {
                    $this->creerNotification(
                        $trajet->getConducteur(),
                        '🚗 Trajet démarré',
                        'Activez votre GPS pour que les passagers puissent vous suivre.',
                        'trajet_demarre',
                        $trajet,
                        '🚗',
                        '#0D9E7E',
                        '/conducteur/trajets/' . $trajet->getId() . '/demarrer'
                    );
                }

                $trajet->setStatut('EN_COURS');
                $this->nbModifs++;
                $io->writeln("  🚗 Trajet #" . $trajet->getId() . " → EN_COURS");
            } catch (\Throwable $e) {
                $this->nbErreurs++;
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // 3️⃣ EN_COURS → EN_ATTENTE_VALIDATION (heure d'arrivée)
    // ═══════════════════════════════════════════════════════
    private function passerEnAttenteValidation(SymfonyStyle $io, \DateTime $now): void
    {
        $trajets = $this->em->getRepository(Trajet::class)
            ->createQueryBuilder('t')
            ->where('t.statut = :statut')
            ->setParameter('statut', 'EN_COURS')
            ->getQuery()
            ->getResult();

        foreach ($trajets as $trajet) {
            try {
                // ✅ AMÉLIORATION : Si pas d'heure d'arrivée estimée, on calcule automatiquement
                // (départ + 2h par défaut pour les trajets inter-villes)
                $heureArrivee = clone $trajet->getDateDepart();
                $hArrivee = $trajet->getHeureArriveeEstimee();
                
                if ($hArrivee) {
                    $heureArrivee->setTime(
                        (int)$hArrivee->format('H'),
                        (int)$hArrivee->format('i'),
                        0
                    );
                } else {
                    // ✅ Fallback : on ajoute 2h à la date de départ
                    $heureArrivee->modify('+2 hours');
                }

                if ($heureArrivee <= $now) {
                    $notifExistante = $this->em->getRepository(Notification::class)->findOneBy([
                        'trajet' => $trajet,
                        'type' => 'arrivee_destination',
                        'destinataire' => $trajet->getConducteur()
                    ]);

                    if (!$notifExistante) {
                        $this->creerNotification(
                            $trajet->getConducteur(),
                            '🏁 Arrivée à destination',
                            'Validez la présence de vos passagers pour terminer le trajet.',
                            'arrivee_destination',
                            $trajet,
                            '🏁',
                            '#DC2626',
                            '/conducteur/trajets/' . $trajet->getId() . '/presence'
                        );
                    }

                    $trajet->setStatut('EN_ATTENTE_VALIDATION');
                    $this->nbModifs++;
                    $io->writeln("  🏁 Trajet #" . $trajet->getId() . " → EN_ATTENTE_VALIDATION");
                }
            } catch (\Throwable $e) {
                $this->nbErreurs++;
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // 4️⃣ NOUVEAU : Gérer les trajets BROUILLON non complétés (24h)
    // ═══════════════════════════════════════════════════════
    private function gererTrajetsBrouillonExpires(SymfonyStyle $io, \DateTime $now): void
    {
        $seuil = (clone $now)->modify('-24 hours');
        
        $brouillonsExpires = $this->em->getRepository(Trajet::class)
            ->createQueryBuilder('t')
            ->where('t.statut = :statut')
            ->andWhere('t.createdAt <= :seuil')
            ->setParameter('statut', 'BROUILLON')
            ->setParameter('seuil', $seuil)
            ->getQuery()
            ->getResult();

        foreach ($brouillonsExpires as $trajet) {
            try {
                // On supprime le trajet brouillon
                $this->em->remove($trajet);
                $this->nbModifs++;
                $io->writeln("  🗑️ Trajet brouillon #" . $trajet->getId() . " supprimé (non complété en 24h)");
            } catch (\Throwable $e) {
                $this->nbErreurs++;
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // 5️⃣ NOUVEAU : Sécurité - Terminer les trajets EN_COURS trop anciens
    // ═══════════════════════════════════════════════════════
    private function terminerTrajetsTropAnciens(SymfonyStyle $io, \DateTime $now): void
    {
        $seuil = (clone $now)->modify('-24 hours');
        
        $trajetsBloques = $this->em->getRepository(Trajet::class)
            ->createQueryBuilder('t')
            ->where('t.statut IN (:statuts)')
            ->andWhere('t.dateDepart <= :seuil')
            ->setParameter('statuts', ['EN_COURS', 'EN_ATTENTE_VALIDATION'])
            ->setParameter('seuil', $seuil)
            ->getQuery()
            ->getResult();

        foreach ($trajetsBloques as $trajet) {
            try {
                $trajet->setStatut('TERMINE');
                $this->nbModifs++;
                $io->writeln("  ✅ Trajet #" . $trajet->getId() . " forcé en TERMINE (sécurité)");
            } catch (\Throwable $e) {
                $this->nbErreurs++;
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // MÉTHODE AIDE : Créer une notification
    // ═══════════════════════════════════════════════════════
    private function creerNotification(
        $destinataire,
        string $titre,
        string $message,
        string $type,
        Trajet $trajet,
        string $icone,
        string $couleur,
        string $url
    ): void {
        $notif = new Notification();
        $notif->setTitre($titre);
        $notif->setMessage($message);
        $notif->setType($type);
        $notif->setDestinataire($destinataire);
        $notif->setTrajet($trajet);
        $notif->setIcone($icone);
        $notif->setCouleur($couleur);
        $notif->setUrl($url);
        $this->em->persist($notif);
        $this->nbNotifs++;
    }
        // ═══════════════════════════════════════════════════════
    // 3️⃣ Débloquer les passagers après 7 jours
    // ═══════════════════════════════════════════════════════
    private function debloquerPassagers(SymfonyStyle $io, \DateTime $now): void
    {
        $passagersADebloquer = $this->em->getRepository(Utilisateur::class)
            ->createQueryBuilder('u')
            ->where('u.estActif = :actif')
            ->andWhere('u.dateDeblocage IS NOT NULL')
            ->andWhere('u.dateDeblocage <= :now')
            ->setParameter('actif', false)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        foreach ($passagersADebloquer as $passager) {
            $passager->setEstActif(true);
            $passager->setDateDeblocage(null);
            
            $notif = new Notification();
            $notif->setTitre('✅ Compte réactivé');
            $notif->setMessage('Votre compte a été réactivé. Vous pouvez à nouveau publier des demandes de trajet.');
            $notif->setType('compte_reactive');
            $notif->setDestinataire($passager);
            $notif->setIcone('✅');
            $notif->setCouleur('#16A34A');
            $notif->setUrl('/passager/demandes');
            $this->em->persist($notif);
            
            $io->writeln("  ✅ Passager #" . $passager->getId() . " débloqué");
        }
    }
}