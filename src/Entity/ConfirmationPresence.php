<?php

namespace App\Entity;

use App\Repository\ConfirmationPresenceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConfirmationPresenceRepository::class)]
class ConfirmationPresence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Trajet::class, inversedBy: 'confirmationPresences')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Trajet $trajet = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'confirmationPresences')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(type: 'boolean')]
    private bool $estPresent = false;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $confirmeParConducteur = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $gpsVerifie = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $timestampConfirmation = null;

    public function __construct()
    {
        $this->timestampConfirmation = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getTrajet(): ?Trajet { return $this->trajet; }
    public function setTrajet(?Trajet $trajet): static { $this->trajet = $trajet; return $this; }
    public function getUtilisateur(): ?Utilisateur { return $this->utilisateur; }
    public function setUtilisateur(?Utilisateur $utilisateur): static { $this->utilisateur = $utilisateur; return $this; }
    public function isEstPresent(): bool { return $this->estPresent; }
    public function setEstPresent(bool $estPresent): static { $this->estPresent = $estPresent; return $this; }
    public function getConfirmeParConducteur(): ?bool { return $this->confirmeParConducteur; }
    public function setConfirmeParConducteur(?bool $confirmeParConducteur): static { $this->confirmeParConducteur = $confirmeParConducteur; return $this; }
    public function getGpsVerifie(): ?bool { return $this->gpsVerifie; }
    public function setGpsVerifie(?bool $gpsVerifie): static { $this->gpsVerifie = $gpsVerifie; return $this; }
    public function getTimestampConfirmation(): ?\DateTimeInterface { return $this->timestampConfirmation; }
    public function setTimestampConfirmation(\DateTimeInterface $timestampConfirmation): static { $this->timestampConfirmation = $timestampConfirmation; return $this; }
}