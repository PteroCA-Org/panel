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

        // Get current context (panel, landing, email)
        $context = $this->contextManager->getCurrentContext();

        // Get theme for this context
        $theme = $this->contextManager->getThemeForContext($context);

        // Clear existing paths to avoid conflicts
        foreach ($loader->getPaths() as $path) {
            if (str_contains($path, '/themes/')) {
                // Don't remove, just prepend new paths (they have priority)
            }
        }

        // Context-specific paths
        if ($context === 'landing') {
            // LANDING CONTEXT: Only themes/{theme}/landing/
            $landingPath = "$this->templatesBaseDir/$theme/landing";
            if (is_dir($landingPath)) {
                $loader->prependPath($landingPath);
            }
        }
        elseif ($context === 'email') {
            // EMAIL CONTEXT: Only themes/{theme}/email/
            $emailPath = "$this->templatesBaseDir/$theme/email";
            if (is_dir($emailPath)) {
                $loader->prependPath($emailPath);
            }
        }
        elseif ($context === 'panel') {
            // PANEL CONTEXT: Dual paths for backward compatibility
            // 1. New location (preferred): themes/{theme}/panel/
            $panelPath = "$this->templatesBaseDir/$theme/panel";
            if (is_dir($panelPath)) {
                $loader->prependPath($panelPath);
            }

            // 2. Legacy location (fallback): themes/{theme}/
            $loader->prependPath("$this->templatesBaseDir/$theme");

            // EasyAdmin bundle overrides
            if (is_dir("$panelPath/bundles/EasyAdminBundle")) {
                $loader->prependPath("$panelPath/bundles/EasyAdminBundle", 'EasyAdmin');
            }
            elseif (is_dir("$this->templatesBaseDir/$theme/bundles/EasyAdminBundle")) {
                $loader->prependPath("$this->templatesBaseDir/$theme/bundles/EasyAdminBundle", 'EasyAdmin');
            }
        }
    }
}
