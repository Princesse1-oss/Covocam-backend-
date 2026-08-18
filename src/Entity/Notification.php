<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $titre = null;

    #[ORM\Column(type: 'text')]
    private ?string $message = null;

    #[ORM\Column(length: 30)]
    private ?string $type = null;

    #[ORM\Column]
    private ?bool $estLu = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dateEnvoi = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateLecture = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $icone = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $couleur = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'notifications')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $destinataire = null;

    // ✅ CORRECTION : Ajout de inversedBy pour la relation bidirectionnelle avec Trajet
    #[ORM\ManyToOne(targetEntity: Trajet::class, inversedBy: 'notifications')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Trajet $trajet = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Reservation $reservation = null;

    public function __construct()
    {
        $this->dateEnvoi = new \DateTimeImmutable();
        $this->estLu = false;
    }

    // ... (Garde tous tes getters et setters existants, ils sont parfaits) ...
    public function getId(): ?int { return $this->id; }
    public function getTitre(): ?string { return $this->titre; }
    public function setTitre(string $titre): static { $this->titre = $titre; return $this; }
    public function getMessage(): ?string { return $this->message; }
    public function setMessage(string $message): static { $this->message = $message; return $this; }
    public function getType(): ?string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }
    public function isEstLu(): ?bool { return $this->estLu; }
    public function setEstLu(bool $estLu): static { $this->estLu = $estLu; return $this; }
    public function getDateEnvoi(): ?\DateTimeImmutable { return $this->dateEnvoi; }
    public function setDateEnvoi(\DateTimeImmutable $dateEnvoi): static { $this->dateEnvoi = $dateEnvoi; return $this; }
    public function getDateLecture(): ?\DateTimeImmutable { return $this->dateLecture; }
    public function setDateLecture(?\DateTimeImmutable $dateLecture): static { $this->dateLecture = $dateLecture; return $this; }
    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $url): static { $this->url = $url; return $this; }
    public function getIcone(): ?string { return $this->icone; }
    public function setIcone(?string $icone): static { $this->icone = $icone; return $this; }
    public function getCouleur(): ?string { return $this->couleur; }
    public function setCouleur(?string $couleur): static { $this->couleur = $couleur; return $this; }
    public function getDestinataire(): ?Utilisateur { return $this->destinataire; }
    public function setDestinataire(?Utilisateur $destinataire): static { $this->destinataire = $destinataire; return $this; }
    public function getTrajet(): ?Trajet { return $this->trajet; }
    public function setTrajet(?Trajet $trajet): static { $this->trajet = $trajet; return $this; }
    public function getReservation(): ?Reservation { return $this->reservation; }
    public function setReservation(?Reservation $reservation): static { $this->reservation = $reservation; return $this; }
}