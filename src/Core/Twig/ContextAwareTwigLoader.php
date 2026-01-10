<?php

namespace App\Core\Twig;

use App\Core\Service\Template\TemplateContextManager;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;

class ContextAwareTwigLoader extends FilesystemLoader
{
    /**
     * @throws LoaderError
     */
    public function __construct(
        private readonly TemplateContextManager $contextManager,
        string $templatesBaseDir,
    )
    {
        parent::__construct();

        // Get current context (panel, landing, email, etc.)
        $context = $this->contextManager->getCurrentContext();

        // Get theme for this context
        $theme = $this->contextManager->getThemeForContext($context);

        // Context-specific paths
        if ($context === 'panel') {
            // Dual paths for backward compatibility
            // 1. New location (preferred): themes/{theme}/panel/
            $panelPath = "$templatesBaseDir/$theme/panel";
            if (is_dir($panelPath)) {
                $this->prependPath($panelPath);
            }

            // 2. Legacy location (fallback): themes/{theme}/
            // Custom user templates in root still work
            $this->prependPath("$templatesBaseDir/$theme");

            // EasyAdmin bundle overrides
            // Check new location first
            if (file_exists("$panelPath/bundles/EasyAdminBundle")) {
                $this->prependPath("$panelPath/bundles/EasyAdminBundle", 'EasyAdmin');
            }
            // Fallback to legacy location
            elseif (file_exists("$templatesBaseDir/$theme/bundles/EasyAdminBundle")) {
                $this->prependPath("$templatesBaseDir/$theme/bundles/EasyAdminBundle", 'EasyAdmin');
            }
        }
        elseif ($context === 'landing') {
            // LANDING CONTEXT: Only themes/{theme}/landing/
            $this->prependPath("$templatesBaseDir/$theme/landing");
        }
        elseif ($context === 'email') {
            // EMAIL CONTEXT: Only themes/{theme}/email/
            $this->prependPath("$templatesBaseDir/$theme/email");
        }
        else {
            // Default: theme root
            $this->prependPath("$templatesBaseDir/$theme");
        }
    }
}
