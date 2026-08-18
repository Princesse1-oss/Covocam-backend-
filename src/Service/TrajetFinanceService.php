<?php

namespace App\Service;

use App\Entity\Trajet;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Traitement financier de fin de trajet :
 * - présente/absente par passager (statut de réservation TERMINEE / NON_PRESENT)
 * - transferts Campay (présent, dédommagement absent, remboursement partiel)
 * - email récapitulatif à l'admin
 *
 * Chaque paiement est isolé : un échec Campay est journalisé, jamais bloquant.
 */
class TrajetFinanceService
{
    private const COMMISSION_PRESENT = 0.10;
    private const DRIVER_PRESENT = 0.90;
    private const PLATFORM_ABSENT = 0.30;
    private const DRIVER_ABSENT = 0.20;
    private const REFUND_ABSENT = 0.50;

    private CampayService $campayService;
    private ?MailerInterface $mailer;

    public function __construct(CampayService $campayService, ?MailerInterface $mailer = null)
    {
        $this->campayService = $campayService;
        $this->mailer = $mailer;
    }

    public function traiter(Trajet $trajet): array
    {
        $recapitulatif = [];
        $totalDriver = 0.0;
        $totalPlatform = 0.0;
        $totalRefunded = 0.0;

        foreach ($trajet->getReservations() as $reservation) {
            $passager = $reservation->getPassager();
            if (!$passager) {
                continue;
            }

            $prixTotal = (float) $reservation->getPrixTotal();
            $statutReservation = strtoupper((string) $reservation->getStatut());
            $estPresent = in_array($statutReservation, ['TERMINEE', 'CONFIRMEE'], true);

            if ($estPresent) {
                // SCÉNARIO 1 : PASSAGER PRÉSENT
                $amountDriver = $prixTotal * self::DRIVER_PRESENT;
                $amountPlatform = $prixTotal * self::COMMISSION_PRESENT;
                $amountRefund = 0.0;

                $this->initierPaiementCampay($trajet->getConducteur()?->getTelephone(), $amountDriver, 'Paiement trajet CovoCam (Présent)');

                $recapitulatif[] = [
                    'passager' => $passager->getPrenom() . ' ' . $passager->getNom(),
                    'statut' => 'Présent',
                    'driver' => round($amountDriver),
                    'platform' => round($amountPlatform),
                    'refund' => 0,
                ];
            } else {
                // SCÉNARIO 2 : PASSAGER ABSENT (NON_PRESENT)
                $amountDriver = $prixTotal * self::DRIVER_ABSENT;
                $amountPlatform = $prixTotal * self::PLATFORM_ABSENT;
                $amountRefund = $prixTotal * self::REFUND_ABSENT;

                // 1. Rembourser le passager (50%)
                $this->initierPaiementCampay($passager->getTelephone(), $amountRefund, 'Remboursement partiel CovoCam (Absence)');

                // 2. Dédommager le conducteur (20%)
                $this->initierPaiementCampay($trajet->getConducteur()?->getTelephone(), $amountDriver, 'Dédommagement place vide CovoCam');

                $recapitulatif[] = [
                    'passager' => $passager->getPrenom() . ' ' . $passager->getNom(),
                    'statut' => 'Absent (Pénalité appliquée)',
                    'driver' => round($amountDriver),
                    'platform' => round($amountPlatform),
                    'refund' => round($amountRefund),
                ];
            }

            $totalDriver += $amountDriver;
            $totalPlatform += $amountPlatform;
            $totalRefunded += $amountRefund;
        }

        $this->envoyerEmailAdmin($trajet, $recapitulatif, $totalDriver, $totalPlatform, $totalRefunded);

        return [
            'recapitulatif' => $recapitulatif,
            'totalDriver' => round($totalDriver),
            'totalPlatform' => round($totalPlatform),
            'totalRefunded' => round($totalRefunded),
        ];
    }

