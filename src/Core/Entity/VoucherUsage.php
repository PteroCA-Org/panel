<?php

namespace App\Core\Entity;

use App\Core\Contract\UserInterface;
use App\Core\Repository\VoucherUsageRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VoucherUsageRepository::class)]
class VoucherUsage extends AbstractEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Voucher::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Voucher $voucher = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?UserInterface $user = null;

    #[ORM\Column(type: "datetime")]
    private DateTimeInterface $usedAt;

    public function __construct()
    {
        $this->usedAt = new DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVoucher(): ?Voucher
    {
        return $this->voucher;
    }

    public function setVoucher(Voucher $voucher): self
    {
        $this->voucher = $voucher;
        return $this;
    }

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    public function setUser(UserInterface $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getUsedAt(): DateTimeInterface
    {
        return $this->usedAt;
    }

    public function setUsedAt(DateTimeInterface $usedAt): self
    {
        $this->usedAt = $usedAt;
        return $this;
    }
}
