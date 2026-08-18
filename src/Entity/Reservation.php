<?php

namespace App\Entity;

use App\Repository\ReservationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
// #[ORM\Table(name: 'reservation')] // Décommente cette ligne si ta table s'appelle "reservations" avec un 's'
class Reservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $placesReservees = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $prixTotal = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $commission = null;

    #[ORM\Column(length: 20)]
    private ?string $statut = 'EN_ATTENTE';

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dateReservation = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateConfirmation = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateAnnulation = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $motifAnnulation = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'reservations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $passager = null;

    #[ORM\ManyToOne(targetEntity: Trajet::class, inversedBy: 'reservations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Trajet $trajet = null;

    // ✅ RELATIONS RÉACTIVÉES (nécessaires au NoteService et au Paiement)
    #[ORM\OneToOne(targetEntity: Paiement::class, mappedBy: 'reservation', cascade: ['persist', 'remove'])]
    private ?Paiement $paiement = null;

    #[ORM\OneToMany(targetEntity: Evaluation::class, mappedBy: 'reservation', cascade: ['remove'])]
    private Collection $evaluations;

    public function __construct()
    {
        $this->dateReservation = new \DateTimeImmutable();
        $this->statut = 'EN_ATTENTE';
        $this->evaluations = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getPlacesReservees(): ?int { return $this->placesReservees; }
    public function setPlacesReservees(int $placesReservees): static { $this->placesReservees = $placesReservees; return $this; }
    public function getPrixTotal(): ?float { return $this->prixTotal; }
    public function setPrixTotal(?float $prixTotal): static { $this->prixTotal = $prixTotal; return $this; }
    public function getCommission(): ?float { return $this->commission; }
    public function setCommission(?float $commission): static { $this->commission = $commission; return $this; }
    public function getStatut(): ?string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = $statut; return $this; }
    public function getDateReservation(): ?\DateTimeImmutable { return $this->dateReservation; }
    public function setDateReservation(\DateTimeImmutable $dateReservation): static { $this->dateReservation = $dateReservation; return $this; }
    public function getDateConfirmation(): ?\DateTimeImmutable { return $this->dateConfirmation; }
    public function setDateConfirmation(?\DateTimeImmutable $dateConfirmation): static { $this->dateConfirmation = $dateConfirmation; return $this; }
    public function getDateAnnulation(): ?\DateTimeImmutable { return $this->dateAnnulation; }
    public function setDateAnnulation(?\DateTimeImmutable $dateAnnulation): static { $this->dateAnnulation = $dateAnnulation; return $this; }
    public function getMotifAnnulation(): ?string { return $this->motifAnnulation; }
    public function setMotifAnnulation(?string $motifAnnulation): static { $this->motifAnnulation = $motifAnnulation; return $this; }
    public function getPassager(): ?Utilisateur { return $this->passager; }
    public function setPassager(?Utilisateur $passager): static { $this->passager = $passager; return $this; }
    public function getTrajet(): ?Trajet { return $this->trajet; }
    public function setTrajet(?Trajet $trajet): static { $this->trajet = $trajet; return $this; }

    public function getPaiement(): ?Paiement { return $this->paiement; }
    public function setPaiement(?Paiement $paiement): static { $this->paiement = $paiement; return $this; }
    public function getEvaluations(): Collection { return $this->evaluations; }
    public function addEvaluation(Evaluation $evaluation): static {
        if (!$this->evaluations->contains($evaluation)) {
            $this->evaluations->add($evaluation);
            $evaluation->setReservation($this);
        }
        return $this;
    }
    public function removeEvaluation(Evaluation $evaluation): static {
        if ($this->evaluations->removeElement($evaluation)) {
            if ($evaluation->getReservation() === $this) {
                $evaluation->setReservation(null);
            }
        }
        return $this;
    }
}