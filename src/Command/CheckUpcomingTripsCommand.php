<?php

namespace App\Command;

use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\Trajet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:check-upcoming-trips',
    description: 'Vérifie les trajets du jour et envoie les notifications (30 min avant et au départ)',
)]
class CheckUpcomingTripsCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTime();
        
        // Début et fin de la journée d'aujourd'hui
        $todayStart = (clone $now)->setTime(0, 0, 0);
        $todayEnd = (clone $now)->setTime(23, 59, 59);

        // Récupérer tous les trajets d'aujourd'hui qui sont encore OUVERTS ou COMPLETS
        $trajets = $this->em->getRepository(Trajet::class)->createQueryBuilder('t')
            ->where('t.dateDepart >= :start')
            ->andWhere('t.dateDepart <= :end')
            ->andWhere('t.statut IN (:statuts)')
            ->setParameter('start', $todayStart)
            ->setParameter('end', $todayEnd)
            ->setParameter('statuts', ['OUVERT', 'COMPLET'])
            ->getQuery()
            ->getResult();

        $io->info(sprintf('Vérification de %d trajet(s) prévu(s) aujourd\'hui.', count($trajets)));

        foreach ($trajets as $trajet) {
            $dateDepart = $trajet->getDateDepart();
            // Différence en secondes entre maintenant et le départ
            $diffSeconds = $now->getTimestamp() - $dateDepart->getTimestamp();

            // ─── CAS 1 : 30 minutes AVANT le départ (entre -1800s et 0s) ───
            if ($diffSeconds >= -1800 && $diffSeconds < 0) {
                // Vérifier si on a déjà envoyé cette notif pour éviter le spam
                $notifExists = $this->em->getRepository(Notification::class)->findOneBy([
                    'trajet' => $trajet,
                    'type' => 'rappel_30min'
                ]);

                if (!$notifExists) {
                    $reservations = $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
                        ->where('r.trajet = :trajet')
                        ->andWhere('r.statut IN (:statuts)')
                        ->setParameter('trajet', $trajet)
                        ->setParameter('statuts', ['CONFIRMEE', 'A_PAYER'])
                        ->getQuery()
                        ->getResult();

                    foreach ($reservations as $res) {
                        $passager = $res->getPassager();
                        $notif = new Notification();
                        $notif->setTitre('⏰ Votre trajet arrive dans 30 minutes !');
                        $notif->setMessage(sprintf(
                            'Préparez-vous ! Votre trajet de %s à %s avec %s %s part dans 30 minutes. Rendez-vous au point de rencontre.',
                            $trajet->getVilleDepart(),
                            $trajet->getVilleArrivee(),
                            $trajet->getConducteur()->getPrenom(),
                            $trajet->getConducteur()->getNom()
                        ));
                        $notif->setType('rappel_30min');
                        $notif->setDestinataire($passager);
                        $notif->setTrajet($trajet);
                        $notif->setReservation($res);
                        $notif->setIcone('⏰');
                        $notif->setCouleur('#F59E0B');
                        $notif->setUrl('/passager/reservations/' . $res->getId());
                        
                        $this->em->persist($notif);
                    }
                    $this->em->flush();
                    $io->success('Notification 30 min envoyée pour le trajet #' . $trajet->getId());
                }
            }

            // ─── CAS 2 : L'heure du départ est ARRIVÉE (diff >= 0) ───
            if ($diffSeconds >= 0 && $trajet->getStatut() !== 'EN_COURS') {
                // 1. Changer le statut du trajet
                $trajet->setStatut('EN_COURS');
                
                // 2. Vérifier les réservations
                $reservationsActives = $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
                    ->where('r.trajet = :trajet')
                    ->andWhere('r.statut IN (:statuts)')
                    ->setParameter('trajet', $trajet)
                    ->setParameter('statuts', ['CONFIRMEE', 'A_PAYER'])
                    ->getQuery()
                    ->getResult();

                if (count($reservationsActives) === 0) {
                    // Aucun passager : avertir le conducteur
                    $notifConducteur = new Notification();
                    $notifConducteur->setTitre('ℹ️ Départ imminent');
                    $notifConducteur->setMessage('Aucune réservation n\'est prévue pour ce trajet. Vous pouvez partir sans problème et en toute tranquillité !');
                    $notifConducteur->setType('info_depart_vide');
                    $notifConducteur->setDestinataire($trajet->getConducteur());
                    $notifConducteur->setTrajet($trajet);
                    $notifConducteur->setIcone('🚗');
                    $notifConducteur->setCouleur('#0D9E7E');
                    $notifConducteur->setUrl('/conducteur/trajets/' . $trajet->getId() . '/demarrer');
                    
                    $this->em->persist($notifConducteur);
                    $io->warning('Conducteur averti : Trajet #' . $trajet->getId() . ' sans réservation.');
                } else {
                    $io->success('Trajet #' . $trajet->getId() . ' passé en statut EN_COURS avec ' . count($reservationsActives) . ' passager(s).');
                }
                
                $this->em->flush();
            }
        }

        $io->success('Vérification des trajets terminée.');
        return Command::SUCCESS;
    }
}