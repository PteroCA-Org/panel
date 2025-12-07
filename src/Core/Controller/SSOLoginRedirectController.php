<?php

namespace App\Core\Controller;

use App\Core\Enum\PermissionEnum;
use App\Core\Enum\ViewNameEnum;
use App\Core\Event\SSO\SSORedirectRequestedEvent;
use App\Core\Event\SSO\SSORedirectInitiatedEvent;
use App\Core\Service\Authorization\SSOLoginRedirectService;
use Exception;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

class SSOLoginRedirectController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/sso/redirect', name: 'sso_redirect')]
    public function index(
        SSOLoginRedirectService $ssoLoginRedirectService,
        Request $request,
    ): Response {
        $this->denyAccessUnlessGranted(PermissionEnum::ACCESS_PTERODACTYL_SSO->value);

        $user = $this->getUser();
        $redirectPath = $this->getRedirectPath($request);

        // Emit SSORedirectRequestedEvent (pre)
        $this->dispatchDataEvent(
            SSORedirectRequestedEvent::class,
            $request,
            [
                $user->getEmail(),
                $user->getPterodactylUserId(),
                $redirectPath,
            ]
        );

        try {
            $redirectToken = $ssoLoginRedirectService->createSSOToken($user);
            $redirectUrl = $ssoLoginRedirectService->getPterodactylLoginUrl();

            // Emit SSORedirectInitiatedEvent (post-commit)
            $this->dispatchDataEvent(
                SSORedirectInitiatedEvent::class,
                $request,
                [
                    $user->getPterodactylUserId(),
                    $redirectPath,
                    $redirectUrl,
                ]
            );

            $viewData = [
                'redirectToken' => $redirectToken,
                'redirectUrl' => $redirectUrl,
                'redirectPath' => $redirectPath,
            ];

            return $this->renderWithEvent(
                ViewNameEnum::SSO_REDIRECT,
                'sso/redirect.html.twig',
                $viewData,
                $request
            );
        } catch (Exception $e) {
            // SSOFailedEvent is already emitted in service
            $errorMessage = $this->translator->trans('pteroca.sso.redirect_failed') . ': ' . $e->getMessage();
            $this->addFlash('danger', $errorMessage);
            return $this->redirectToRoute('panel');
        }
    }

    private function getRedirectPath(Request $request): string
    {
        return $request->query->get('redirect_path')
            ?? $request->query->all()['routeParams']['redirect_path']
            ?? '/';
    }
}
