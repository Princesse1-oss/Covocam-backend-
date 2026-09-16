<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fichier_upload')]
class FichierUpload
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $dossier = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(type: 'blob')]
    private $donnees;

    #[ORM\Column()]
    private ?int $taille = null;

    #[ORM\Column(length: 100)]
    private ?string $typeMime = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dateUpload = null;

    public function __construct()
    {
        $this->dateUpload = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getDossier(): ?string { return $this->dossier; }
    public function setDossier(string $dossier): static { $this->dossier = $dossier; return $this; }

    public function getNom(): ?string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = $nom; return $this; }

    public function getDonnees()
    {
        return $this->donnees;
    }

    /** @param string|resource $donnees */
    public function setDonnees($donnees): static
    {
        if (is_resource($donnees)) {
            rewind($donnees);
            $donnees = stream_get_contents($donnees);
        }
        $this->donnees = $donnees;
        return $this;
    }

    /** Données binaires sous forme de chaîne (BYTEA dans Neon) */
    public function getDonneesString(): string
    {
        $data = $this->donnees;
        if (is_resource($data)) {
            rewind($data);
            return stream_get_contents($data);
        }
        return is_string($data) ? $data : '';
    }

    public function getTaille(): ?int { return $this->taille; }
    public function setTaille(int $taille): static { $this->taille = $taille; return $this; }

    public function getTypeMime(): ?string { return $this->typeMime; }
    public function setTypeMime(string $typeMime): static { $this->typeMime = $typeMime; return $this; }

    public function getDateUpload(): ?\DateTimeImmutable { return $this->dateUpload; }
    public function setDateUpload(\DateTimeImmutable $dateUpload): static { $this->dateUpload = $dateUpload; return $this; }
}
