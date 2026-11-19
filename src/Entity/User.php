<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Repository\UserRepository;
use App\State\MeProvider;
use App\State\UserRegisterProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Vue API mobile de la table `user` (partagée avec le web).
 * La modération (warnings, ban actif, rôles admin) est gérée côté WEB :
 * l'API n'en garde que `account_status` pour REFUSER la connexion d'un compte banni.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Cet email est déjà utilisé.')]
#[UniqueEntity(fields: ['username'], message: 'Ce pseudo est déjà utilisé.')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        // Inscription : POST /api/register { email, username, plainPassword }
        new Post(
            uriTemplate: '/register',
            normalizationContext: ['groups' => ['user:read']],
            denormalizationContext: ['groups' => ['user:create']],
            validationContext: ['groups' => ['Default', 'user:create']],
            processor: UserRegisterProcessor::class,
        ),
        // Profil courant : GET /api/me (JWT requis)
        new Get(
            uriTemplate: '/me',
            normalizationContext: ['groups' => ['user:read']],
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: MeProvider::class,
        ),
    ],
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    private const ALLOWED_STATUSES = ['pending', 'active', 'banned', 'deleted'];

    // ========== IDENTITÉ ==========

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email(message: 'Email invalide.')]
    #[Groups(['user:read', 'user:create'])]
    private ?string $email = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Groups(['user:read', 'user:create'])]
    private ?string $username = null;

    #[ORM\Column]
    private ?string $password = null;

    /**
     * Mot de passe en clair (jamais persisté) : hashé à l'inscription par UserRegisterProcessor.
     */
    #[Assert\NotBlank(groups: ['user:create'])]
    #[Assert\Length(min: 8, minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.', groups: ['user:create'])]
    #[Groups(['user:create'])]
    private ?string $plainPassword = null;

    /**
     * Acceptation des CGU à l'inscription (jamais persistée telle quelle) : exigée à `true`,
     * puis horodatée dans `termsAcceptedAt` par UserRegisterProcessor.
     */
    #[Assert\IsTrue(message: 'Tu dois accepter les conditions d’utilisation.', groups: ['user:create'])]
    #[Groups(['user:create'])]
    private bool $acceptedTerms = false;

    // Date d'acceptation des CGU (trace légale). Null = compte antérieur à cette trace.
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $termsAcceptedAt = null;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    // ========== PROFIL ==========

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read'])]
    private ?string $avatarUrl = null;

    #[ORM\ManyToOne(targetEntity: Line::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['user:read'])]
    private ?Line $favoriteLine = null;

    // ========== STATUT DU COMPTE (lecture seule côté API — décidé sur le web) ==========

    #[ORM\Column(length: 20)]
    #[Groups(['user:read'])]
    private string $accountStatus = 'pending';

    // Date de fin de l'onboarding mobile (null = pas encore vu). Suit le compte (multi-appareil).
    #[ORM\Column(nullable: true)]
    #[Groups(['user:read'])]
    private ?\DateTimeImmutable $onboardingCompletedAt = null;

    // ========== VÉRIFICATION EMAIL ==========

    #[ORM\Column]
    private bool $isEmailVerified = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emailVerificationToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerificationTokenExpiresAt = null;

    // ========== RÉINITIALISATION MOT DE PASSE ==========

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passwordResetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordResetTokenExpiresAt = null;

    // ========== CONNEXION ==========

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    // ========== TIMESTAMPS ==========

    #[ORM\Column]
    #[Groups(['user:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // ========== RELATIONS ==========

    /**
     * @var Collection<int, UserStation>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserStation::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $userStations;

    /**
     * @var Collection<int, Badge>
     */
    #[ORM\ManyToMany(targetEntity: Badge::class, mappedBy: 'users')]
    private Collection $badges;

    /**
     * IDs des badges à afficher sur le profil (max 3)
     */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['user:read'])]
    private array $displayedBadges = [];

    // ========== CONSTRUCTEUR & LIFECYCLE ==========

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->roles = ['ROLE_USER'];
        $this->userStations = new ArrayCollection();
        $this->badges = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ========== IDENTITÉ ==========

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;
        return $this;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    // ========== RÔLES ==========

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    // ========== PROFIL ==========

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function setAvatarUrl(?string $avatarUrl): static
    {
        $this->avatarUrl = $avatarUrl;
        return $this;
    }

    public function getFavoriteLine(): ?Line
    {
        return $this->favoriteLine;
    }

    public function setFavoriteLine(?Line $favoriteLine): static
    {
        $this->favoriteLine = $favoriteLine;
        return $this;
    }

    // ========== STATUT DU COMPTE ==========

    public function getAccountStatus(): string
    {
        return $this->accountStatus;
    }

    public function setAccountStatus(string $accountStatus): static
    {
        if (!in_array($accountStatus, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException('Statut de compte invalide : ' . $accountStatus);
        }
        $this->accountStatus = $accountStatus;
        return $this;
    }

    public function isPending(): bool
    {
        return $this->accountStatus === 'pending';
    }

    public function isActive(): bool
    {
        return $this->accountStatus === 'active';
    }

    public function isBanned(): bool
    {
        return $this->accountStatus === 'banned';
    }

    public function isDeleted(): bool
    {
        return $this->accountStatus === 'deleted';
    }

    public function activate(): static
    {
        $this->accountStatus = 'active';
        return $this;
    }

    // ========== VÉRIFICATION EMAIL ==========

    #[Groups(['user:read'])]
    #[SerializedName('isEmailVerified')]
    public function isEmailVerified(): bool
    {
        return $this->isEmailVerified;
    }

    public function setIsEmailVerified(bool $isEmailVerified): static
    {
        $this->isEmailVerified = $isEmailVerified;
        return $this;
    }

    public function getEmailVerificationToken(): ?string
    {
        return $this->emailVerificationToken;
    }

    public function setEmailVerificationToken(?string $emailVerificationToken): static
    {
        $this->emailVerificationToken = $emailVerificationToken;
        return $this;
    }

    public function getEmailVerificationTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->emailVerificationTokenExpiresAt;
    }

    public function setEmailVerificationTokenExpiresAt(?\DateTimeImmutable $emailVerificationTokenExpiresAt): static
    {
        $this->emailVerificationTokenExpiresAt = $emailVerificationTokenExpiresAt;
        return $this;
    }

    /** Code à 6 chiffres saisi dans l'app (vérification 100% in-app). */
    public function generateEmailVerificationToken(): static
    {
        $this->emailVerificationToken = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->emailVerificationTokenExpiresAt = new \DateTimeImmutable('+24 hours');
        return $this;
    }

    public function isEmailVerificationTokenValid(): bool
    {
        if (!$this->emailVerificationToken || !$this->emailVerificationTokenExpiresAt) {
            return false;
        }
        return $this->emailVerificationTokenExpiresAt > new \DateTimeImmutable();
    }

    public function verifyEmail(): static
    {
        $this->isEmailVerified = true;
        $this->emailVerificationToken = null;
        $this->emailVerificationTokenExpiresAt = null;
        $this->activate();
        return $this;
    }

    // ========== RÉINITIALISATION MOT DE PASSE ==========

    public function getPasswordResetToken(): ?string
    {
        return $this->passwordResetToken;
    }

    public function setPasswordResetToken(?string $passwordResetToken): static
    {
        $this->passwordResetToken = $passwordResetToken;
        return $this;
    }

    public function getPasswordResetTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->passwordResetTokenExpiresAt;
    }

    public function setPasswordResetTokenExpiresAt(?\DateTimeImmutable $passwordResetTokenExpiresAt): static
    {
        $this->passwordResetTokenExpiresAt = $passwordResetTokenExpiresAt;
        return $this;
    }

    /**
     * Génère un code à 6 chiffres (flux mobile). Court, donc expiration brève (15 min).
     * Stocké dans la même colonne que le token web ; la vérification se fait par email + code.
     */
    public function generatePasswordResetToken(): static
    {
        $this->passwordResetToken = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->passwordResetTokenExpiresAt = new \DateTimeImmutable('+15 minutes');
        return $this;
    }

    public function isPasswordResetTokenValid(): bool
    {
        if (!$this->passwordResetToken || !$this->passwordResetTokenExpiresAt) {
            return false;
        }
        return $this->passwordResetTokenExpiresAt > new \DateTimeImmutable();
    }

    public function resetPassword(string $newPassword): static
    {
        $this->password = $newPassword;
        $this->passwordResetToken = null;
        $this->passwordResetTokenExpiresAt = null;
        return $this;
    }

    // ========== ONBOARDING ==========

    public function getAcceptedTerms(): bool
    {
        return $this->acceptedTerms;
    }

    public function setAcceptedTerms(bool $acceptedTerms): static
    {
        $this->acceptedTerms = $acceptedTerms;

        return $this;
    }

    public function getTermsAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function setTermsAcceptedAt(?\DateTimeImmutable $termsAcceptedAt): static
    {
        $this->termsAcceptedAt = $termsAcceptedAt;

        return $this;
    }

    public function getOnboardingCompletedAt(): ?\DateTimeImmutable
    {
        return $this->onboardingCompletedAt;
    }

    public function hasCompletedOnboarding(): bool
    {
        return $this->onboardingCompletedAt !== null;
    }

    public function completeOnboarding(): static
    {
        $this->onboardingCompletedAt ??= new \DateTimeImmutable();
        return $this;
    }

    // ========== CONNEXION ==========

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function updateLastLogin(): static
    {
        $this->lastLoginAt = new \DateTimeImmutable();
        return $this;
    }

    // ========== TIMESTAMPS ==========

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    // ========== STATIONS (progression) ==========

    /**
     * @return Collection<int, UserStation>
     */
    public function getUserStations(): Collection
    {
        return $this->userStations;
    }

    public function addUserStation(UserStation $userStation): static
    {
        if (!$this->userStations->contains($userStation)) {
            $this->userStations->add($userStation);
            $userStation->setUser($this);
        }
        return $this;
    }

    public function removeUserStation(UserStation $userStation): static
    {
        if ($this->userStations->removeElement($userStation)) {
            if ($userStation->getUser() === $this) {
                $userStation->setUser(null);
            }
        }
        return $this;
    }

    // ========== BADGES ==========

    /**
     * @return Collection<int, Badge>
     */
    public function getBadges(): Collection
    {
        return $this->badges;
    }

    public function addBadge(Badge $badge): static
    {
        if (!$this->badges->contains($badge)) {
            $this->badges->add($badge);
            $badge->addUser($this);
        }
        return $this;
    }

    public function removeBadge(Badge $badge): static
    {
        if ($this->badges->removeElement($badge)) {
            $badge->removeUser($this);
        }
        return $this;
    }

    public function getDisplayedBadges(): array
    {
        return $this->displayedBadges;
    }

    public function setDisplayedBadges(array $displayedBadges): static
    {
        $this->displayedBadges = array_slice($displayedBadges, 0, 2);
        return $this;
    }

    public function addDisplayedBadge(int $badgeId): static
    {
        if (!in_array($badgeId, $this->displayedBadges, true) && count($this->displayedBadges) < 2) {
            $this->displayedBadges[] = $badgeId;
        }
        return $this;
    }

    public function removeDisplayedBadge(int $badgeId): static
    {
        $this->displayedBadges = array_values(array_filter(
            $this->displayedBadges,
            fn ($id) => $id !== $badgeId
        ));
        return $this;
    }

    // ========== SÉRIALISATION (security) ==========

    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'password' => $this->password,
            'roles' => $this->roles,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
        $this->password = $data['password'];
        $this->roles = $data['roles'];
    }

    /** Règle des pseudos à l'inscription (la même que sur le site) : voir UsernamePolicy. */
    #[Assert\Callback(groups: ['user:create'])]
    public function validateUsername(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        $error = \App\Validator\UsernamePolicy::error((string) $this->username);
        if ($this->username !== null && $this->username !== '' && $error !== null) {
            $context->buildViolation($error)->atPath('username')->addViolation();
        }
    }
}
