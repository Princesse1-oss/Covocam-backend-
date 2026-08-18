<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\Trajet;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Notifications multi-canaux :
 *  1. In-app (table Notification, affichée au login et en temps réel)
 *  2. WhatsApp (lien wa.me, V1 gratuit ; le Cloud API réel se branche en V2)
 *  3. Email (mailer Symfony)
 *
 * Chaque méthode d'envoi est isolée : un échec sur un canal ne bloque jamais le reste.
 */
class NotificationService
{
    private EntityManagerInterface $em;
    private ?MailerInterface $mailer;
    private string $appName;

    public function __construct(EntityManagerInterface $em, ?MailerInterface $mailer = null, string $appName = 'CovoCam')
    {
        $this->em = $em;
        $this->mailer = $mailer;
        $this->appName = $appName;
    }

    /**
     * Notification in-app persistée.
     * Retourne la notification créée (l'appelant décide du flush).
     */
    public function notifier(
        Utilisateur $destinataire,
        string $titre,
        string $message,
        string $type,
        ?Trajet $trajet = null,
        ?Reservation $reservation = null,
        ?string $url = null,
        ?string $icone = null,
        ?string $couleur = null
    ): Notification {
        $notification = new Notification();
        $notification->setTitre($titre);
        $notification->setMessage($message);
        $notification->setType($type);
        $notification->setDestinataire($destinataire);
        $notification->setTrajet($trajet);
        $notification->setReservation($reservation);
        $notification->setUrl($url);
        $notification->setIcone($icone);
        $notification->setCouleur($couleur);

        $this->em->persist($notification);

        return $notification;
    }

    /**
     * Lien WhatsApp profond (wa.me) vers le numéro de l'utilisateur.
     * Normalise les numéros camerounais (237 + 9 chiffres).
     */
    public function lienWhatsApp(Utilisateur $destinataire, string $message): string
    {
        $telephone = preg_replace('/[^0-9]/', '', (string) $destinataire->getTelephone());
        if ($telephone === '') {
            return '';
        }

        if (!str_starts_with($telephone, '237')) {
            $telephone = '237' . ltrim($telephone, '0');
        }

        return 'https://wa.me/' . $telephone . '?text=' . rawurlencode($message);
    }

    /**
     * Envoie une notification in-app ET retourne le lien WhatsApp associé
     * (l'UI affiche un bouton "Notifier sur WhatsApp" sans envoyer automatiquement).
     */
    public function notifierAvecWhatsApp(
        Utilisateur $destinataire,
        string $titre,
        string $message,
        string $type,
        ?Trajet $trajet = null,
        ?Reservation $reservation = null,
        ?string $url = null,
        ?string $icone = null,
        ?string $couleur = null
    ): array {
        $notification = $this->notifier(
            $destinataire,
            $titre,
            $message,
            $type,
            $trajet,
            $reservation,
            $url,
            $icone,
            $couleur
        );

        $whatsapp = $this->lienWhatsApp($destinataire, $this->appName . ' : ' . $message);

        return ['notification' => $notification, 'whatsapp' => $whatsapp];
    }

    /**
     * Envoi d'un email simple, non bloquant en cas d'échec (SMTP indisponible).
     */
    public function envoyerEmail(string $destinataire, string $sujet, string $corps): bool
    {
        if ($this->mailer === null || !filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $email = (new Email())
                ->from('noreply@covocam.cm')
                ->to($destinataire)
                ->subject($sujet)
                ->text($corps);

            $this->mailer->send($email);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
