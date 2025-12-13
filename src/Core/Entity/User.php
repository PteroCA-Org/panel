<?php

namespace App\Core\Entity;

use App\Core\Contract\UserInterface;
use App\Core\Enum\PermissionEnum;
use App\Core\Enum\SystemRoleEnum;
use App\Core\Repository\UserRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[UniqueEntity(fields: ['email'], message: 'pteroca.register.email_already_exists')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class User extends AbstractEntity implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $pterodactylUserId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $pterodactylUserApiKey = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column(type: "decimal", precision: 10, scale: 2)]
    private float $balance = 0;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255)]
    private string $surname;

    #[ORM\Column(type: 'datetime')]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isVerified = false;

    #[ORM\Column(type: 'boolean')]
    private bool $isBlocked = false;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $avatarPath = null;

    #[Vich\UploadableField(mapping: 'user_avatars', fileNameProperty: 'avatarPath')]
    private ?File $avatarFile = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTime $deletedAt = null;

    #[ORM\OneToMany(targetEntity: Log::class, mappedBy: 'user', cascade: ['remove'])]
    private PersistentCollection $logs;

    #[ORM\ManyToMany(targetEntity: Role::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_role')]
    private Collection $userRoles;

    private ?string $plainPassword = null;

    /**
     * @var ?string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    public function __construct()
    {
        $this->userRoles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPterodactylUserId(): ?int
    {
        return $this->pterodactylUserId;
    }

    public function setPterodactylUserId(?int $pterodactylUserId): static
    {
        $this->pterodactylUserId = $pterodactylUserId;

        return $this;
    }

    public function getPterodactylUserApiKey(): ?string
    {
        return $this->pterodactylUserApiKey;
    }

    public function setPterodactylUserApiKey(?string $pterodactylUserApiKey): static
    {
        $this->pterodactylUserApiKey = $pterodactylUserApiKey;

        return $this;
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

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;

        foreach ($this->userRoles as $role) {
            $roles[] = $role->getName();
        }

        $roles[] = SystemRoleEnum::ROLE_USER->value;

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = [];

        $currentRoles = [];
        foreach ($this->userRoles as $role) {
            $currentRoles[] = $role;
        }
        foreach ($currentRoles as $role) {
            $this->removeUserRole($role);
        }

        foreach ($roles as $role) {
            if ($role instanceof Role) {
                $this->addUserRole($role);
            } elseif (is_string($role)) {
                $this->roles[] = $role;
            }
        }

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getBalance(): float
    {
        return $this->balance;
    }

    public function setBalance(float $balance): static
    {
        $this->balance = $balance;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSurname(): string
    {
        return $this->surname;
    }

    public function setSurname(string $surname): static
    {
        $this->surname = $surname;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function isBlocked(): bool
    {
        return $this->isBlocked;
    }

    public function setIsBlocked(bool $isBlocked): static
    {
        $this->isBlocked = $isBlocked;

        return $this;
    }

    public function getAvatarPath(): ?string
    {
        return $this->avatarPath;
    }

    public function setAvatarPath(?string $avatarPath): static
    {
        $this->avatarPath = $avatarPath;

        return $this;
    }

    public function getAvatarFile(): ?File
    {
        return $this->avatarFile;
    }

    public function setAvatarFile(?File $avatarFile = null): static
    {
        $this->avatarFile = $avatarFile;

        if (null !== $avatarFile) {
            // It is required that at least one field changes if you are using doctrine
            // otherwise the event listeners won't be called and the file is lost
            $this->updatedAt = new DateTime();
        }

        return $this;
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new DateTime();
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new DateTime();
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): self
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    public function getDeletedAt(): ?DateTime
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?DateTime $deletedAt): self
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function softDelete(): self
    {
        $this->deletedAt = new DateTime();

        return $this;
    }

    public function restore(): self
    {
        $this->deletedAt = null;

        return $this;
    }

    /**
     * @return Collection<int, Role>
     */
    public function getUserRoles(): Collection
    {
        return $this->userRoles;
    }

    public function addUserRole(Role $role): static
    {
        if (!$this->userRoles->contains($role)) {
            $this->userRoles->add($role);
        }

        return $this;
    }

    public function removeUserRole(Role $role): static
    {
        $this->userRoles->removeElement($role);

        return $this;
    }

    public function hasUserRole(Role $role): bool
    {
        return $this->userRoles->contains($role);
    }

    /**
     * Check if user has a specific permission through their roles
     */
    public function hasPermission(string|PermissionEnum $permissionCode): bool
    {
        $code = $permissionCode instanceof PermissionEnum ? $permissionCode->value : $permissionCode;

        foreach ($this->userRoles as $role) {
            foreach ($role->getPermissions() as $permission) {
                if ($permission->getCode() === $code) {
                    return true;
                }
            }
        }
        return false;
    }

    public function __toString(): string
    {
        return $this->email;
    }
}
