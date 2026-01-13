<?php

namespace App\Core\EventSubscriber;

use App\Core\Service\Template\TemplateContextManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class TwigContextSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Environment $twig,
        private readonly TemplateContextManager $contextManager,
        private readonly string $templatesBaseDir,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 15], // After routing (priority 32)
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $loader = $this->twig->getLoader();
        if (!$loader instanceof FilesystemLoader) {
            return;
        }

        $context = $this->contextManager->getCurrentContext();
        $theme = $this->contextManager->getThemeForContext($context);

        if ($context === 'landing') {
            $landingPath = "$this->templatesBaseDir/$theme/landing";
            if (is_dir($landingPath)) {
                $loader->prependPath($landingPath);
            }
        }
        elseif ($context === 'email') {
            $emailPath = "$this->templatesBaseDir/$theme/email";
            if (is_dir($emailPath)) {
                $loader->prependPath($emailPath);
            }
        }
        elseif ($context === 'panel') {
            $panelPath = "$this->templatesBaseDir/$theme/panel";
            if (is_dir($panelPath)) {
                $loader->prependPath($panelPath);
            }

            // DEPRECATED: Legacy location (fallback): themes/{theme}/ (introduced in v0.6.3)
            // This fallback will be REMOVED in a future version (v0.8.0+)
            // Legacy themes store templates directly in theme root instead of panel/ subdirectory
            // ACTION REQUIRED: Migrate your custom templates to themes/{theme}/panel/ structure
            $loader->prependPath("$this->templatesBaseDir/$theme");

            if (is_dir("$panelPath/bundles/EasyAdminBundle")) {
                $loader->prependPath("$panelPath/bundles/EasyAdminBundle", 'EasyAdmin');
            }
            // DEPRECATED: Legacy EasyAdmin location (will be removed in v0.8.0+)
            elseif (is_dir("$this->templatesBaseDir/$theme/bundles/EasyAdminBundle")) {
                $loader->prependPath("$this->templatesBaseDir/$theme/bundles/EasyAdminBundle", 'EasyAdmin');
            }
        }
    }
}
