<?php

namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Environment\EnvVarProcessorInterface;

class EmailService
{
    private MailerInterface $mailer;
    private string $senderEmail;
    private string $frontendUrl;

    public function __construct(MailerInterface $mailer, string $senderEmail, string $frontendUrl)
    {
        $this->mailer = $mailer;
        $this->senderEmail = $senderEmail;
        $this->frontendUrl = $frontendUrl;
    }

    public function sendSuspensionEmail(string $toEmail, string $prenom): void
    {
        $email = (new Email())
            ->from($this->senderEmail)
            ->to($toEmail)
            ->subject('Notification importante : Suspension de votre compte CovoCam')
            ->html("
                <h2>Bonjour {$prenom},</h2>
                <p>Nous vous informons que votre compte sur la plateforme <strong>CovoCam</strong> a été suspendu par l'administrateur.</p>
                <p>Vous ne pouvez plus vous connecter à la plateforme pour le moment.</p>
                <p>Si vous pensez qu'il s'agit d'une erreur, veuillez contacter notre support à l'adresse : <a href='mailto:support@covocam.com'>support@covocam.com</a>.</p>
                <br>
                <p>Cordialement,<br>L'équipe CovoCam</p>
            ");

        try {
            $this->mailer->send($email);
        } catch (\Exception $e) {
        }
    }

    public function sendReactivationEmail(string $toEmail, string $prenom): void
    {
        $email = (new Email())
            ->from($this->senderEmail)
            ->to($toEmail)
            ->subject('Bonne nouvelle : Votre compte CovoCam a été réactivé !')
            ->html("
                <h2>Bonjour {$prenom},</h2>
                <p>Nous avons le plaisir de vous informer que votre compte sur la plateforme <strong>CovoCam</strong> a été <strong>réactivé</strong> par l'administrateur.</p>
                <p>Vous pouvez désormais vous reconnecter et profiter pleinement de tous nos services.</p>
                <p>Connectez-vous dès maintenant : <a href='{$this->frontendUrl}/login'>Se connecter à CovoCam</a></p>
                <br>
                <p>Cordialement,<br>L'équipe CovoCam</p>
            ");

        try {
            $this->mailer->send($email);
        } catch (\Exception $e) {
        }
    }

    public function sendDriverSuspensionEmail(string $toEmail, string $prenom, int $nbAnnulations): void
    {
        $email = (new Email())
            ->from($this->senderEmail)
            ->to($toEmail)
            ->subject('Suspension temporaire de votre compte conducteur CovoCam')
            ->html("
                <h2>Bonjour {$prenom},</h2>
                <p>Nous vous informons que votre <strong>compte conducteur</strong> sur la plateforme <strong>CovoCam</strong> a été temporairement suspendu.</p>
                <p><strong>Raison :</strong> Vous avez annulé <strong>{$nbAnnulations} trajet(s)</strong> au cours des 30 derniers jours.</p>
                <p>Notre politique de qualité stipule qu'après <strong>3 annulations</strong> de trajets imminents, le compte conducteur est suspendu temporairement pour protéger la fiabilité du service envers les passagers.</p>
                <p><strong>Durée de la suspension :</strong> 7 jours.</p>
                <p>Si vous pensez qu'il s'agit d'une erreur, veuillez contacter notre support à l'adresse : <a href='mailto:support@covocam.com'>support@covocam.com</a>.</p>
                <br>
                <p>Cordialement,<br>L'équipe CovoCam</p>
            ");

        try {
            $this->mailer->send($email);
        } catch (\Exception $e) {
        }
    }

    public function sendDriverReactivationEmail(string $toEmail, string $prenom): void
    {
        $email = (new Email())
            ->from($this->senderEmail)
            ->to($toEmail)
            ->subject('Votre compte conducteur CovoCam a été réactivé !')
            ->html("
                <h2>Bonjour {$prenom},</h2>
                <p>Bonne nouvelle ! Votre <strong>compte conducteur</strong> sur la plateforme <strong>CovoCam</strong> a été <strong>réactivé</strong>.</p>
                <p>Vous pouvez à nouveau publier des trajets et recevoir des réservations.</p>
                <p>Connectez-vous dès maintenant : <a href='{$this->frontendUrl}/conducteur'>Espace Conducteur CovoCam</a></p>
                <br>
                <p>Cordialement,<br>L'équipe CovoCam</p>
            ");

        try {
            $this->mailer->send($email);
        } catch (\Exception $e) {
        }
    }
}
