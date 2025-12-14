<?php

namespace App\Core\DependencyInjection\Compiler;

use App\Core\Trait\PluginDirectoryScannerTrait;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass to register plugin CRUD controllers with EasyAdmin.
 *
 * This ensures that EasyAdmin's CrudControllerRegistry knows about
 * plugin CRUD controllers during container compilation.
 */
class PluginEasyAdminCompilerPass implements CompilerPassInterface
{
    use PluginDirectoryScannerTrait;

    public function process(ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $pluginsDir = $projectDir . '/plugins';
        $plugins = $this->scanPluginDirectory($pluginsDir);

        if (empty($plugins)) {
            return;
        }

        foreach ($plugins as $pluginData) {
            $this->registerPluginCrudControllers($container, $pluginData, $projectDir);
        }
    }

    /**
     * Register CRUD controllers for a single plugin.
     *
     * @param ContainerBuilder $container
     * @param array $pluginData
     * @param string $projectDir
     */
    private function registerPluginCrudControllers(
        ContainerBuilder $container,
        array            $pluginData,
        string           $projectDir
    ): void {
        $pluginName = $pluginData['name'];
        $manifest = $pluginData['manifest'];

        if (!isset($manifest['capabilities']) || !in_array('routes', $manifest['capabilities'], true)) {
            return;
        }

        $controllerPath = $projectDir . '/plugins/' . $pluginName . '/src/Controller';

        if (!is_dir($controllerPath)) {
            return;
        }

        $pluginNamespace = $this->getPluginNamespace($pluginName);

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($controllerPath, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relativePath = str_replace($controllerPath . '/', '', $file->getPathname());
                $className = $pluginNamespace . '\\Controller\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

                if (!class_exists($className)) {
                    continue;
                }

                if (!is_subclass_of($className, AbstractCrudController::class)) {
                    continue;
                }

                try {
                    $entityFqcn = $className::getEntityFqcn();
                } catch (\Exception $e) {
                    continue;
                }

                if (empty($entityFqcn)) {
                    continue;
                }

                if ($container->hasDefinition($className)) {
                    $definition = $container->getDefinition($className);
                    $definition->addTag('ea.crud_controller', [
                        'entity' => $entityFqcn
                    ]);
                }
            }

        } catch (\Exception $e) {
            error_log("[PluginEasyAdmin] Failed to register CRUD controllers for plugin $pluginName: {$e->getMessage()}");
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
        $className = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $pluginName)));
        return "Plugins\\$className";
    }
}
