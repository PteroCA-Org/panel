<?php

namespace App\Core\DependencyInjection\Compiler;

use App\Core\Service\Widget\WidgetRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Automatically registers all tagged widgets into the WidgetRegistry.
 *
 * This compiler pass finds all services tagged with:
 * - 'widget' (generic widget tag for plugins and new widgets)
 * - 'core.widget' (core system widgets)
 * - 'dashboard.widget' (legacy dashboard widgets)
 * - 'admin.widget' (legacy admin widgets)
 * - 'header.widget' (header/navbar widgets)
 *
 * And registers them into the WidgetRegistry service for automatic discovery.
 */
class WidgetRegistryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(WidgetRegistry::class)) {
            return;
        }

        $widgetRegistry = $container->findDefinition(WidgetRegistry::class);

        // Tags to scan for widgets
        $widgetTags = [
            'widget',
            'core.widget',
            'dashboard.widget',
            'admin.widget',
            'header.widget',
        ];

        foreach ($widgetTags as $tag) {
            $taggedServices = $container->findTaggedServiceIds($tag);

            foreach (array_keys($taggedServices) as $serviceId) {
                $widgetRegistry->addMethodCall('registerWidget', [
                    new Reference($serviceId),
                ]);
            }
        }
    }
}
