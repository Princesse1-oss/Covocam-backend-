<?php

namespace App\Entity;

use App\Repository\PaiementRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PaiementRepository::class)]
class Paiement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Reservation::class, inversedBy: 'paiement')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Reservation $reservation = null;
    
    #[ORM\Column(length: 100, unique: true)]
    private ?string $campayReference = null;

    #[ORM\Column(type: 'float')]
    #[Assert\Positive(message: 'Le montant total doit être positif')]
    private float $montantTotal = 0.0; // Ce que le passager a payé

    #[ORM\Column(type: 'float')]
    #[Assert\Positive(message: 'La commission doit être positive')]
    private float $commission = 0.0; // Ce que la plateforme garde

    #[ORM\Column(type: 'float')]
    #[Assert\Positive(message: 'Le montant net conducteur doit être positif')]
    private float $montantNetConducteur = 0.0; // Ce qui reviendra au conducteur

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['XAF', 'EUR', 'USD'], message: 'La devise doit être XAF, EUR ou USD')]
    private string $devise = 'XAF';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['EN_ATTENTE', 'REUSSI', 'ECHEC', 'REMBOURSE'], message: 'Le statut doit être EN_ATTENTE, REUSSI, ECHEC ou REMBOURSE')]
    private string $statut = 'EN_ATTENTE'; // EN_ATTENTE, REUSSI, ECHEC, REMBOURSE

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $datePaiement = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateRemboursement = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    // --- GETTERS & SETTERS (Générés automatiquement, assure-toi qu'ils sont tous présents) ---
    public function getId(): ?int { return $this->id; }
    public function getReservation(): ?Reservation { return $this->reservation; }
    public function setReservation(?Reservation $reservation): self { $this->reservation = $reservation; return $this; }
    public function getCampayReference(): ?string { return $this->campayReference; }
    public function setCampayReference(string $campayReference): self { $this->campayReference = $campayReference; return $this; }
    public function getMontantTotal(): ?float { return $this->montantTotal; }
    public function setMontantTotal(float $montantTotal): self { $this->montantTotal = $montantTotal; return $this; }
    public function getCommission(): ?float { return $this->commission; }
    public function setCommission(float $commission): self { $this->commission = $commission; return $this; }
    public function getMontantNetConducteur(): ?float { return $this->montantNetConducteur; }
    public function setMontantNetConducteur(float $montantNetConducteur): self { $this->montantNetConducteur = $montantNetConducteur; return $this; }
    public function getDevise(): ?string { return $this->devise; }
    public function setDevise(string $devise): self { $this->devise = $devise; return $this; }
    public function getStatut(): ?string { return $this->statut; }
    public function setStatut(string $statut): self { $this->statut = $statut; return $this; }
    public function getDateCreation(): ?\DateTimeImmutable { return $this->dateCreation; }
    public function getDatePaiement(): ?\DateTimeImmutable { return $this->datePaiement; }
    public function setDatePaiement(?\DateTimeImmutable $datePaiement): self { $this->datePaiement = $datePaiement; return $this; }
    public function getDateRemboursement(): ?\DateTimeImmutable { return $this->dateRemboursement; }
    public function setDateRemboursement(?\DateTimeImmutable $dateRemboursement): self { $this->dateRemboursement = $dateRemboursement; return $this; }
}