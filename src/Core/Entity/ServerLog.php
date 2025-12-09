<?php

namespace App\Core\Entity;

use App\Core\Contract\UserInterface;
use App\Core\Repository\ServerLogRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServerLogRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ServerLog extends AbstractEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private int $id;

    #[ORM\Column(type: "string", length: 255)]
    private string $actionId;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $details = null;

    #[ORM\Column(type: "datetime")]
    private DateTimeInterface $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private UserInterface $user;

    #[ORM\ManyToOne(targetEntity: Server::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Server $server;

    #[ORM\PrePersist]
    public function setCreatedAtValue(): self
    {
        $this->createdAt = new DateTime();

        return $this;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getActionId(): string
    {
        return $this->actionId;
    }

    public function setActionId(string $actionId): self
    {
        $this->actionId = $actionId;
        return $this;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): self
    {
        $this->details = $details;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
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

    public function getServer(): Server
    {
        return $this->server;
    }

    public function setServer(Server $server): self
    {
        $this->server = $server;
        return $this;
    }
}
