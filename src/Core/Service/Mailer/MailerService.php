<?php

namespace App\Core\Service\Mailer;

use App\Core\Enum\SettingEnum;
use App\Core\Event\Email\EmailAfterSendEvent;
use App\Core\Event\Email\EmailBeforeSendEvent;
use App\Core\Service\SettingService;
use App\Core\Service\System\IpAddressProviderService;
use App\Core\Service\Template\TemplateService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Throwable;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;

class MailerService implements MailerServiceInterface
{
    private const DEV_MAILHOG_DSN = 'smtp://mailhog:1025';
    private const DEV_MAILHOG_FROM = 'noreply@pteroca.local';

    private MailerInterface $mailer;

    private string $from = '';

    public function __construct(
        private readonly Environment $twig,
        private readonly SettingService $settingsService,
        private readonly string $defaultLogoPath,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RequestStack $requestStack,
        private readonly IpAddressProviderService $ipAddressProvider,
        private readonly string $projectDir,
        private readonly string $appEnv = 'prod',
    ) {}

    /**
     * @throws SyntaxError
     * @throws Throwable
     * @throws RuntimeError
     * @throws LoaderError
     */
    /**
     * Theme template paths are registered per HTTP request, so console commands (cron jobs,
     * messenger workers) need the email and default theme roots added as a fallback.
     */
    private function ensureEmailTemplatePaths(string $template): void
    {
        $loader = $this->twig->getLoader();
        if (!$loader instanceof FilesystemLoader || $loader->exists($template)) {
            return;
        }

        $themesDir = $this->projectDir . '/themes';
        $themes = array_unique(array_filter([
            $this->settingsService->getSetting(SettingEnum::EMAIL_THEME->value),
            TemplateService::DEFAULT_THEME,
        ]));

        foreach ($themes as $theme) {
            if (is_dir("$themesDir/$theme")) {
                $loader->addPath("$themesDir/$theme");
            }
        }
    }

    public function sendEmail(string $to, string $subject, string $template, array $context): void
    {
        if (empty($this->mailer)) {
            $this->setMailer();
        }

        if (empty($context['title'])) {
            $context['title'] = $subject;
        }

        $logoPath = $this->resolveLogoPath();

        // Create Email object early so plugins can modify it
        $email = (new Email())
            ->from($this->from)
            ->to($to)
            ->subject($subject)
            ->attachFromPath($logoPath, 'logo.png');

        // Build event context
        $eventContext = $this->buildEventContext();

        // Dispatch EmailBeforeSendEvent to allow plugins to modify email before sending
        $beforeEvent = new EmailBeforeSendEvent(
            $email,
            $template,
            $context,
            $subject,
            $to,
            $eventContext
        );
        $this->eventDispatcher->dispatch($beforeEvent);

        // Use potentially modified values from event
        $modifiedTemplate = $beforeEvent->getTemplateName();
        $modifiedContext = $beforeEvent->getContext();
        $modifiedSubject = $beforeEvent->getSubject();

        // Update email with modified values
        $email->subject($modifiedSubject);
        $this->ensureEmailTemplatePaths($modifiedTemplate);
        $email->html($this->twig->render($modifiedTemplate, $modifiedContext));

        // Try to send email and dispatch after-send event
        $exception = null;
        $success = true;

        try {
            $this->mailer->send($email);
        } catch (Throwable $e) {
            $exception = $e;
            $success = false;
        }

        // Dispatch EmailAfterSendEvent for logging/statistics
        $afterEvent = new EmailAfterSendEvent(
            $email,
            $modifiedTemplate,
            $to,
            $success,
            $exception,
            $eventContext
        );
        $this->eventDispatcher->dispatch($afterEvent);

        // Re-throw exception if send failed
        if (!$success) {
            throw $exception;
        }
    }

    private function setMailer(): void
    {
        if ($this->appEnv === 'dev') {
            $this->from = self::DEV_MAILHOG_FROM;
            $dsn = self::DEV_MAILHOG_DSN;
        } else {
            $smtpServer = $this->settingsService->getSetting(SettingEnum::EMAIL_SMTP_SERVER->value);
            $smtpPort = $this->settingsService->getSetting(SettingEnum::EMAIL_SMTP_PORT->value);
            $smtpUsername = $this->settingsService->getSetting(SettingEnum::EMAIL_SMTP_USERNAME->value);
            $smtpPassword = $this->settingsService->getSetting(SettingEnum::EMAIL_SMTP_PASSWORD->value);
            $this->from = $this->settingsService->getSetting(SettingEnum::EMAIL_SMTP_FROM->value);
            $dsn = sprintf('smtp://%s:%s@%s:%d', $smtpUsername, $smtpPassword, $smtpServer, $smtpPort);
        }

        $transport = Transport::fromDsn($dsn);
        $this->mailer = new Mailer($transport);
    }

    private function resolveLogoPath(): string
    {
        $logoFilename = $this->settingsService->getSetting(SettingEnum::EMAIL_LOGO->value);

        if (empty($logoFilename)) {
            $logoFilename = $this->settingsService->getSetting(SettingEnum::LOGO->value);
        }

        if (!empty($logoFilename)) {
            $customLogoPath = sprintf(
                '%s/public/uploads/settings/%s',
                $this->projectDir,
                $logoFilename
            );

            if (is_file($customLogoPath)) {
                return $customLogoPath;
            }
        }

        return $this->defaultLogoPath;
    }

    /**
     * Build minimal event context from current request.
     *
     * @return array
     */
    private function buildEventContext(): array
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request) {
            return [];
        }

        return [
            'ip' => $this->ipAddressProvider->getIpAddress(),
            'userAgent' => $request->headers->get('User-Agent'),
            'locale' => $request->getLocale(),
            'route' => $request->attributes->get('_route'),
        ];
    }
}
