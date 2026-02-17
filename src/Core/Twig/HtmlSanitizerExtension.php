<?php

namespace App\Core\Twig;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class HtmlSanitizerExtension extends AbstractExtension
{
    public function __construct(
        private readonly HtmlSanitizerInterface $htmlSanitizer
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('safe_html', [$this, 'sanitizeHtml'], ['is_safe' => ['html']]),
        ];
    }

    public function sanitizeHtml(?string $html): string
    {
        if (empty($html)) {
            return '';
        }

        return $this->htmlSanitizer->sanitize($html);
    }
}
