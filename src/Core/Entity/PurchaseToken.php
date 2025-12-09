<?php

namespace App\Core\Entity;

use App\Core\Repository\PurchaseTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PurchaseTokenRepository::class)]
#[ORM\Table(name: 'purchase_token')]
#[ORM\Index(columns: ['token'], name: 'idx_token')]
#[ORM\Index(columns: ['created_at'], name: 'idx_created_at')]
#[ORM\HasLifecycleCallbacks]
class PurchaseToken extends AbstractEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $token;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 10)]
    private string $type;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTime();
    }

    public function isExpired(int $ttlInSeconds = 3600): bool
    {
        $now = new \DateTime();
        $expirationTime = (clone $this->createdAt)->modify("+{$ttlInSeconds} seconds");

        return $now > $expirationTime;
    }
}
