<?php

namespace App\Core\DependencyInjection\Compiler;

use App\Core\Trait\PluginDirectoryScannerTrait;
use Exception;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Yaml\Yaml;

/**
 * Compiler pass to dynamically register plugin services.
 *
 * This runs during container compilation and:
 * - Scans plugins directory for ALL plugins (from plugin.json files)
 * - Registers their controllers (if 'routes' capability)
 * - Loads their services from Resources/config/services.yaml
 */
class PluginCompilerPass implements CompilerPassInterface
{
    use PluginDirectoryScannerTrait;
    public function process(ContainerBuilder $container): void
    {
        // Get project directory
        $projectDir = $container->getParameter('kernel.project_dir');
        $pluginsDir = $projectDir . '/plugins';

        // Scan plugins directory for ALL plugins (no database query needed)
        $plugins = $this->scanPluginDirectory($pluginsDir);

        if (empty($plugins)) {
            return; // No plugins to load
        }

        foreach ($plugins as $pluginData) {
            $this->registerPlugin($container, $pluginData, $projectDir);
        }
    }

    /**
     * Register a single plugin's services.
     *
     * @param ContainerBuilder $container
     * @param array $pluginData ['name' => string, 'manifest' => array]
     * @param string $projectDir
     */
    private function registerPlugin(ContainerBuilder $container, array $pluginData, string $projectDir): void
    {
        $pluginName = $pluginData['name'];
        $manifest = $pluginData['manifest'];
        $pluginPath = $projectDir . '/plugins/' . $pluginName;

        // Step 0: Register autoloader for this plugin so classes can be found
        $this->registerPluginAutoloader($pluginName, $pluginPath);

        // Step 1: Register controllers if plugin has 'routes' capability
        if (in_array('routes', $manifest['capabilities'] ?? [], true)) {
            $this->registerPluginControllers($container, $pluginName, $pluginPath);
        }

        // Step 2: Register custom services from services.yaml
        $servicesPath = $pluginPath . '/Resources/config/services.yaml';

        // Check if services.yaml exists
        if (!file_exists($servicesPath)) {
            return; // No additional services to register
        }

        try {
            // Parse services.yaml
            $servicesConfig = Yaml::parseFile($servicesPath);

            if (!isset($servicesConfig['services'])) {
                return;
            }

            // Register each service from the plugin
            $this->registerPluginServices($container, $servicesConfig['services'], $pluginName);

        } catch (Exception $e) {
            // Log error but don't break compilation
            // In production, you might want to mark plugin as FAULTED
            error_log("Failed to load services for plugin $pluginName: {$e->getMessage()}");
        }
    }

    /**
     * Register plugin services into the container.
     *
     * @param ContainerBuilder $container
     * @param array $services
     * @param string $pluginName
     */
    private function registerPluginServices(ContainerBuilder $container, array $services, string $pluginName): void
    {
        // Extract _defaults if present
        $defaults = $services['_defaults'] ?? [];

        foreach ($services as $serviceId => $serviceConfig) {
            // Skip special keys like _defaults
            if (str_starts_with($serviceId, '_')) {
                continue;
            }

            $class = $serviceConfig['class'] ?? $serviceId;
            $isDoctrineRepository = isset($serviceConfig['tags']) &&
                in_array('doctrine.repository_service', $serviceConfig['tags'], true);
            $isController = isset($serviceConfig['tags']) &&
                in_array('controller.service_arguments', $serviceConfig['tags'], true);

            if ($isDoctrineRepository || $isController) {
                $definition = $container->register($class, $class);

                if ($isDoctrineRepository) {
                    $definition->setAutowired(true);
                }

                if ($isController) {
                    $definition->addTag('container.service_subscriber');
                    $definition->setAutowired(true);
                }
            } else {
                $prefixedServiceId = "plugin.$pluginName.$serviceId";
                $definition = $container->register($prefixedServiceId, $class);

                if (class_exists($class) && $serviceId === $class) {
                    $container->setAlias($class, $prefixedServiceId)->setPublic(true);
                }
            }

            // Apply configuration
            if (isset($serviceConfig['arguments'])) {
                $definition->setArguments($this->resolveArguments($serviceConfig['arguments']));
            }

            if (isset($serviceConfig['tags'])) {
                foreach ($serviceConfig['tags'] as $tag) {
                    $definition->addTag(is_array($tag) ? $tag['name'] : $tag);
                }
            }

            if (isset($serviceConfig['public'])) {
                $definition->setPublic($serviceConfig['public']);
            } elseif (isset($defaults['public'])) {
                $definition->setPublic($defaults['public']);
            }

            if (isset($serviceConfig['autowire'])) {
                $definition->setAutowired($serviceConfig['autowire']);
            } elseif (isset($defaults['autowire'])) {
                $definition->setAutowired($defaults['autowire']);
            }

            if (isset($serviceConfig['autoconfigure'])) {
                $definition->setAutoconfigured($serviceConfig['autoconfigure']);
            } elseif (isset($defaults['autoconfigure'])) {
                $definition->setAutoconfigured($defaults['autoconfigure']);
            }
        }
    }

