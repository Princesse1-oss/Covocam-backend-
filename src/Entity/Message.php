<?php

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private ?string $contenu = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dateEnvoi = null;

    #[ORM\Column]
    private ?bool $estLu = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateLu = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $typeMessage = null;

    #[ORM\Column]
    private ?bool $estSignale = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $raisonSignalement = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateSignalement = null;

    // RELATIONS CORRECTES
    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'messagesEnvoyes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $expediteur = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'messagesRecus')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $destinataire = null;

    public function __construct()
    {
        $this->dateEnvoi = new \DateTimeImmutable();
        $this->estLu = false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): static
    {
        $this->contenu = $contenu;
        return $this;
    }

    public function getDateEnvoi(): ?\DateTimeImmutable
    {
        return $this->dateEnvoi;
    }

    public function setDateEnvoi(\DateTimeImmutable $dateEnvoi): static
    {
        $this->dateEnvoi = $dateEnvoi;
        return $this;
    }

    public function isEstLu(): ?bool
    {
        return $this->estLu;
    }

    public function setEstLu(bool $estLu): static
    {
        $this->estLu = $estLu;
        return $this;
    }

    public function getDateLu(): ?\DateTimeImmutable
    {
        return $this->dateLu;
    }

    public function setDateLu(?\DateTimeImmutable $dateLu): static
    {
        $this->dateLu = $dateLu;
        return $this;
    }

    public function getTypeMessage(): ?string
    {
        return $this->typeMessage;
    }

    public function setTypeMessage(?string $typeMessage): static
    {
        $this->typeMessage = $typeMessage;
        return $this;
    }

    public function getExpediteur(): ?Utilisateur
    {
        return $this->expediteur;
    }

    public function setExpediteur(?Utilisateur $expediteur): static
    {
        $this->expediteur = $expediteur;
        return $this;
    }

    public function getDestinataire(): ?Utilisateur
    {
        return $this->destinataire;
    }

    public function setDestinataire(?Utilisateur $destinataire): static
    {
        $this->destinataire = $destinataire;
        return $this;
    }

    public function isEstSignale(): ?bool { return $this->estSignale; }
    public function setEstSignale(bool $estSignale): static { $this->estSignale = $estSignale; return $this; }

    public function getRaisonSignalement(): ?string { return $this->raisonSignalement; }
    public function setRaisonSignalement(?string $raisonSignalement): static { $this->raisonSignalement = $raisonSignalement; return $this; }

    public function getDateSignalement(): ?\DateTimeImmutable { return $this->dateSignalement; }
    public function setDateSignalement(?\DateTimeImmutable $dateSignalement): static { $this->dateSignalement = $dateSignalement; return $this; }
}