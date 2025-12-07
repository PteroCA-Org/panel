<?php

namespace App\Core\Contract;

use App\Core\Enum\PermissionEnum;
use DateTime;
use DateTimeInterface;
use Symfony\Component\Security\Core\User\UserInterface as SymfonyUserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\HttpFoundation\File\File;

interface UserInterface extends SymfonyUserInterface, PasswordAuthenticatedUserInterface
{
    public function getId(): ?int;

    public function getPterodactylUserId(): ?int;
    public function setPterodactylUserId(?int $pterodactylUserId): self;

    public function getPterodactylUserApiKey(): ?string;
    public function setPterodactylUserApiKey(?string $pterodactylUserApiKey): self;

    public function getEmail(): ?string;
    public function setEmail(string $email): self;

    public function getUserIdentifier(): string;
    public function getRoles(): array;
    public function setRoles(array $roles): self;

    public function getPassword(): string;
    public function setPassword(string $password): self;

    public function getBalance(): float;
    public function setBalance(float $balance): self;

    public function getName(): string;
    public function setName(string $name): self;

    public function getSurname(): string;
    public function setSurname(string $surname): self;

    public function isVerified(): bool;
    public function setIsVerified(bool $isVerified): self;

    public function isBlocked(): bool;
    public function setIsBlocked(bool $isBlocked): self;

    public function getAvatarPath(): ?string;
    public function setAvatarPath(?string $avatarPath): self;

    public function getAvatarFile(): ?File;
    public function setAvatarFile(?File $avatarFile = null): self;

    public function getCreatedAt(): DateTimeInterface;
    public function getUpdatedAt(): ?DateTimeInterface;

    public function getPlainPassword(): ?string;
    public function setPlainPassword(?string $plainPassword): self;

    public function eraseCredentials(): void;

    public function getDeletedAt(): ?DateTime;
    public function setDeletedAt(?DateTime $deletedAt): self;
    public function isDeleted(): bool;
    public function softDelete(): self;
    public function restore(): self;

    public function hasPermission(string|PermissionEnum $permissionCode): bool;

    public function __toString(): string;
}