    /**
     * Resolve service arguments (convert string references to Reference objects).
     *
     * @param array $arguments
     * @return array
     */
    private function resolveArguments(array $arguments): array
    {
        $resolved = [];

        foreach ($arguments as $argument) {
            if (is_string($argument) && str_starts_with($argument, '@')) {
                // Service reference
                $serviceName = substr($argument, 1);
                $resolved[] = new Reference($serviceName);
            } else {
                $resolved[] = $argument;
            }
        }

        return $resolved;
    }

    /**
     * Register plugin controllers as services.
     *
     * This makes controllers available for autowiring and dependency injection.
     *
     * @param ContainerBuilder $container
     * @param string $pluginName
     * @param string $pluginPath
     */
    private function registerPluginControllers(ContainerBuilder $container, string $pluginName, string $pluginPath): void
    {
        $controllerPath = $pluginPath . '/src/Controller';

        if (!is_dir($controllerPath)) {
            return;
        }

        // Get plugin namespace
        $pluginNamespace = $this->getPluginNamespace($pluginName);

        try {
            // Find all PHP files in Controller directory
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($controllerPath, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                // Extract class name from file
                $relativePath = str_replace($controllerPath . '/', '', $file->getPathname());
                $className = $pluginNamespace . '\\Controller\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

                // Register controller as service with autowiring
                // AbstractController automatically gets the service locator through parent::setContainer()
                $definition = $container->register($className, $className);
                $definition->setPublic(true);
                $definition->setAutowired(true);
                $definition->addTag('controller.service_arguments');

                // AbstractController extends from ContainerAwareInterface and needs container
                // Symfony will automatically inject the service locator
                $definition->addTag('container.service_subscriber');
            }

        } catch (Exception $e) {
            error_log("Failed to register controllers for plugin $pluginName: {$e->getMessage()}");
        }
    }

    /**
     * Get plugin namespace from plugin name.
     *
     * @param string $pluginName
     * @return string
     */
    private function getPluginNamespace(string $pluginName): string
    {
        // Convert "hello-world" to "HelloWorld"
        $className = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $pluginName)));
        return "Plugins\\$className";
    }

    /**
     * Register PSR-4 autoloader for a plugin.
     *
     * This allows plugin classes to be found during container compilation.
     *
     * @param string $pluginName
     * @param string $pluginPath
     */
    private function registerPluginAutoloader(string $pluginName, string $pluginPath): void
    {
        $srcPath = $pluginPath . '/src';

        if (!is_dir($srcPath)) {
            return;
        }

        $namespace = $this->getPluginNamespace($pluginName) . '\\';

        spl_autoload_register(function ($class) use ($namespace, $srcPath) {
            // Check if class belongs to this namespace
            if (!str_starts_with($class, $namespace)) {
                return;
            }

            // Get relative class name
            $relativeClass = substr($class, strlen($namespace));

            // Replace namespace separators with directory separators
            $file = $srcPath . '/' . str_replace('\\', '/', $relativeClass) . '.php';

            // Require if exists
            if (file_exists($file)) {
                require $file;
            }
        });
    }
}
