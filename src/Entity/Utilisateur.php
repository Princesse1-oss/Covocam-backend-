<?php

namespace App\Entity;

use App\Repository\UtilisateurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[UniqueEntity(fields: ['email'], message: 'Cette adresse email est déjà utilisée par un autre compte.')]
#[UniqueEntity(fields: ['telephone'], message: 'Ce numéro de téléphone est déjà associé à un autre compte.')]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire')]
    #[Assert\Length(min: 2, max: 50, minMessage: 'Le nom doit contenir au moins 2 caractères', maxMessage: 'Le nom ne peut pas dépasser 50 caractères')]
    private ?string $nom = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Le prénom est obligatoire')]
    #[Assert\Length(min: 2, max: 50, minMessage: 'Le prénom doit contenir au moins 2 caractères', maxMessage: 'Le prénom ne peut pas dépasser 50 caractères')]
    private ?string $prenom = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: 'L\'email est obligatoire')]
    #[Assert\Email(message: 'L\'email n\'est pas valide')]
    private ?string $email = null;

    #[ORM\Column]
    private ?string $motDePasse = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Regex(pattern: '/^[0-9+\s]{9,20}$/', message: 'Le numéro de téléphone n\'est pas valide')]
    private ?string $telephone = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dateDeblocage = null;


    #[ORM\Column(length: 20, nullable: true)]
    private ?string $typeUtilisateur = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $biographie = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $preferencesVoyage = null;

    // ✅ NOTE MOYENNE DU CONDUCTEUR
    #[ORM\Column(type: 'float', nullable: true, options: ['default' => 0.0])]
    private ?float $noteMoyenne = 0.0;

    // ✅ NOMBRE TOTAL D'ÉVALUATIONS (pour le tri et l'affichage)
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $totalEvaluations = 0;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Le statut actif est obligatoire')]
    private ?bool $estActif = true;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateModification = null;

    // ==========================================
    // RELATIONS
    // ==========================================
    #[ORM\OneToMany(targetEntity: Vehicule::class, mappedBy: 'utilisateur', cascade: ['persist', 'remove'])]
    private Collection $vehicules;

    #[ORM\OneToMany(targetEntity: Trajet::class, mappedBy: 'conducteur')]
    private Collection $trajetsConduits;

    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'passager')]
    private Collection $reservations;

    #[ORM\OneToMany(targetEntity: Evaluation::class, mappedBy: 'auteur')]
    private Collection $evaluationsDonnees;

    #[ORM\OneToMany(targetEntity: Evaluation::class, mappedBy: 'cible')]
    private Collection $evaluationsRecues;

    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'expediteur')]
    private Collection $messagesEnvoyes;

    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'destinataire')]
    private Collection $messagesRecus;

    #[ORM\OneToMany(targetEntity: Notification::class, mappedBy: 'destinataire')]
    private Collection $notifications;

    #[ORM\OneToMany(targetEntity: ConfirmationPresence::class, mappedBy: 'utilisateur')]
    private Collection $confirmationPresences;

    #[ORM\ManyToOne(targetEntity: Lieu::class, inversedBy: 'utilisateursVille')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Lieu $ville = null;

    // ==========================================
    // CONSTRUCTEUR
    // ==========================================
    public function __construct()
    {
        $this->roles = ['ROLE_USER'];
        $this->dateCreation = new \DateTimeImmutable();
        $this->estActif = true;
        $this->vehicules = new ArrayCollection();
        $this->trajetsConduits = new ArrayCollection();
        $this->reservations = new ArrayCollection();
        $this->evaluationsDonnees = new ArrayCollection();
        $this->evaluationsRecues = new ArrayCollection();
        $this->messagesEnvoyes = new ArrayCollection();
        $this->messagesRecus = new ArrayCollection();
        $this->notifications = new ArrayCollection();
        $this->confirmationPresences = new ArrayCollection(); // ✅ Initialisé
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->dateModification = new \DateTimeImmutable();
    }

    // ✅ MÉTHODE DE CALCUL AUTOMATIQUE DE LA MOYENNE
    public function calculerNoteMoyenne(): void
    {
        $count = $this->evaluationsRecues->count();
        $this->totalEvaluations = $count;

        if ($count === 0) {
            $this->noteMoyenne = 0.0;
            return;
        }

        $total = 0;
        foreach ($this->evaluationsRecues as $evaluation) {
            $total += $evaluation->getNote();
        }

        // Arrondi à 1 décimale (ex: 4.5)
        $this->noteMoyenne = round($total / $count, 1);
    }

    // ==========================================
    // GETTERS ET SETTERS (Sélectionnés pour la clarté)
    // ==========================================
    public function getId(): ?int { return $this->id; }

    public function getNom(): ?string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = $nom; return $this; }

    
    public function getPrenom(): ?string { return $this->prenom; }
    public function setPrenom(string $prenom): static { $this->prenom = $prenom; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getMotDePasse(): ?string { return $this->motDePasse; }
    public function setMotDePasse(string $motDePasse): static { $this->motDePasse = $motDePasse; return $this; }

    public function getTelephone(): ?string { return $this->telephone; }
    public function setTelephone(?string $telephone): static { $this->telephone = $telephone; return $this; }

    public function getTypeUtilisateur(): ?string { return $this->typeUtilisateur; }
    public function setTypeUtilisateur(?string $typeUtilisateur): static { $this->typeUtilisateur = $typeUtilisateur; return $this; }

    public function getPhoto(): ?string { return $this->photo; }
    public function setPhoto(?string $photo): static { $this->photo = $photo; return $this; }

    public function getBiographie(): ?string { return $this->biographie; }
    public function setBiographie(?string $biographie): static { $this->biographie = $biographie; return $this; }

    public function getPreferencesVoyage(): ?array { return $this->preferencesVoyage; }
    public function setPreferencesVoyage(?array $preferencesVoyage): static { $this->preferencesVoyage = $preferencesVoyage; return $this; }

    public function getNoteMoyenne(): ?float { return $this->noteMoyenne; }
    public function setNoteMoyenne(?float $noteMoyenne): static { $this->noteMoyenne = $noteMoyenne; return $this; }

    public function getTotalEvaluations(): int { return $this->totalEvaluations; }
    public function setTotalEvaluations(int $totalEvaluations): static { $this->totalEvaluations = $totalEvaluations; return $this; }

    public function isEstActif(): ?bool { return $this->estActif; }
    public function setEstActif(bool $estActif): static { $this->estActif = $estActif; return $this; }

    public function getDateCreation(): ?\DateTimeImmutable { return $this->dateCreation; }
    public function setDateCreation(\DateTimeImmutable $dateCreation): static { $this->dateCreation = $dateCreation; return $this; }

    public function getDateModification(): ?\DateTimeImmutable { return $this->dateModification; }
    public function setDateModification(?\DateTimeImmutable $dateModification): static { $this->dateModification = $dateModification; return $this; }

    public function getVille(): ?Lieu { return $this->ville; }
    public function setVille(?Lieu $ville): static { $this->ville = $ville; return $this; }

    // --- Collections ---
    public function getVehicules(): Collection { return $this->vehicules; }
    public function addVehicule(Vehicule $vehicule): static {
        if (!$this->vehicules->contains($vehicule)) { $this->vehicules->add($vehicule); $vehicule->setUtilisateur($this); }
        return $this;
    }
    public function removeVehicule(Vehicule $vehicule): static {
        if ($this->vehicules->removeElement($vehicule) && $vehicule->getUtilisateur() === $this) { $vehicule->setUtilisateur(null); }
        return $this;
    }

    public function getTrajetsConduits(): Collection { return $this->trajetsConduits; }
    public function addTrajetConduit(Trajet $trajet): static {
        if (!$this->trajetsConduits->contains($trajet)) { $this->trajetsConduits->add($trajet); $trajet->setConducteur($this); }
        return $this;
    }
    public function removeTrajetConduit(Trajet $trajet): static {
        if ($this->trajetsConduits->removeElement($trajet) && $trajet->getConducteur() === $this) { $trajet->setConducteur(null); }
        return $this;
    }

    public function getReservations(): Collection { return $this->reservations; }
    public function addReservation(Reservation $reservation): static {
        if (!$this->reservations->contains($reservation)) { $this->reservations->add($reservation); $reservation->setPassager($this); }
        return $this;
    }
    public function removeReservation(Reservation $reservation): static {
        if ($this->reservations->removeElement($reservation) && $reservation->getPassager() === $this) { $reservation->setPassager(null); }
        return $this;
    }

    public function getEvaluationsDonnees(): Collection { return $this->evaluationsDonnees; }
    public function addEvaluationDonnee(Evaluation $evaluation): static {
        if (!$this->evaluationsDonnees->contains($evaluation)) { $this->evaluationsDonnees->add($evaluation); $evaluation->setAuteur($this); }
        return $this;
    }
    public function removeEvaluationDonnee(Evaluation $evaluation): static {
        if ($this->evaluationsDonnees->removeElement($evaluation) && $evaluation->getAuteur() === $this) { $evaluation->setAuteur(null); }
        return $this;
    }
    

    public function getEvaluationsRecues(): Collection { return $this->evaluationsRecues; }
    public function addEvaluationRecue(Evaluation $evaluation): static {
        if (!$this->evaluationsRecues->contains($evaluation)) { $this->evaluationsRecues->add($evaluation); $evaluation->setCible($this); }
        return $this;
    }
    public function removeEvaluationRecue(Evaluation $evaluation): static {
        if ($this->evaluationsRecues->removeElement($evaluation) && $evaluation->getCible() === $this) { $evaluation->setCible(null); }
        return $this;
    }

    public function getMessagesEnvoyes(): Collection { return $this->messagesEnvoyes; }
    public function addMessageEnvoye(Message $message): static {
        if (!$this->messagesEnvoyes->contains($message)) { $this->messagesEnvoyes->add($message); $message->setExpediteur($this); }
        return $this;
    }
    public function removeMessageEnvoye(Message $message): static {
        if ($this->messagesEnvoyes->removeElement($message) && $message->getExpediteur() === $this) { $message->setExpediteur(null); }
        return $this;
    }

    public function getMessagesRecus(): Collection { return $this->messagesRecus; }
    public function addMessageRecu(Message $message): static {
        if (!$this->messagesRecus->contains($message)) { $this->messagesRecus->add($message); $message->setDestinataire($this); }
        return $this;
    }
    public function removeMessageRecu(Message $message): static {
        if ($this->messagesRecus->removeElement($message) && $message->getDestinataire() === $this) { $message->setDestinataire(null); }
        return $this;
    }

    public function getConfirmationPresences(): Collection { return $this->confirmationPresences; }
    public function addConfirmationPresence(ConfirmationPresence $confirmationPresence): static {
        if (!$this->confirmationPresences->contains($confirmationPresence)) { $this->confirmationPresences->add($confirmationPresence); $confirmationPresence->setUtilisateur($this); }
        return $this;
    }
    public function removeConfirmationPresence(ConfirmationPresence $confirmationPresence): static {
        if ($this->confirmationPresences->removeElement($confirmationPresence) && $confirmationPresence->getUtilisateur() === $this) { $confirmationPresence->setUtilisateur(null); }
        return $this;
    }

    public function getNotifications(): Collection { return $this->notifications; }
    public function addNotification(Notification $notification): static {
        if (!$this->notifications->contains($notification)) { $this->notifications->add($notification); $notification->setDestinataire($this); }
        return $this;
    }
    public function removeNotification(Notification $notification): static {
        if ($this->notifications->removeElement($notification) && $notification->getDestinataire() === $this) { $notification->setDestinataire(null); }
        return $this;
    }

    // ==========================================
    // UserInterface Methods
    // ==========================================
    public function getUserIdentifier(): string { return (string) $this->email; }
    public function getRoles(): array { $roles = $this->roles; $roles[] = 'ROLE_USER'; return array_unique($roles); }
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }
    public function getPassword(): ?string { return $this->motDePasse; }
    public function setPassword(string $motDePasse): static { $this->motDePasse = $motDePasse; return $this; }
    public function eraseCredentials(): void {}

    public function getDateDeblocage(): ?\DateTimeInterface { return $this->dateDeblocage; }
    public function setDateDeblocage(?\DateTimeInterface $dateDeblocage): static { 
        $this->dateDeblocage = $dateDeblocage; 
        return $this; 
    }
}