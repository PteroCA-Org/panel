<?php

declare(strict_types=1);

namespace App\Core\EventSubscriber\Plugin;

use App\Core\Repository\PluginRepository;
use App\Core\Service\Plugin\PluginAutoloader;
use App\Core\Service\Plugin\PluginEventSubscriberRegistry;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Loader\FilesystemLoader;

/**
 * Event subscriber that bootstraps all ENABLED plugins on kernel boot.
 *
 * Performs runtime registration of plugin components:
 * - PSR-4 autoloaders for plugin classes
 * - Twig namespaces for plugin templates
 * - Event subscribers for plugin event handlers
 *
 * This runs once per request with high priority (before routing) to ensure
 * plugin resources are available when needed.
 */
class PluginBootSubscriber implements EventSubscriberInterface
{
    private static bool $booted = false;

    public function __construct(
        private readonly PluginRepository $pluginRepository,
        private readonly PluginAutoloader $pluginAutoloader,
        private readonly PluginEventSubscriberRegistry $eventSubscriberRegistry,
        private readonly FilesystemLoader $twigLoader,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Register on kernel.request with high priority (before routing)
            KernelEvents::REQUEST => ['onKernelRequest', 512],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Only boot once per PHP process
        if (self::$booted) {
            return;
        }

        // Only run on main request (not sub-requests)
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            // Find all ENABLED plugins
            $enabledPlugins = $this->pluginRepository->findEnabled();

            // Register autoloader for each enabled plugin
            foreach ($enabledPlugins as $plugin) {
                $registered = $this->pluginAutoloader->registerPlugin($plugin);

                if ($registered) {
                    $this->logger->debug("Registered autoloader for plugin: {$plugin->getName()}");
                }
            }

            // Register Twig namespaces for enabled plugins
            $this->registerTwigNamespaces($enabledPlugins);

            // Register event subscribers for enabled plugins
            $this->registerEventSubscribers($enabledPlugins);

            self::$booted = true;

        } catch (Exception $e) {
            // Silently fail during boot (e.g., database not available yet)
            $this->logger->warning("Failed to boot plugins: {$e->getMessage()}");
        }
    }

    /**
     * Register Twig namespaces for enabled plugins with 'ui' capability.
     *
     * Each plugin gets a Twig namespace like @PluginHelloWorld/ pointing to
     * /plugins/hello-world/templates/
     *
     * @param array $plugins Array of Plugin entities
     */
    private function registerTwigNamespaces(array $plugins): void
    {
        foreach ($plugins as $plugin) {
            // Only register for plugins with 'ui' capability
            if (!$plugin->hasCapability('ui')) {
                continue;
            }

            $templatePath = sprintf(
                '%s/plugins/%s/templates',
                $this->projectDir,
                $plugin->getName()
            );

            // Skip if templates directory doesn't exist
            if (!is_dir($templatePath)) {
                continue;
            }

            $namespace = $this->getPluginTwigNamespace($plugin->getName());

            try {
                $this->twigLoader->addPath($templatePath, $namespace);

                $this->logger->debug("Registered Twig namespace for plugin", [
                    'plugin' => $plugin->getName(),
                    'namespace' => $namespace,
                    'path' => $templatePath,
                ]);
            } catch (Exception $e) {
                $this->logger->error("Failed to register Twig namespace", [
                    'plugin' => $plugin->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Register event subscribers for enabled plugins with 'eda' capability.
     *
     * @param array $plugins Array of Plugin entities
     */
    private function registerEventSubscribers(array $plugins): void
    {
        foreach ($plugins as $plugin) {
            // Only register for plugins with 'eda' capability
            if (!$plugin->hasCapability('eda')) {
                continue;
            }

            try {
                $this->eventSubscriberRegistry->registerSubscribers($plugin);
            } catch (Exception $e) {
                $this->logger->error("Failed to register event subscribers for plugin", [
                    'plugin' => $plugin->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get Twig namespace for a plugin.
     *
     * Converts plugin-name to PluginName (PascalCase).
     *
     * Example: hello-world → Plugin HelloWorld
     *
     * @param string $pluginName Plugin name (kebab-case)
     * @return string Twig namespace (e.g., "PluginHelloWorld")
     */
    private function getPluginTwigNamespace(string $pluginName): string
    {
        return 'Plugin' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $pluginName)));
    }
}
