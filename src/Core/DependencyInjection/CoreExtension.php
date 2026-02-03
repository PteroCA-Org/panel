<?php

namespace App\Core\DependencyInjection;

use App\Core\Trait\PluginDirectoryScannerTrait;
use Exception;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Yaml\Yaml;

class CoreExtension extends Extension implements PrependExtensionInterface
{
    use PluginDirectoryScannerTrait;
    /**
     * @throws Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
        $loader->load('plugin_security.yaml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        // Prepend Core bundle configurations
        $this->prependSecurity($container);
        $this->prependTwig($container);
        $this->prependMonolog($container);
        $this->prependVichUploader($container);

        // Build translation paths with correct priority
        $this->prependAllTranslations($container);
    }

    private function prependAllTranslations(ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $allTranslationPaths = [];

        // 1. Core translations (from translation.yaml)
        $corePath = $projectDir . '/src/Core/Resources/translations';
        if (is_dir($corePath)) {
            $allTranslationPaths[] = $corePath;
        }

        // 2. Plugin translations
        $pluginsDir = $projectDir . '/plugins';
        $plugins = $this->scanPluginDirectory($pluginsDir);

        foreach ($plugins as $pluginData) {
            $capabilities = $pluginData['manifest']['capabilities'] ?? [];
            if (!in_array('ui', $capabilities, true)) {
                continue;
            }

            $translationsPath = $pluginData['path'] . '/translations';
            if (is_dir($translationsPath)) {
                $allTranslationPaths[] = $translationsPath;
            }
        }

        // 3. Theme translations (LAST = HIGHEST PRIORITY in Symfony)
        $themesDir = $projectDir . '/themes';
        if (is_dir($themesDir)) {
            $themes = array_filter(glob($themesDir . '/*'), 'is_dir');

            foreach ($themes as $themePath) {
                $translationsPath = $themePath . '/translations';

                if (is_dir($translationsPath)) {
                    $allTranslationPaths[] = $translationsPath;
                }
            }
        }

        // Register all paths in one call - last in array has highest priority
        if (!empty($allTranslationPaths)) {
            $container->prependExtensionConfig('framework', [
                'translator' => [
                    'paths' => $allTranslationPaths,
                ],
            ]);
        }
    }

    private function prependSecurity(ContainerBuilder $container): void
    {
        $configFile = __DIR__ . '/../Resources/config/security.yaml';
        if (!file_exists($configFile)) {
            return;
        }

        $config = Yaml::parseFile($configFile);
        if (isset($config['security'])) {
            $container->prependExtensionConfig('security', $config['security']);
        }
    }

    private function prependTwig(ContainerBuilder $container): void
    {
        $configFile = __DIR__ . '/../Resources/config/twig.yaml';
        if (!file_exists($configFile)) {
            return;
        }

        $config = Yaml::parseFile($configFile);
        if (isset($config['twig'])) {
            $container->prependExtensionConfig('twig', $config['twig']);
        }
    }

    private function prependTranslation(ContainerBuilder $container): void
    {
        $configFile = __DIR__ . '/../Resources/config/translation.yaml';
        if (!file_exists($configFile)) {
            return;
        }

        $config = Yaml::parseFile($configFile);
        if (isset($config['framework'])) {
            $container->prependExtensionConfig('framework', $config['framework']);
        }
    }

    private function prependMonolog(ContainerBuilder $container): void
    {
        $configFile = __DIR__ . '/../Resources/config/monolog.yaml';
        if (!file_exists($configFile)) {
            return;
        }

        $config = Yaml::parseFile($configFile);
        if (isset($config['monolog'])) {
            $container->prependExtensionConfig('monolog', $config['monolog']);
        }

        // Prepend environment-specific configs
        $environment = $container->getParameter('kernel.environment');
        $whenKey = 'when@' . $environment;
        if (isset($config[$whenKey]['monolog'])) {
            $container->prependExtensionConfig('monolog', $config[$whenKey]['monolog']);
        }
    }

    private function prependVichUploader(ContainerBuilder $container): void
    {
        $configFile = __DIR__ . '/../Resources/config/vich_uploader.yaml';
        if (!file_exists($configFile)) {
            return;
        }

        $config = Yaml::parseFile($configFile);
        if (isset($config['vich_uploader'])) {
            $container->prependExtensionConfig('vich_uploader', $config['vich_uploader']);
        }
    }
}