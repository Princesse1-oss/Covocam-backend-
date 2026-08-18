<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

// ✅ CORRECTION : Suppression de la référence au repository inexistant
#[ORM\Entity]
#[ORM\Table(name: 'demande_trajet')]
class DemandeTrajet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'La ville de départ est obligatoire')]
    private ?string $villeDepart = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'La ville d\'arrivée est obligatoire')]
    private ?string $villeArrivee = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $quartierDepart = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $quartierArrivee = null;

    #[ORM\Column(type: 'date')]
    #[Assert\NotBlank(message: 'La date de départ est obligatoire')]
    private ?\DateTimeInterface $dateDepart = null;

    #[ORM\Column(type: 'time', nullable: true)]
    private ?\DateTimeInterface $heureDepart = null;

    #[ORM\Column]
    #[Assert\Range(min: 1, max: 8, notInRangeMessage: 'Le nombre de places doit être entre {{ min }} et {{ max }}')]
    private ?int $nbPlaces = null;

    #[ORM\Column(type: 'float')]
    #[Assert\Positive(message: 'Le budget doit être positif')]
    private ?float $budgetMax = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 30)]
    private string $statut = 'EN_ATTENTE';

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $dateExpiration = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $prixPropose = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $estPrivee = false;

    // ==========================================
    // RELATIONS
    // ==========================================
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $passager = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $conducteurAcceptant = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $destinatairePrive = null;

    #[ORM\ManyToOne(targetEntity: Trajet::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Trajet $trajetCree = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
        $this->statut = 'EN_ATTENTE';
    }

    // ==========================================
    // GETTERS ET SETTERS
    // ==========================================
    public function getId(): ?int { return $this->id; }

    public function getVilleDepart(): ?string { return $this->villeDepart; }
    public function setVilleDepart(string $villeDepart): static { $this->villeDepart = $villeDepart; return $this; }

    public function getVilleArrivee(): ?string { return $this->villeArrivee; }
    public function setVilleArrivee(string $villeArrivee): static { $this->villeArrivee = $villeArrivee; return $this; }

    public function getQuartierDepart(): ?string { return $this->quartierDepart; }
    public function setQuartierDepart(?string $quartierDepart): static { $this->quartierDepart = $quartierDepart; return $this; }

    public function getQuartierArrivee(): ?string { return $this->quartierArrivee; }
    public function setQuartierArrivee(?string $quartierArrivee): static { $this->quartierArrivee = $quartierArrivee; return $this; }

    public function getDateDepart(): ?\DateTimeInterface { return $this->dateDepart; }
    public function setDateDepart(\DateTimeInterface $dateDepart): static { $this->dateDepart = $dateDepart; return $this; }

    public function getHeureDepart(): ?\DateTimeInterface { return $this->heureDepart; }
    public function setHeureDepart(?\DateTimeInterface $heureDepart): static { $this->heureDepart = $heureDepart; return $this; }

    public function getNbPlaces(): ?int { return $this->nbPlaces; }
    public function setNbPlaces(int $nbPlaces): static { $this->nbPlaces = $nbPlaces; return $this; }

    public function getBudgetMax(): ?float { return $this->budgetMax; }
    public function setBudgetMax(float $budgetMax): static { $this->budgetMax = $budgetMax; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = strtoupper($statut); return $this; }

    public function getDateCreation(): ?\DateTimeImmutable { return $this->dateCreation; }
    public function setDateCreation(\DateTimeImmutable $dateCreation): static { $this->dateCreation = $dateCreation; return $this; }

    public function getDateExpiration(): ?\DateTimeInterface { return $this->dateExpiration; }
    public function setDateExpiration(\DateTimeInterface $dateExpiration): static { $this->dateExpiration = $dateExpiration; return $this; }

    public function getPrixPropose(): ?float { return $this->prixPropose; }
    public function setPrixPropose(?float $prixPropose): static { $this->prixPropose = $prixPropose; return $this; }

    public function isEstPrivee(): bool { return $this->estPrivee; }
    public function setEstPrivee(bool $estPrivee): static { $this->estPrivee = $estPrivee; return $this; }

    public function getPassager(): ?Utilisateur { return $this->passager; }
    public function setPassager(?Utilisateur $passager): static { $this->passager = $passager; return $this; }

    public function getConducteurAcceptant(): ?Utilisateur { return $this->conducteurAcceptant; }
    public function setConducteurAcceptant(?Utilisateur $conducteurAcceptant): static { $this->conducteurAcceptant = $conducteurAcceptant; return $this; }

    public function getDestinatairePrive(): ?Utilisateur { return $this->destinatairePrive; }
    public function setDestinatairePrive(?Utilisateur $destinatairePrive): static { $this->destinatairePrive = $destinatairePrive; return $this; }

    public function getTrajetCree(): ?Trajet { return $this->trajetCree; }
    public function setTrajetCree(?Trajet $trajetCree): static { $this->trajetCree = $trajetCree; return $this; }
}