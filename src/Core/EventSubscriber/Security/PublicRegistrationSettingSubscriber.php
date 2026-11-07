<?php

namespace App\Core\EventSubscriber\Security;

use App\Core\Entity\Setting;
use App\Core\Enum\PermissionEnum;
use App\Core\Enum\SettingContextEnum;
use App\Core\Enum\SettingEnum;
use EasyCorp\Bundle\EasyAdminBundle\Event\AbstractLifecycleEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityDeletedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityUpdatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class PublicRegistrationSettingSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuthorizationCheckerInterface $authorizationChecker)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeEntityPersistedEvent::class => 'checkPermission',
            BeforeEntityUpdatedEvent::class => 'checkPermission',
            BeforeEntityDeletedEvent::class => 'checkPermission',
        ];
    }

    public function checkPermission(AbstractLifecycleEvent $event): void
    {
        $setting = $event->getEntityInstance();

        if (
            $setting instanceof Setting
            && $setting->getName() === SettingEnum::PUBLIC_REGISTRATION_ENABLED->value
            && $setting->getContext() === SettingContextEnum::SECURITY->value
            && !$this->authorizationChecker->isGranted(PermissionEnum::EDIT_SETTINGS_SECURITY->value)
        ) {
            throw new AccessDeniedException();
        }
    }
}
