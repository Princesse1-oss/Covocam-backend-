<?php

namespace App\Entity;

use App\Repository\LieuRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LieuRepository::class)]
class Lieu
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $nom = null;

    #[ORM\Column(length: 30)]
    private ?string $type = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $departement = null;

    #[ORM\Column(length: 50)]
    private ?string $pays = 'Cameroun';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private ?bool $estActif = true;

    #[ORM\Column]
    private ?bool $estPrincipal = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateModification = null;

    // Auto-référence (hiérarchie des lieux)
    #[ORM\ManyToOne(targetEntity: Lieu::class, inversedBy: 'sousLieux')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Lieu $lieuParent = null;

    #[ORM\OneToMany(targetEntity: Lieu::class, mappedBy: 'lieuParent')]
    private Collection $sousLieux;

    // Relation avec Utilisateur
    #[ORM\OneToMany(targetEntity: Utilisateur::class, mappedBy: 'ville')]
    private Collection $utilisateursVille;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
        $this->estActif = true;
        $this->estPrincipal = false;
        $this->sousLieux = new ArrayCollection();
        $this->utilisateursVille = new ArrayCollection();
    }

    // GETTERS ET SETTERS
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;
        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;
        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;
        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = $codePostal;
        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): static
    {
        $this->region = $region;
        return $this;
    }

    public function getDepartement(): ?string
    {
        return $this->departement;
    }

    public function setDepartement(?string $departement): static
    {
        $this->departement = $departement;
        return $this;
    }

    public function getPays(): ?string
    {
        return $this->pays;
    }

    public function setPays(string $pays): static
    {
        $this->pays = $pays;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function isEstActif(): ?bool
    {
        return $this->estActif;
    }

    public function setEstActif(bool $estActif): static
    {
        $this->estActif = $estActif;
        return $this;
    }

    public function isEstPrincipal(): ?bool
    {
        return $this->estPrincipal;
    }

    public function setEstPrincipal(bool $estPrincipal): static
    {
        $this->estPrincipal = $estPrincipal;
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

    public function getLieuParent(): ?Lieu
    {
        return $this->lieuParent;
    }

    public function setLieuParent(?Lieu $lieuParent): static
    {
        $this->lieuParent = $lieuParent;
        return $this;
    }

    /**
     * @return Collection<int, Lieu>
     */
    public function getSousLieux(): Collection
    {
        return $this->sousLieux;
    }

    public function addSousLieu(Lieu $sousLieu): static
    {
        if (!$this->sousLieux->contains($sousLieu)) {
            $this->sousLieux->add($sousLieu);
            $sousLieu->setLieuParent($this);
        }
        return $this;
    }

    public function removeSousLieu(Lieu $sousLieu): static
    {
        if ($this->sousLieux->removeElement($sousLieu)) {
            if ($sousLieu->getLieuParent() === $this) {
                $sousLieu->setLieuParent(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Utilisateur>
     */
    public function getUtilisateursVille(): Collection
    {
        return $this->utilisateursVille;
    }

    public function addUtilisateursVille(Utilisateur $utilisateur): static
    {
        if (!$this->utilisateursVille->contains($utilisateur)) {
            $this->utilisateursVille->add($utilisateur);
            $utilisateur->setVille($this);
        }
        return $this;
    }

    public function removeUtilisateursVille(Utilisateur $utilisateur): static
    {
        if ($this->utilisateursVille->removeElement($utilisateur)) {
            if ($utilisateur->getVille() === $this) {
                $utilisateur->setVille(null);
            }
        }
        return $this;
    }
}