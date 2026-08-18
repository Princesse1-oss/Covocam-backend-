<?php

namespace App\Entity;

use App\Repository\PlafondPrixRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Plafond de prix (FCFA) par paire de villes.
 * Protège le passager contre les tarifs abusifs (point 7 du cahier des charges).
 */
#[ORM\Entity(repositoryClass: PlafondPrixRepository::class)]
#[ORM\Table(name: 'plafond_prix')]
class PlafondPrix
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

    #[ORM\Column(type: 'float')]
    #[Assert\NotBlank(message: 'Le prix maximum est obligatoire')]
    #[Assert\Positive(message: 'Le prix maximum doit être positif')]
    private ?float $prixMax = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateModification = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->dateModification = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVilleDepart(): ?string
    {
        return $this->villeDepart;
    }

    public function setVilleDepart(string $villeDepart): static
    {
        $this->villeDepart = $villeDepart;
        return $this;
    }

    public function getVilleArrivee(): ?string
    {
        return $this->villeArrivee;
    }

    public function setVilleArrivee(string $villeArrivee): static
    {
        $this->villeArrivee = $villeArrivee;
        return $this;
    }

    public function getPrixMax(): ?float
    {
        return $this->prixMax;
    }

    public function setPrixMax(float $prixMax): static
    {
        $this->prixMax = $prixMax;
        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;
        return $this;
    }

    public function getDateModification(): ?\DateTimeImmutable
    {
        return $this->dateModification;
    }

    public function setDateModification(?\DateTimeImmutable $dateModification): static
    {
        $this->dateModification = $dateModification;
        return $this;
    }
}
