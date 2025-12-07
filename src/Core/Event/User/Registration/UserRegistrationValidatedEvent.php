<?php

namespace App\Core\Event\User\Registration;

use App\Core\Enum\SystemRoleEnum;
use App\Core\Event\AbstractDomainEvent;
use App\Core\Event\StoppableEventTrait;

class UserRegistrationValidatedEvent extends AbstractDomainEvent
{
    use StoppableEventTrait;

    public function __construct(
        private readonly string $email,
        private readonly string $normalizedEmail,
        private readonly array $roles = [SystemRoleEnum::ROLE_USER->value],
        private readonly array $context = [],
        private readonly ?string $eventId = null,
    ) {
        parent::__construct($eventId);
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getNormalizedEmail(): string
    {
        return $this->normalizedEmail;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
