<?php

namespace App\Core\Entity;

use App\Core\Contract\UserInterface;
use App\Core\Repository\ServerRepository;
use DateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServerRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Server extends AbstractEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $pterodactylServerId;

    #[ORM\Column(type: 'string')]
    private string $pterodactylServerIdentifier;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $name = null;

    #[ORM\OneToOne(targetEntity: ServerProduct::class, mappedBy: 'server', cascade: ['persist', 'remove'])]
    private ?ServerProduct $serverProduct = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private UserInterface $user;

    #[ORM\Column(type: 'datetime')]
    private DateTime $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTime $deletedAt = null;

    #[ORM\Column(type: 'datetime')]
    private DateTime $expiresAt;

    #[ORM\Column(type: 'boolean')]
    private bool $isSuspended = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $autoRenewal = false;

    /**
     * Non-persisted property for storing full image path in presentation layer
     * Set dynamically in controllers/services, not stored in database
     */
    private ?string $imagePath = null;

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPterodactylServerId(): int
    {
        return $this->pterodactylServerId;
    }

    public function setPterodactylServerId(int $pterodactylServerId): self
    {
        $this->pterodactylServerId = $pterodactylServerId;
        return $this;
    }

    public function getPterodactylServerIdentifier(): string
    {
        return $this->pterodactylServerIdentifier;
    }

    public function setPterodactylServerIdentifier(string $pterodactylServerIdentifier): self
    {
        $this->pterodactylServerIdentifier = $pterodactylServerIdentifier;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getServerProduct(): ?ServerProduct
    {
        return $this->serverProduct;
    }

    public function setServerProduct(?ServerProduct $serverProduct): self
    {
        $this->serverProduct = $serverProduct;
        return $this;
    }

    public function getUser(): UserInterface
    {
        return $this->user;
    }

    public function setUser(UserInterface $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function setDeletedAtValue(): void
    {
        $this->deletedAt = new DateTime();

        if (!$this->serverProduct) {
            return;
        }

        foreach ($this->serverProduct->getPrices() as $price) {
            $price->setDeletedAt($this->deletedAt);
        }
    }

    public function getDeletedAt(): ?DateTime
    {
        return $this->deletedAt;
    }

    public function getExpiresAt(): DateTime
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(DateTime $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getIsSuspended(): bool
    {
        return $this->isSuspended;
    }

    public function setIsSuspended(bool $isSuspended): self
    {
        $this->isSuspended = $isSuspended;
        return $this;
    }

    public function isAutoRenewal(): bool
    {
        return $this->autoRenewal;
    }

    public function setAutoRenewal(bool $autoRenewal): self
    {
        $this->autoRenewal = $autoRenewal;
        return $this;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): self
    {
        $this->imagePath = $imagePath;
        return $this;
    }

    public function __toString(): string
    {
        return $this->pterodactylServerIdentifier;
    }
}
