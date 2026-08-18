<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'preference_trajet_conducteur')]
class PreferenceTrajetConducteur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $villeDepartHabituelle = null;

    #[ORM\Column(length: 100)]
    private ?string $villeArriveeHabituelle = null;

    #[ORM\Column(type: 'json')]
    private array $joursHabituels = [];

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $budgetMinimum = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $notificationActive = true;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $conducteur = null;

    public function getId(): ?int { return $this->id; }

    public function getVilleDepartHabituelle(): ?string { return $this->villeDepartHabituelle; }
    public function setVilleDepartHabituelle(string $villeDepartHabituelle): static { $this->villeDepartHabituelle = $villeDepartHabituelle; return $this; }

    public function getVilleArriveeHabituelle(): ?string { return $this->villeArriveeHabituelle; }
    public function setVilleArriveeHabituelle(string $villeArriveeHabituelle): static { $this->villeArriveeHabituelle = $villeArriveeHabituelle; return $this; }

    public function getJoursHabituels(): array { return $this->joursHabituels; }
    public function setJoursHabituels(array $joursHabituels): static { $this->joursHabituels = $joursHabituels; return $this; }

    public function getBudgetMinimum(): ?float { return $this->budgetMinimum; }
    public function setBudgetMinimum(?float $budgetMinimum): static { $this->budgetMinimum = $budgetMinimum; return $this; }

    public function isNotificationActive(): bool { return $this->notificationActive; }
    public function setNotificationActive(bool $notificationActive): static { $this->notificationActive = $notificationActive; return $this; }

    public function getConducteur(): ?Utilisateur { return $this->conducteur; }
    public function setConducteur(?Utilisateur $conducteur): static { $this->conducteur = $conducteur; return $this; }
}
