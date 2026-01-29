<?php

namespace App\Core\Entity;

use App\Core\Enum\VoucherTypeEnum;
use App\Core\Repository\VoucherRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VoucherRepository::class)]
class Voucher extends AbstractEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $code;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $value;

    #[ORM\Column(type: 'string', enumType: VoucherTypeEnum::class)]
    private VoucherTypeEnum $type;

    #[ORM\Column(type: 'boolean')]
    private bool $newAccountsOnly = false;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $minimumTopupAmount = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $minimumOrderAmount = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $expirationDate = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxGlobalUses = null;

    #[ORM\Column(type: 'integer')]
    private int $usedCount = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $oneUsePerUser = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'datetime')]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime')]
    private DateTimeInterface $updatedAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $deletedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;
        return $this;
    }

    public function getType(): VoucherTypeEnum
    {
        return $this->type;
    }

    public function setType(VoucherTypeEnum $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function isNewAccountsOnly(): bool
    {
        return $this->newAccountsOnly;
    }

    public function setNewAccountsOnly(bool $newAccountsOnly): self
    {
        $this->newAccountsOnly = $newAccountsOnly;
        return $this;
    }

    public function getMinimumTopupAmount(): ?string
    {
        return $this->minimumTopupAmount;
    }

    public function setMinimumTopupAmount(?string $minimumTopupAmount): self
    {
        $this->minimumTopupAmount = $minimumTopupAmount;
        return $this;
    }

    public function getMinimumOrderAmount(): ?string
    {
        return $this->minimumOrderAmount;
    }

    public function setMinimumOrderAmount(?string $minimumOrderAmount): self
    {
        $this->minimumOrderAmount = $minimumOrderAmount;
        return $this;
    }

    public function getExpirationDate(): ?DateTimeInterface
    {
        return $this->expirationDate;
    }

    public function setExpirationDate(?DateTimeInterface $expirationDate): self
    {
        $this->expirationDate = $expirationDate;
        return $this;
    }

    public function getMaxGlobalUses(): ?int
    {
        return $this->maxGlobalUses;
    }

    public function setMaxGlobalUses(?int $maxGlobalUses): self
    {
        $this->maxGlobalUses = $maxGlobalUses;
        return $this;
    }

    public function getUsedCount(): int
    {
        return $this->usedCount;
    }

    public function setUsedCount(int $usedCount): self
    {
        $this->usedCount = $usedCount;
        return $this;
    }

    public function incrementUsedCount(): self
    {
        $this->usedCount++;
        return $this;
    }

    public function isOneUsePerUser(): bool
    {
        return $this->oneUsePerUser;
    }

    public function setOneUsePerUser(bool $oneUsePerUser): self
    {
        $this->oneUsePerUser = $oneUsePerUser;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getDeletedAt(): ?DateTimeInterface
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?DateTimeInterface $deletedAt): self
    {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    public function setDeletedAtValue(): void
    {
        $this->deletedAt = new DateTime();
    }

    #[Assert\Callback]
    public function validateVoucher(ExecutionContextInterface $context): void
    {
        if ($this->getExpirationDate() && $this->getExpirationDate() < new DateTime()) {
            $context->buildViolation('pteroca.voucher.expired')
                ->setTranslationDomain('messages')
                ->atPath('expirationDate')
                ->addViolation();
        }

        if (
            in_array($this->getType(), [VoucherTypeEnum::SERVER_DISCOUNT, VoucherTypeEnum::PAYMENT_DISCOUNT])
            && $this->getValue() > 100
        ) {
            $context->buildViolation('pteroca.voucher.discount_value_invalid')
                ->setTranslationDomain('messages')
                ->atPath('value')
                ->addViolation();
        }
    }

    public function __toString(): string
    {
        return $this->getCode();
    }
}
