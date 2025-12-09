<?php

namespace App\Core\Entity;

use App\Core\Contract\UserInterface;
use App\Core\Enum\EmailTypeEnum;
use App\Core\Repository\EmailLogRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailLogRepository::class)]
#[ORM\HasLifecycleCallbacks]
class EmailLog extends AbstractEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private UserInterface $user;

    #[ORM\Column(type: 'string', length: 50, enumType: EmailTypeEnum::class)]
    private EmailTypeEnum $emailType;

    #[ORM\Column(type: 'string', length: 255)]
    private string $emailAddress;

    #[ORM\ManyToOne(targetEntity: Server::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Server $server = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'datetime')]
    private DateTimeInterface $sentAt;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'sent'])]
    private string $status = 'sent';

    #[ORM\PrePersist]
    public function setSentAtValue(): void
    {
        $this->sentAt = new DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getEmailType(): EmailTypeEnum
    {
        return $this->emailType;
    }

    public function setEmailType(EmailTypeEnum $emailType): self
    {
        $this->emailType = $emailType;
        return $this;
    }

    public function getEmailAddress(): string
    {
        return $this->emailAddress;
    }

    public function setEmailAddress(string $emailAddress): self
    {
        $this->emailAddress = $emailAddress;
        return $this;
    }

    public function getServer(): ?Server
    {
        return $this->server;
    }

    public function setServer(?Server $server): self
    {
        $this->server = $server;
        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getSentAt(): DateTimeInterface
    {
        return $this->sentAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }
}
