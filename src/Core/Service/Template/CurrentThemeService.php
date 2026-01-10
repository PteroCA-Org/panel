<?php

namespace App\Core\Service\Template;

readonly class CurrentThemeService
{
    public function __construct(
        private TemplateContextManager $contextManager,
    ) {}

    public function getCurrentTheme(): string
    {
        $context = $this->contextManager->getCurrentContext();
        return $this->contextManager->getThemeForContext($context);
    }

    public function getThemeForContext(string $context): string
    {
        return $this->contextManager->getThemeForContext($context);
    }

    public function getCurrentContext(): string
    {
        return $this->contextManager->getCurrentContext();
    }
}
