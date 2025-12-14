<?php

namespace App\Core\Service\Tab;

use App\Core\Contract\Tab\ServerTabInterface;
use App\Core\DTO\ServerTabContext;
use App\Core\Enum\PluginStateEnum;
use App\Core\Repository\PluginRepository;
use Exception;
use InvalidArgumentException;

class ServerTabRegistry
{
    /** @var array<string, ServerTabInterface> */
    private array $tabs = [];

    /**
     * @param iterable<ServerTabInterface> $tabs Tagged server tabs
     * @param PluginRepository|null $pluginRepository Optional repository for filtering plugin tabs
     */
    public function __construct(
        iterable $tabs = [],
        private ?PluginRepository $pluginRepository = null,
    ) {
        foreach ($tabs as $tab) {
            $this->registerTab($tab);
        }
    }

    public function registerTab(ServerTabInterface $tab): void
    {
        $id = $tab->getId();

        if (isset($this->tabs[$id])) {
            throw new InvalidArgumentException(
                sprintf('Server tab with ID "%s" is already registered', $id)
            );
        }

        $this->tabs[$id] = $tab;
    }

    public function getAllTabs(): array
    {
        return $this->tabs;
    }

    public function getVisibleTabs(ServerTabContext $context): array
    {
        $enabledTabs = $this->filterEnabledPluginTabs($this->tabs);

        $visibleTabs = array_filter(
            $enabledTabs,
            fn(ServerTabInterface $tab) => $tab->isVisible($context)
        );

        usort($visibleTabs, function (ServerTabInterface $a, ServerTabInterface $b) {
            return $b->getPriority() <=> $a->getPriority(); // DESC
        });

        return $visibleTabs;
    }

    public function getDefaultTab(ServerTabContext $context): ?ServerTabInterface
    {
        $visibleTabs = $this->getVisibleTabs($context);

        // First try to find tab marked as default
        foreach ($visibleTabs as $tab) {
            if ($tab->isDefault()) {
                return $tab;
            }
        }

        // Fallback to first visible tab
        return $visibleTabs[0] ?? null;
    }

    public function getTab(string $id): ?ServerTabInterface
    {
        return $this->tabs[$id] ?? null;
    }

    public function hasTab(string $id): bool
    {
        return isset($this->tabs[$id]);
    }

    public function removeTab(string $id): void
    {
        unset($this->tabs[$id]);
    }

    public function getTabAssets(array $tabs): array
    {
        $stylesheets = [];
        $javascripts = [];

        foreach ($tabs as $tab) {
            foreach ($tab->getStylesheets() as $css) {
                if (!in_array($css, $stylesheets, true)) {
                    $stylesheets[] = $css;
                }
            }

            foreach ($tab->getJavascripts() as $js) {
                if (!in_array($js, $javascripts, true)) {
                    $javascripts[] = $js;
                }
            }
        }

        return [
            'stylesheets' => $stylesheets,
            'javascripts' => $javascripts,
        ];
    }

    public function count(): int
    {
        return count($this->tabs);
    }

    /**
     * Filter tabs to only include those from enabled plugins.
     * Core tabs (not in Plugins namespace) are always included.
     *
     * @param array<ServerTabInterface> $tabs
     * @return array<ServerTabInterface>
     */
    private function filterEnabledPluginTabs(array $tabs): array
    {
        if ($this->pluginRepository === null) {
            return $tabs;
        }

        return array_filter($tabs, function (ServerTabInterface $tab) {
            $tabClass = get_class($tab);

            if (!str_starts_with($tabClass, 'Plugins\\')) {
                return true;
            }

            $parts = explode('\\', $tabClass);
            if (count($parts) < 2) {
                return false;
            }

            $pluginClassName = $parts[1];
            $pluginName = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $pluginClassName));

            try {
                $plugin = $this->pluginRepository->findByName($pluginName);
                return $plugin && $plugin->getState() === PluginStateEnum::ENABLED;
            } catch (Exception) {
                return false;
            }
        });
    }
}
