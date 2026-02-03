<?php

namespace App\Core\EventSubscriber\Kernel;

use App\Core\Service\Telemetry\TelemetryService;
use App\Core\Service\Template\CurrentThemeService;
use App\Core\Service\Template\TemplateContextManager;
use Exception;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AsEventListener(event: 'kernel.exception')]
readonly class ExceptionSubscriber
{
    public function __construct(
        private Environment            $twig,
        private CurrentThemeService    $currentThemeService,
        private KernelInterface        $kernel,
        private TelemetryService       $telemetryService,
        private TemplateContextManager $contextManager,
    ) {
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function __invoke(ExceptionEvent $event): void
    {
        if ($this->kernel->getEnvironment() !== 'prod') {
            return;
        }

        $exception = $event->getThrowable();
        $statusCode = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

        if ($statusCode === 500) {
            $this->telemetryService->send500ErrorEvent($exception, $event->getRequest());
        }

        $currentTheme = $this->currentThemeService->getCurrentTheme();
        $context = $this->contextManager->getCurrentContext();

        $template = $this->findTemplate($statusCode, $currentTheme, $context);

        if (!$template) {
            return;
        }

        try {
            $content = $this->twig->render($template, [
                'status_code' => $statusCode,
                'status_text' => Response::$statusTexts[$statusCode] ?? 'Unknown error',
                'exception' => $exception,
            ]);

            $response = new Response($content, $statusCode);
            $event->setResponse($response);
        } catch (Exception) {
            return;
        }
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     */
    private function findTemplate(int $statusCode, string $currentTheme, string $context): ?string
    {
        $contextPath = match($context) {
            'landing' => 'landing',
            'email' => 'panel',
            default => 'panel',
        };

        $candidates = $this->getTemplateCandidates($statusCode, $currentTheme, $contextPath);

        foreach ($candidates as $template) {
            if ($this->templateExists($template)) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Returns list of template candidates in priority order
     *
     * @return string[]
     */
    private function getTemplateCandidates(int $statusCode, string $theme, string $contextPath): array
    {
        $candidates = [];

        if ($theme === 'default') {
            // 1. Default theme with requested context
            $candidates[] = $this->buildTemplatePath('@default_theme', $contextPath, $statusCode);
            $candidates[] = $this->buildTemplatePath('@default_theme', $contextPath);

            // 2. Fallback to panel if landing
            if ($contextPath === 'landing') {
                $candidates[] = $this->buildTemplatePath('@default_theme', 'panel', $statusCode);
                $candidates[] = $this->buildTemplatePath('@default_theme', 'panel');
            }
        } else {
            // 1. Custom theme with requested context
            $candidates[] = $this->buildTemplatePath("themes/{$theme}", $contextPath, $statusCode);
            $candidates[] = $this->buildTemplatePath("themes/{$theme}", $contextPath);

            // 2. Custom theme with panel context (fallback for landing)
            if ($contextPath === 'landing') {
                $candidates[] = $this->buildTemplatePath("themes/{$theme}", 'panel', $statusCode);
                $candidates[] = $this->buildTemplatePath("themes/{$theme}", 'panel');
            }

            // 3. Default theme with requested context
            $candidates[] = $this->buildTemplatePath('@default_theme', $contextPath, $statusCode);
            $candidates[] = $this->buildTemplatePath('@default_theme', $contextPath);

            // 4. Default theme with panel context (final fallback for landing)
            if ($contextPath === 'landing') {
                $candidates[] = $this->buildTemplatePath('@default_theme', 'panel', $statusCode);
                $candidates[] = $this->buildTemplatePath('@default_theme', 'panel');
            }
        }

        return $candidates;
    }

    /**
     * Builds template path
     */
    private function buildTemplatePath(string $themePrefix, string $contextPath, ?int $statusCode = null): string
    {
        if ($statusCode) {
            return sprintf('%s/%s/bundles/TwigBundle/Exception/error%d.html.twig',
                $themePrefix, $contextPath, $statusCode);
        }

        return sprintf('%s/%s/bundles/TwigBundle/Exception/error.html.twig',
            $themePrefix, $contextPath);
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     */
    private function templateExists(string $template): bool
    {
        try {
            $this->twig->load($template);
            return true;
        } catch (LoaderError) {
            return false;
        }
    }
}
