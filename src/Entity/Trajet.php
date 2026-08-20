<?php

namespace App\Entity;

use App\Repository\TrajetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TrajetRepository::class)]
class Trajet
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

    #[ORM\Column(length: 100)]
    private ?string $quartierDepart = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $quartierArrivee = null;

    #[ORM\Column(length: 100)]
    private ?string $pointDepart = null;

    #[ORM\Column(length: 100)]
    private ?string $pointArrivee = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $dateDepart = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $heureDepart = null;

    #[ORM\Column]
    private ?int $placesDisponibles = null;

    #[ORM\Column(type: 'float')]
    private ?float $prixParPlace = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    // ✅ STATUTS POSSIBLES : 'OUVERT', 'EN_ATTENTE_DEPART', 'EN_COURS', 'EN_ATTENTE_VALIDATION', 'TERMINE', 'ANNULE'
    #[ORM\Column(length: 30)]
    private string $statut = 'OUVERT';

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // ==========================================
    // CHAMPS GPS & SUIVI
    // ==========================================
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $pointDepartLat = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $pointDepartLng = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $pointArriveeLat = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $pointArriveeLng = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $positionActuelleLat = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $positionActuelleLng = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $trajetActive = false;

    #[ORM\Column(type: 'time', nullable: true)]
    private ?\DateTimeInterface $heureArriveeEstimee = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateTermine = null;

    // ==========================================
    // RELATIONS
    // ==========================================
    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'trajetsConduits')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $conducteur = null;

    #[ORM\ManyToOne(targetEntity: Vehicule::class, inversedBy: 'trajets')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Vehicule $vehicule = null;

    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'trajet', cascade: ['remove'])]
    private Collection $reservations;

    #[ORM\OneToMany(targetEntity: Evaluation::class, mappedBy: 'trajet', cascade: ['remove'])]
    private Collection $evaluations;

    #[ORM\OneToMany(targetEntity: Notification::class, mappedBy: 'trajet')]
    private Collection $notifications;

    #[ORM\OneToMany(targetEntity: PositionHistory::class, mappedBy: 'trajet', cascade: ['remove'])]
    private Collection $positionHistories;

    #[ORM\OneToMany(targetEntity: ConfirmationPresence::class, mappedBy: 'trajet', cascade: ['remove'])]
    private Collection $confirmationPresences;

    public function __construct()
    {
        $this->reservations = new ArrayCollection();
        $this->evaluations = new ArrayCollection();
        $this->notifications = new ArrayCollection();
        $this->positionHistories = new ArrayCollection();
        $this->confirmationPresences = new ArrayCollection();
        
        // ✅ Corrigé pour correspondre à la nouvelle logique du Cron Job
        $this->statut = 'OUVERT'; 
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ===== GETTERS ET SETTERS =====
    public function getId(): ?int { return $this->id; }

    public function getVilleDepart(): ?string { return $this->villeDepart; }
    public function setVilleDepart(string $villeDepart): static { $this->villeDepart = $villeDepart; return $this; }

    public function getVilleArrivee(): ?string { return $this->villeArrivee; }
    public function setVilleArrivee(string $villeArrivee): static { $this->villeArrivee = $villeArrivee; return $this; }

    public function getQuartierDepart(): ?string { return $this->quartierDepart; }
    public function setQuartierDepart(string $quartierDepart): static { $this->quartierDepart = $quartierDepart; return $this; }

    public function getQuartierArrivee(): ?string { return $this->quartierArrivee; }
    public function setQuartierArrivee(?string $quartierArrivee): static { $this->quartierArrivee = $quartierArrivee; return $this; }

    public function getPointDepart(): ?string { return $this->pointDepart; }
    public function setPointDepart(string $pointDepart): static { $this->pointDepart = $pointDepart; return $this; }

    public function getPointArrivee(): ?string { return $this->pointArrivee; }
    public function setPointArrivee(string $pointArrivee): static { $this->pointArrivee = $pointArrivee; return $this; }

    public function getDateDepart(): ?\DateTimeInterface { return $this->dateDepart; }
    public function setDateDepart(\DateTimeInterface $dateDepart): static { $this->dateDepart = $dateDepart; return $this; }

    public function getHeureDepart(): ?\DateTimeInterface { return $this->heureDepart; }
    public function setHeureDepart(\DateTimeInterface $heureDepart): static { $this->heureDepart = $heureDepart; return $this; }

    public function getPlacesDisponibles(): ?int { return $this->placesDisponibles; }
    public function setPlacesDisponibles(int $placesDisponibles): static { $this->placesDisponibles = $placesDisponibles; return $this; }

    public function getPrixParPlace(): ?float { return $this->prixParPlace; }
    public function setPrixParPlace(float $prixParPlace): static { $this->prixParPlace = $prixParPlace; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getStatut(): ?string { return $this->statut; }
    public function setStatut(string $statut): static { 
        $this->statut = strtoupper($statut); 
        return $this; 
    }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }

    public function getConducteur(): ?Utilisateur { return $this->conducteur; }
    public function setConducteur(?Utilisateur $conducteur): static { $this->conducteur = $conducteur; return $this; }

    public function getVehicule(): ?Vehicule { return $this->vehicule; }
    public function setVehicule(?Vehicule $vehicule): static { $this->vehicule = $vehicule; return $this; }
    
    public function getReservations(): Collection { return $this->reservations; }
    public function addReservation(Reservation $reservation): static { 
        if (!$this->reservations->contains($reservation)) { 
            $this->reservations->add($reservation); 
            $reservation->setTrajet($this); 
        } 
        return $this; 
    }
    public function removeReservation(Reservation $reservation): static { 
        if ($this->reservations->removeElement($reservation)) { 
            if ($reservation->getTrajet() === $this) { 
                $reservation->setTrajet(null); 
            } 
        } 
        return $this; 
    }

    public function getEvaluations(): Collection { return $this->evaluations; }
    public function getNotifications(): Collection { return $this->notifications; }

    public function getPositionHistories(): Collection { return $this->positionHistories; }
    public function addPositionHistory(PositionHistory $positionHistory): static {
        if (!$this->positionHistories->contains($positionHistory)) {
            $this->positionHistories->add($positionHistory);
            $positionHistory->setTrajet($this);
        }
        return $this;
    }
    public function removePositionHistory(PositionHistory $positionHistory): static {
        if ($this->positionHistories->removeElement($positionHistory)) {
            if ($positionHistory->getTrajet() === $this) { 
                $positionHistory->setTrajet(null); 
            }
        }
        return $this;
    }

    public function getConfirmationPresences(): Collection { return $this->confirmationPresences; }
    public function addConfirmationPresence(ConfirmationPresence $confirmationPresence): static {
        if (!$this->confirmationPresences->contains($confirmationPresence)) {
            $this->confirmationPresences->add($confirmationPresence);
            $confirmationPresence->setTrajet($this);
        }
        return $this;
    }
    public function removeConfirmationPresence(ConfirmationPresence $confirmationPresence): static {
        if ($this->confirmationPresences->removeElement($confirmationPresence)) {
            if ($confirmationPresence->getTrajet() === $this) { 
                $confirmationPresence->setTrajet(null); 
            }
        }
        return $this;
    }

    public function getPointDepartLat(): ?float { return $this->pointDepartLat; }
    public function setPointDepartLat(?float $pointDepartLat): static { $this->pointDepartLat = $pointDepartLat; return $this; }

    public function getPointDepartLng(): ?float { return $this->pointDepartLng; }
    public function setPointDepartLng(?float $pointDepartLng): static { $this->pointDepartLng = $pointDepartLng; return $this; }

    public function getPointArriveeLat(): ?float { return $this->pointArriveeLat; }
    public function setPointArriveeLat(?float $pointArriveeLat): static { $this->pointArriveeLat = $pointArriveeLat; return $this; }

    public function getPointArriveeLng(): ?float { return $this->pointArriveeLng; }
    public function setPointArriveeLng(?float $pointArriveeLng): static { $this->pointArriveeLng = $pointArriveeLng; return $this; }

    public function getPositionActuelleLat(): ?float { return $this->positionActuelleLat; }
    public function setPositionActuelleLat(?float $positionActuelleLat): static { $this->positionActuelleLat = $positionActuelleLat; return $this; }

    public function getPositionActuelleLng(): ?float { return $this->positionActuelleLng; }
    public function setPositionActuelleLng(?float $positionActuelleLng): static { $this->positionActuelleLng = $positionActuelleLng; return $this; }

    public function isTrajetActive(): bool { return $this->trajetActive; }
    public function setTrajetActive(bool $trajetActive): static { $this->trajetActive = $trajetActive; return $this; }

    public function getHeureArriveeEstimee(): ?\DateTimeInterface { return $this->heureArriveeEstimee; }
    public function setHeureArriveeEstimee(?\DateTimeInterface $heureArriveeEstimee): static { $this->heureArriveeEstimee = $heureArriveeEstimee; return $this; }

    public function getDateTermine(): ?\DateTimeImmutable { return $this->dateTermine; }
    public function setDateTermine(?\DateTimeImmutable $dateTermine): static { $this->dateTermine = $dateTermine; return $this; }
}