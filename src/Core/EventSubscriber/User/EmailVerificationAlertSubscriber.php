<?php

namespace App\Core\EventSubscriber\User;

use App\Core\Entity\User;
use App\Core\Enum\EmailVerificationValueEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Service\Plugin\PluginNotificationService;
use App\Core\Service\SettingService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class EmailVerificationAlertSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private SettingService $settingService,
        private UrlGeneratorInterface $urlGenerator,
        private PluginNotificationService $notificationService,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->attributes->get('_route') === 'verify_notice') {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isVerified()) {
            return;
        }

        if (!$this->shouldShowEmailVerificationAlert()) {
            return;
        }

        $translatedMessage = $this->translator->trans('pteroca.system.email_not_verified');
        $translatedLinkText = $this->translator->trans('pteroca.system.or_resend_verification_email');

        $message = sprintf('%s <a href="%s">%s</a>',
            $translatedMessage,
            $this->urlGenerator->generate('panel', ['routeName' => 'verify_notice']),
            $translatedLinkText
        );

        $session = $request->getSession();
        $flashBag = $session->getFlashBag();
        $existingWarnings = $flashBag->peek('warning');

        if (!in_array($message, $existingWarnings, true)) {
            $this->notificationService->warning($message);
        }
    }

    private function shouldShowEmailVerificationAlert(): bool
    {
        $emailVerificationSetting = $this->settingService->getSetting(SettingEnum::REQUIRE_EMAIL_VERIFICATION->value);

        return $emailVerificationSetting === EmailVerificationValueEnum::REQUIRED->value
            || $emailVerificationSetting === EmailVerificationValueEnum::OPTIONAL->value;
    }
}