    private function initierPaiementCampay(?string $telephone, float $montant, string $description): void
    {
        if (!$telephone || $montant <= 0) {
            return;
        }

        try {
            $this->campayService->sendMoney($telephone, $montant, $description, 'trajet-' . uniqid());
        } catch (\Throwable $e) {
            // Journalisé pour intervention manuelle, jamais bloquant
            error_log('Erreur API Campay: ' . $e->getMessage());
        }
    }

    private function envoyerEmailAdmin(Trajet $trajet, array $recapitulatif, float $totalDriver, float $totalPlatform, float $totalRefunded): void
    {
        if ($this->mailer === null) {
            return;
        }

        $rows = '';
        foreach ($recapitulatif as $item) {
            $refund = $item['refund'] > 0 ? "{$item['refund']} FCFA" : '-';
            $rows .= "<tr>
                <td style='padding: 10px; border: 1px solid #e5e7eb;'>{$item['passager']}</td>
                <td style='padding: 10px; border: 1px solid #e5e7eb;'>{$item['statut']}</td>
                <td style='padding: 10px; border: 1px solid #e5e7eb;'>{$item['driver']} FCFA</td>
                <td style='padding: 10px; border: 1px solid #e5e7eb;'>{$item['platform']} FCFA</td>
                <td style='padding: 10px; border: 1px solid #e5e7eb;'>{$refund}</td>
            </tr>";
        }

        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;'>
            <div style='background-color: #0D9E7E; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;'>
                <h1 style='margin: 0;'>CovoCam - Trajet Terminé</h1>
            </div>
            <div style='padding: 20px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px;'>
                <p>Bonjour Administrateur,</p>
                <p>Le trajet <strong>{$trajet->getVilleDepart()} → {$trajet->getVilleArrivee()}</strong> du {$trajet->getDateDepart()->format('d/m/Y')} a été terminé.</p>
                <h3 style='color: #0D9E7E;'>Récapitulatif des passagers :</h3>
                <table style='width: 100%; border-collapse: collapse; margin: 15px 0;'>
                    <thead>
                        <tr style='background: #f3f4f6;'>
                            <th style='padding: 10px; border: 1px solid #e5e7eb; text-align: left;'>Passager</th>
                            <th style='padding: 10px; border: 1px solid #e5e7eb; text-align: left;'>Statut</th>
                            <th style='padding: 10px; border: 1px solid #e5e7eb; text-align: left;'>Conducteur</th>
                            <th style='padding: 10px; border: 1px solid #e5e7eb; text-align: left;'>Plateforme</th>
                            <th style='padding: 10px; border: 1px solid #e5e7eb; text-align: left;'>Remboursement</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>
                <div style='background: #f9fafb; padding: 15px; border-radius: 8px; margin-top: 20px;'>
                    <p style='margin: 5px 0;'>Total versé aux conducteurs : <strong>{$totalDriver} FCFA</strong></p>
                    <p style='margin: 5px 0;'>Total commission/pénalité CovoCam : <strong>{$totalPlatform} FCFA</strong> <em>(frais Campay déduits)</em></p>
                    <p style='margin: 5px 0; color: #2563EB;'>Total remboursé aux passagers : <strong>{$totalRefunded} FCFA</strong></p>
                </div>
                <p style='color: #d97706; font-weight: bold; margin-top: 20px;'>⚠️ Action requise :</p>
                <p>Veuillez vérifier votre tableau de bord Campay pour confirmer que tous les transferts automatiques ont bien été exécutés.</p>
            </div>
        </div>";

        try {
            $email = (new \Symfony\Component\Mime\Email())
                ->from('noreply@covocam.cm')
                ->to($_ENV['ADMIN_EMAIL'] ?? $_SERVER['ADMIN_EMAIL'] ?? 'admin@covocam.cm')
                ->subject('🚗 Trajet Terminé : Récapitulatif des paiements CovoCam')
                ->html($html);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            error_log('Erreur email admin: ' . $e->getMessage());
        }
    }
}
