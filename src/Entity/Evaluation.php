<?php

namespace App\Entity;

use App\Repository\EvaluationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EvaluationRepository::class)]
#[ORM\Table(name: 'evaluation')]
class Evaluation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'La note est obligatoire')]
    #[Assert\Range(min: 1, max: 5, minMessage: 'La note doit être au minimum de 1', maxMessage: 'La note ne peut pas dépasser 5')]
    private ?int $note = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 500, maxMessage: 'Le commentaire ne peut pas dépasser 500 caractères')]
    private ?string $commentaire = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dateEvaluation = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateModification = null;

    // Type d'évaluation : 'CONDUCTEUR' (passager → conducteur),
    // 'PASSAGER' (conducteur → passager) ou 'PLATEFORME' (avis sur la plateforme).
    #[ORM\Column(length: 20, options: ['default' => 'CONDUCTEUR'])]
    private string $type = 'CONDUCTEUR';

    // Double-aveugle : la note n'est visible que lorsque les deux parties
    // ont évalué la même réservation (ou après le délai de révélation).
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $revele = false;

    // ==========================================
    // RELATIONS
    // ==========================================

    // Auteur = celui qui donne l'évaluation (le passager)
    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'evaluationsDonnees')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $auteur = null;

    // Cible = celui qui reçoit l'évaluation (le conducteur).
    // null pour les évaluations de type PLATEFORME.
    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'evaluationsRecues')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $cible = null;

    // L'évaluation est liée à une réservation spécifique (empêche les faux avis).
    // null pour les évaluations de type PLATEFORME.
    #[ORM\ManyToOne(targetEntity: Reservation::class, inversedBy: 'evaluations')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Reservation $reservation = null;

    // Lien direct avec le trajet pour faciliter les requêtes d'affichage.
    #[ORM\ManyToOne(targetEntity: Trajet::class, inversedBy: 'evaluations')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Trajet $trajet = null;

    // ==========================================
    // CONSTRUCTEUR
    // ==========================================
    public function __construct()
    {
        $this->dateEvaluation = new \DateTimeImmutable();
    }

    // ==========================================
    // GETTERS ET SETTERS
    // ==========================================
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNote(): ?int
    {
        return $this->note;
    }

    public function setNote(int $note): static
    {
        $this->note = $note;
        return $this;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire;
        return $this;
    }

    public function getDateEvaluation(): ?\DateTimeImmutable
    {
        return $this->dateEvaluation;
    }

    public function setDateEvaluation(\DateTimeImmutable $dateEvaluation): static
    {
        $this->dateEvaluation = $dateEvaluation;
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = strtoupper($type);
        return $this;
    }

    public function isRevele(): bool
    {
        return $this->revele;
    }

    public function setRevele(bool $revele): static
    {
        $this->revele = $revele;
        return $this;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): static
    {
        $this->auteur = $auteur;
        return $this;
    }

    public function getCible(): ?Utilisateur
    {
        return $this->cible;
    }

    public function setCible(?Utilisateur $cible): static
    {
        $this->cible = $cible;
        return $this;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function setReservation(?Reservation $reservation): static
    {
        $this->reservation = $reservation;
        return $this;
    }

    public function getTrajet(): ?Trajet
    {
        return $this->trajet;
    }

    public function setTrajet(?Trajet $trajet): static
    {
        $this->trajet = $trajet;
        return $this;
    }
}