<?php

namespace App\Core\Entity;

use App\Core\Enum\PluginStateEnum;
use App\Core\Repository\PluginRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PluginRepository::class)]
#[ORM\Table(name: 'plugin')]
#[ORM\HasLifecycleCallbacks]
class Plugin extends AbstractEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50, unique: true)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $displayName;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $version;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $license;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PluginStateEnum::class)]
    private PluginStateEnum $state;

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $path;

    #[ORM\Column(type: Types::JSON)]
    private array $manifest;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $pterocaMinVersion;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $pterocaMaxVersion = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $enabledAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $disabledAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $faultReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->state = PluginStateEnum::DISCOVERED;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): self
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

    public function setAuthor(string $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getLicense(): string
    {
        return $this->license;
    }

    public function setLicense(string $license): self
    {
        $this->license = $license;

        return $this;
    }

    public function getState(): PluginStateEnum
    {
        return $this->state;
    }

    public function setState(PluginStateEnum $state): self
    {
        $this->state = $state;

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getManifest(): array
    {
        return $this->manifest;
    }

    public function setManifest(array $manifest): self
    {
        $this->manifest = $manifest;

        return $this;
    }

    public function getPterocaMinVersion(): string
    {
        return $this->pterocaMinVersion;
    }

    public function setPterocaMinVersion(string $pterocaMinVersion): self
    {
        $this->pterocaMinVersion = $pterocaMinVersion;

        return $this;
    }

    public function getPterocaMaxVersion(): ?string
    {
        return $this->pterocaMaxVersion;
    }

    public function setPterocaMaxVersion(?string $pterocaMaxVersion): self
    {
        $this->pterocaMaxVersion = $pterocaMaxVersion;

        return $this;
    }

    public function getEnabledAt(): ?DateTimeImmutable
    {
        return $this->enabledAt;
    }

    public function setEnabledAt(?DateTimeImmutable $enabledAt): self
    {
        $this->enabledAt = $enabledAt;

        return $this;
    }

    public function getDisabledAt(): ?DateTimeImmutable
    {
        return $this->disabledAt;
    }

    public function setDisabledAt(?DateTimeImmutable $disabledAt): self
    {
        $this->disabledAt = $disabledAt;

        return $this;
    }

    public function getFaultReason(): ?string
    {
        return $this->faultReason;
    }

    public function setFaultReason(?string $faultReason): self
    {
        $this->faultReason = $faultReason;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isEnabled(): bool
    {
        return $this->state->isActive();
    }

    public function isFaulted(): bool
    {
        return $this->state->isFaulted();
    }

    /**
     * @return string[]
     */
    public function getCapabilities(): array
    {
        return $this->manifest['capabilities'] ?? [];
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->getCapabilities(), true);
    }

    /**
     * @return array<string, string> Map of plugin name => version constraint
     */
    public function getRequires(): array
    {
        return $this->manifest['requires'] ?? [];
    }

    public function getConfigSchema(): array
    {
        return $this->manifest['config_schema'] ?? [];
    }

    public function getBootstrapClass(): ?string
    {
        return $this->manifest['bootstrap_class'] ?? null;
    }

    public function markAsEnabled(): self
    {
        $this->state = PluginStateEnum::ENABLED;
        $this->enabledAt = new DateTimeImmutable();
        $this->disabledAt = null;
        $this->faultReason = null;

        return $this;
    }

    public function markAsDisabled(): self
    {
        $this->state = PluginStateEnum::DISABLED;
        $this->disabledAt = new DateTimeImmutable();

        return $this;
    }

    public function markAsFaulted(string $reason): self
    {
        $this->state = PluginStateEnum::FAULTED;
        $this->faultReason = $reason;

        return $this;
    }

    public function markAsRegistered(): self
    {
        $this->state = PluginStateEnum::REGISTERED;
        $this->faultReason = null;

        return $this;
    }
}
