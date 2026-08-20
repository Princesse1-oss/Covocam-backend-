<?php

namespace App\Entity;

use App\Repository\VehiculeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VehiculeRepository::class)]
#[ORM\Table(name: 'vehicule')]
class Vehicule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'vehicules')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'La marque est obligatoire')]
    #[Assert\Length(min: 2, max: 50, minMessage: 'La marque doit contenir au moins 2 caractères', maxMessage: 'La marque ne peut pas dépasser 50 caractères')]
    private ?string $marque = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Le modèle est obligatoire')]
    #[Assert\Length(min: 2, max: 50, minMessage: 'Le modèle doit contenir au moins 2 caractères', maxMessage: 'Le modèle ne peut pas dépasser 50 caractères')]
    private ?string $modele = null;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'L\'année est obligatoire')]
    #[Assert\Range(min: 1990, max: 2100, notInRangeMessage: 'L\'année doit être entre 1990 et 2100')]
    private ?int $annee = null;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: 'La couleur est obligatoire')]
    #[Assert\Length(min: 2, max: 30, minMessage: 'La couleur doit contenir au moins 2 caractères', maxMessage: 'La couleur ne peut pas dépasser 30 caractères')]
    private ?string $couleur = null;

    #[ORM\Column(length: 20, unique: true)]
    #[Assert\NotBlank(message: 'La plaque d\'immatriculation est obligatoire')]
    #[Assert\Regex(pattern: '/^[A-Z]{2}-\d{4}-[A-Z]{2}$/', message: 'Le format de la plaque doit être XX-1234-XX')]
    private ?string $plaqueImmatriculation = null;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'Le nombre de places est obligatoire')]
    #[Assert\Range(min: 1, max: 8, notInRangeMessage: 'Le véhicule doit avoir entre 1 et 8 places')]
    private ?int $places = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $carburant = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $boiteVitesse = null;

    #[ORM\Column]
    private ?bool $climatisation = false;

    #[ORM\Column]
    private ?bool $gps = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoAvant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoArriere = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoInterieur = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoCoffre = null;

    #[ORM\Column]
    private ?bool $estDefaut = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateModification = null;

    #[ORM\OneToMany(targetEntity: Trajet::class, mappedBy: 'vehicule')]
    private Collection $trajets;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
        $this->estDefaut = false;
        $this->climatisation = false;
        $this->gps = false;
        $this->trajets = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getUtilisateur(): ?Utilisateur { return $this->utilisateur; }
    public function setUtilisateur(?Utilisateur $utilisateur): static { $this->utilisateur = $utilisateur; return $this; }

    public function getMarque(): ?string { return $this->marque; }
    public function setMarque(string $marque): static { $this->marque = $marque; return $this; }

    public function getModele(): ?string { return $this->modele; }
    public function setModele(string $modele): static { $this->modele = $modele; return $this; }

    public function getAnnee(): ?int { return $this->annee; }
    public function setAnnee(int $annee): static { $this->annee = $annee; return $this; }

    public function getCouleur(): ?string { return $this->couleur; }
    public function setCouleur(string $couleur): static { $this->couleur = $couleur; return $this; }

    public function getPlaqueImmatriculation(): ?string { return $this->plaqueImmatriculation; }
    public function setPlaqueImmatriculation(string $plaqueImmatriculation): static { 
        $this->plaqueImmatriculation = strtoupper($plaqueImmatriculation); return $this; 
    }

    public function getPlaces(): ?int { return $this->places; }
    public function setPlaces(int $places): static { $this->places = $places; return $this; }

    public function getCarburant(): ?string { return $this->carburant; }
    public function setCarburant(?string $carburant): static { $this->carburant = $carburant; return $this; }

    public function getBoiteVitesse(): ?string { return $this->boiteVitesse; }
    public function setBoiteVitesse(?string $boiteVitesse): static { $this->boiteVitesse = $boiteVitesse; return $this; }

    public function isClimatisation(): ?bool { return $this->climatisation; }
    public function setClimatisation(bool $climatisation): static { $this->climatisation = $climatisation; return $this; }

    public function isGps(): ?bool { return $this->gps; }
    public function setGps(bool $gps): static { $this->gps = $gps; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getPhotoAvant(): ?string { return $this->photoAvant; }
    public function setPhotoAvant(?string $photoAvant): static { $this->photoAvant = $photoAvant; return $this; }

    public function getPhotoArriere(): ?string { return $this->photoArriere; }
    public function setPhotoArriere(?string $photoArriere): static { $this->photoArriere = $photoArriere; return $this; }

    public function getPhotoInterieur(): ?string { return $this->photoInterieur; }
    public function setPhotoInterieur(?string $photoInterieur): static { $this->photoInterieur = $photoInterieur; return $this; }

    public function getPhotoCoffre(): ?string { return $this->photoCoffre; }
    public function setPhotoCoffre(?string $photoCoffre): static { $this->photoCoffre = $photoCoffre; return $this; }

    public function isEstDefaut(): ?bool { return $this->estDefaut; }
    public function setEstDefaut(bool $estDefaut): static { $this->estDefaut = $estDefaut; return $this; }

    public function getDateCreation(): ?\DateTimeImmutable { return $this->dateCreation; }
    public function setDateCreation(\DateTimeImmutable $dateCreation): static { $this->dateCreation = $dateCreation; return $this; }

    public function getDateModification(): ?\DateTimeImmutable { return $this->dateModification; }
    public function setDateModification(?\DateTimeImmutable $dateModification): static { $this->dateModification = $dateModification; return $this; }

    /** @return Collection<int, Trajet> */
    public function getTrajets(): Collection { return $this->trajets; }

    public function addTrajet(Trajet $trajet): static
    {
        if (!$this->trajets->contains($trajet)) {
            $this->trajets->add($trajet);
            $trajet->setVehicule($this);
        }
        return $this;
    }

    public function removeTrajet(Trajet $trajet): static
    {
        if ($this->trajets->removeElement($trajet)) {
            if ($trajet->getVehicule() === $this) {
                $trajet->setVehicule(null);
            }
        }
        return $this;
    }
}