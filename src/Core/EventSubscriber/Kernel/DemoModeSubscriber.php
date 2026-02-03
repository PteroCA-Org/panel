<?php

namespace App\Core\EventSubscriber\Kernel;

use App\Core\Service\System\DemoModeService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class DemoModeSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ROUTES = [
        'app_login',
        'app_logout',
        'security_logout',
    ];

    private const BLOCKED_ROUTES = [
        'payment_callback_success',  // /wallet/{provider}/success - adds money to wallet
        'stripe_success',            // /wallet/recharge/success - adds money (deprecated but active)
    ];

    private const BLOCKED_CRUD_ACTIONS = [
        'enablePlugin',
        'disablePlugin',
        'resetPlugin',
    ];

    public function __construct(
        private DemoModeService $demoModeService,
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->demoModeService->isDemoModeEnabled()) {
            return;
        }

        $request = $event->getRequest();
        $method = $request->getMethod();
        $routeName = $request->attributes->get('_route');

        // Block dangerous routes that modify data (e.g., payment callbacks)
        if (in_array($routeName, self::BLOCKED_ROUTES, true)) {
            $message = $this->translator->trans($this->demoModeService->getDemoModeMessage());
            $session = $this->requestStack->getSession();
            $session->getFlashBag()->add('danger', $message);

            // Redirect to wallet page for payment routes
            $event->setResponse(new RedirectResponse('/panel?routeName=recharge_balance'));
            return;
        }

        // Special handling for Settings CRUD - block viewing settings (even GET requests)
        // to protect sensitive information (API keys, passwords, etc.)
        if ($routeName === 'panel' && $method === 'GET') {
            $crudAction = $request->query->get('crudAction');
            $crudController = $request->query->get('crudControllerFqcn');

            // Block edit and detail actions for all Setting controllers
            if (in_array($crudAction, ['edit', 'detail']) &&
                $crudController &&
                str_contains($crudController, 'Setting') &&
                str_contains($crudController, 'CrudController')) {

                $session = $this->requestStack->getSession();
                $session->getFlashBag()->add(
                    'danger',
                    $this->translator->trans('pteroca.demo_mode.settings_view_disabled')
                );

                // Redirect to index page of the same controller
                $indexUrl = $this->generateSettingsIndexUrl($crudController);
                $event->setResponse(new RedirectResponse($indexUrl));
                return;
            }
        }

        // Block state-changing custom CRUD actions (even on GET requests)
        if ($routeName === 'panel' && $method === 'GET') {
            $crudAction = $request->query->get('crudAction');

            if (in_array($crudAction, self::BLOCKED_CRUD_ACTIONS, true)) {
                $session = $this->requestStack->getSession();
                $session->getFlashBag()->add(
                    'danger',
                    $this->translator->trans($this->demoModeService->getDemoModeMessage())
                );

                $referer = $request->headers->get('referer');
                $redirectUrl = $referer ?: '/panel?crudControllerFqcn=App\\Core\\Controller\\Panel\\Setting\\PluginCrudController&crudAction=index';
                $event->setResponse(new RedirectResponse($redirectUrl));
                return;
            }
        }

        // Allow GET requests and login/logout routes
        if ($method === 'GET' || in_array($routeName, self::ALLOWED_ROUTES, true)) {
            return;
        }

        // Block all POST/PUT/DELETE/PATCH requests
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            $message = $this->translator->trans($this->demoModeService->getDemoModeMessage());

            // Check if this is an AJAX/API request
            if ($request->isXmlHttpRequest() || str_starts_with($request->getPathInfo(), '/panel/api/')) {
                // Return JSON response for AJAX/API requests
                $event->setResponse(new JsonResponse([
                    'success' => false,
                    'error' => $message,
                ], 403));
            } else {
                // Return redirect for regular requests
                $session = $this->requestStack->getSession();
                $session->getFlashBag()->add('danger', $message);

                $referer = $request->headers->get('referer');
                $redirectUrl = $referer ?: '/panel';
                $event->setResponse(new RedirectResponse($redirectUrl));
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 6],
        ];
    }

    private function generateSettingsIndexUrl(string $crudController): string
    {
        return sprintf(
            '/panel?crudAction=index&crudControllerFqcn=%s',
            urlencode($crudController)
        );
    }
}
