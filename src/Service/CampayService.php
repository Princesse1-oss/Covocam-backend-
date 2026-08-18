<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class CampayService
{
    private HttpClientInterface $httpClient;
    private string $username;
    private string $password;
    private string $appCode;
    private string $baseUrl;

    public function __construct(HttpClientInterface $httpClient, string $campayUsername, string $campayPassword, string $campayAppCode)
    {
        $this->httpClient = $httpClient;
        $this->username = $campayUsername;
        $this->password = $campayPassword;
        $this->appCode = $campayAppCode;
        $this->baseUrl = 'https://campay.net/api';
    }

    private function getAuthHeader(): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Demander un paiement via Campay
     */
    public function requestPayment(string $phoneNumber, float $amount, string $description, string $externalId): array
    {
        $response = $this->httpClient->request('POST', $this->baseUrl . '/collect/', [
            'headers' => $this->getAuthHeader(),
            'json' => [
                'amount' => $amount,
                'from' => $phoneNumber,
                'description' => $description,
                'external_id' => $externalId,
            ],
        ]);

        return $response->toArray();
    }

    /**
     * Vérifier le statut d'une transaction
     */
    public function checkTransactionStatus(string $reference): array
    {
        $response = $this->httpClient->request('GET', $this->baseUrl . '/collect/' . $reference . '/', [
            'headers' => $this->getAuthHeader(),
        ]);

        return $response->toArray();
    }

    /**
     * Envoyer de l'argent via Campay (disbursement)
     */
    public function sendMoney(string $phoneNumber, float $amount, string $description, string $externalId): array
    {
        $response = $this->httpClient->request('POST', $this->baseUrl . '/disburse/', [
            'headers' => $this->getAuthHeader(),
            'json' => [
                'amount' => $amount,
                'to' => $phoneNumber,
                'description' => $description,
                'external_id' => $externalId,
            ],
        ]);

        return $response->toArray();
    }

    /**
     * Obtenir le solde du compte Campay
     */
    public function getBalance(): array
    {
        $response = $this->httpClient->request('GET', $this->baseUrl . '/balance/', [
            'headers' => $this->getAuthHeader(),
        ]);

        return $response->toArray();
    }
}
