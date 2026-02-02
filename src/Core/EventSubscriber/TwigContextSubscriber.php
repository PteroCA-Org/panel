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

        // Fallback to default theme if configured theme doesn't exist
        if (!is_dir("$this->templatesBaseDir/$theme")) {
            $theme = 'default';
        }

        if ($context === 'landing') {
            // Add default theme as fallback first (searched last)
            $defaultLandingPath = "$this->templatesBaseDir/default/landing";
            if ($theme !== 'default' && is_dir($defaultLandingPath)) {
                $loader->prependPath($defaultLandingPath);
            }

            // Then add current theme (searched first)
            $landingPath = "$this->templatesBaseDir/$theme/landing";
            if (is_dir($landingPath)) {
                $loader->prependPath($landingPath);
            }
        }
        elseif ($context === 'email') {
            // Add default theme as fallback first (searched last)
            $defaultEmailPath = "$this->templatesBaseDir/default/email";
            if ($theme !== 'default' && is_dir($defaultEmailPath)) {
                $loader->prependPath($defaultEmailPath);
            }

            // Then add current theme (searched first)
            $emailPath = "$this->templatesBaseDir/$theme/email";
            if (is_dir($emailPath)) {
                $loader->prependPath($emailPath);
            }
        }
        elseif ($context === 'panel') {
            // Add default theme as fallback first (searched last)
            $defaultPanelPath = "$this->templatesBaseDir/default/panel";
            if ($theme !== 'default' && is_dir($defaultPanelPath)) {
                $loader->prependPath($defaultPanelPath);
            }

            // Then add current theme (searched first)
            $panelPath = "$this->templatesBaseDir/$theme/panel";
            if (is_dir($panelPath)) {
                $loader->prependPath($panelPath);
            }

            // DEPRECATED: Legacy location (fallback): themes/{theme}/ (introduced in v0.6.3)
            // This fallback will be REMOVED in a future version (v0.8.0+)
            // Legacy themes store templates directly in theme root instead of panel/ subdirectory
            // ACTION REQUIRED: Migrate your custom templates to themes/{theme}/panel/ structure

            // Add default theme legacy location as fallback first
            if ($theme !== 'default' && is_dir("$this->templatesBaseDir/default")) {
                $loader->prependPath("$this->templatesBaseDir/default");
            }

            // Then add current theme legacy location
            if (is_dir("$this->templatesBaseDir/$theme")) {
                $loader->prependPath("$this->templatesBaseDir/$theme");
            }

            // Add default theme EasyAdmin bundle as fallback first
            $defaultEasyAdminPath = "$defaultPanelPath/bundles/EasyAdminBundle";
            if ($theme !== 'default' && is_dir($defaultEasyAdminPath)) {
                $loader->prependPath($defaultEasyAdminPath, 'EasyAdmin');
            }

            // Then add current theme EasyAdmin bundle
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
