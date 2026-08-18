<?php

namespace App\Command;

use App\Entity\Notification;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:envoi-rappels', description: 'Envoie des notifications de rappel pour les réservations du jour')]
class EnvoiRappelsReservationsCommand extends Command
{
    // ... (le reste de ton code)
    public function __construct(
        private ReservationRepository $reservationRepository,
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $aujourdHui = new \DateTime('today 00:00:00');
        $demain = new \DateTime('today 23:59:59');

        // On cherche les réservations CONFIRMEE qui ont lieu aujourd'hui
        $reservations = $this->reservationRepository->createQueryBuilder('r')
            ->where('r.statut = :statut')
            ->andWhere('r.dateReservation >= :aujourdHui')
            ->andWhere('r.dateReservation <= :demain')
            ->setParameter('statut', 'CONFIRMEE')
            ->setParameter('aujourdHui', $aujourdHui)
            ->setParameter('demain', $demain)
            ->getQuery()
            ->getResult();

        $compteur = 0;
        foreach ($reservations as $reservation) {
            // Vérifier si on a déjà envoyé un rappel pour éviter les doublons
            $notificationExistante = $this->em->getRepository(Notification::class)->findOneBy([
                'reservation' => $reservation,
                'type' => 'RAPPEL_TRAJET'
            ]);

            if (!$notificationExistante) {
                $notification = new Notification();
                $notification->setDestinataire($reservation->getPassager());
                $notification->setTitre('Rappel de votre trajet aujourd\'hui !');
                $notification->setMessage('N\'oubliez pas votre trajet prévu aujourd\'hui. Soyez à l\'heure au point de rendez-vous.');
                $notification->setType('RAPPEL_TRAJET');
                $notification->setReservation($reservation);
                $notification->setUrl('/reservations/' . $reservation->getId()); // Pour rediriger le frontend
                $notification->setCouleur('blue');
                $notification->setIcone('car');

                $this->em->persist($notification);
                $compteur++;
            }
        }

        $this->em->flush();
        $output->writeln(sprintf('<info>%d notification(s) de rappel envoyée(s).</info>', $compteur));

        return Command::SUCCESS;
    }
}