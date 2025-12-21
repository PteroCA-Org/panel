<?php

namespace App\Core\Controller;

use App\Core\Entity\User;
use App\Core\Enum\ViewNameEnum;
use App\Core\Contract\UserInterface;
use App\Core\Form\RegistrationFormType;
use App\Core\Event\Form\FormSubmitEvent;
use App\Core\Enum\EmailVerificationValueEnum;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Core\Service\Mailer\EmailVerificationService;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Core\Service\Authorization\RegistrationService;
use App\Core\Service\System\DemoModeService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

class RegistrationController extends AbstractController
{

    public function __construct(
        private readonly RegistrationService $registrationService,
        private readonly TranslatorInterface $translator,
        private readonly EmailVerificationService $emailVerificationService,
        private readonly DemoModeService $demoModeService,
    ) {}

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserAuthenticatorInterface $userAuthenticator,
        #[Autowire(service: 'App\Core\Security\UserAuthenticator')]
        AuthenticatorInterface $authenticator,
    ): Response {
        if ($this->demoModeService->isDemoModeEnabled()) {
            $this->addFlash(
                'danger',
                $this->translator->trans($this->demoModeService->getDemoModeMessage())
            );
            return $this->redirectToRoute('app_login');
        }

        if ($this->getUser() !== null) {
            return $this->redirectToRoute('panel');
        }

        $registrationErrors = [];
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $formData = [
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'surname' => $user->getSurname(),
                'plainPassword' => $form->get('plainPassword')->getData(),
            ];
            
            foreach ($form->all() as $fieldName => $field) {
                if ($field->getConfig()->getOption('mapped') === false && $fieldName !== 'plainPassword') {
                    $formData[$fieldName] = $field->getData();
                }
            }

            $context = $this->buildMinimalEventContext($request);
            $submitEvent = $this->dispatchEvent(new FormSubmitEvent('registration', $formData, $context));
            
            if ($submitEvent->isPropagationStopped()) {
                $registrationErrors[] = $this->translator->trans('pteroca.register.plugin_validation_failed');
            } else {
                $registerActionResult = $this->registrationService
                    ->registerUser($user, $submitEvent->getFormValue('plainPassword'));

                if (!$registerActionResult->success) {
                    $registrationErrors[] = $registerActionResult->error;
                } else {
                    return $userAuthenticator->authenticateUser(
                        $registerActionResult->user,
                        $authenticator,
                        $request
                    );
                }
            }
        }

        if (empty($registrationErrors)) {
            $errors = $form->getErrors(true);
            $registrationErrors = array_map(fn ($error) => $error->getMessage(), iterator_to_array($errors));
        }

        $viewData = [
            'registrationForm' => $form->createView(),
            'errors' => $registrationErrors,
        ];

        return $this->renderWithEvent(ViewNameEnum::REGISTRATION, 'panel/registration/register.html.twig', $viewData, $request);
    }

    #[Route('/verify-email', name: 'app_verify_email')]
    public function verifyEmail(
        Request $request,
    ): Response {
        $token = $request->query->get('token');
        if (!$token) {
            $this->addFlash('danger', $this->translator->trans('pteroca.register.verification_token_invalid'));
            return $this->redirectToRoute('panel');
        }
        try {
            $this->registrationService->verifyEmail($token);
            $this->addFlash('success', $this->translator->trans('pteroca.register.verification_success'));
        } catch (Exception $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('panel');
    }

    #[Route('/verify-notice', name: 'verify_notice')]
    public function verifyNotice(Request $request): Response
    {
        $this->checkPermission();

        $user = $this->getUser();
        if ($user instanceof UserInterface && $user->isVerified()) {
            return $this->redirectToRoute('panel');
        }

        $verificationMode = $this->registrationService->getEmailVerificationMode();
        if ($verificationMode === EmailVerificationValueEnum::DISABLED->value) {
            return $this->redirectToRoute('panel');
        }

        $viewData = [
            'user' => $user,
            'verificationMode' => $verificationMode,
        ];

        return $this->renderWithEvent(
            ViewNameEnum::EMAIL_VERIFICATION_NOTICE,
            'panel/registration/verify_notice.html.twig',
            $viewData,
            $request
        );
    }

    #[Route('/resend-verification', name: 'resend_verification', methods: ['POST'])]
    public function resendVerification(): Response
    {
        $user = $this->getUser();
        
        if (!$user instanceof UserInterface) {
            $this->addFlash('danger', $this->translator->trans('pteroca.email.verification.not_logged_in'));
            return $this->redirectToRoute('app_login');
        }

        if ($user->isVerified()) {
            $this->addFlash('info', $this->translator->trans('pteroca.email.verification.already_verified'));
            return $this->redirectToRoute('panel');
        }

        try {
            $this->emailVerificationService->resendVerificationEmail($user);
            $this->addFlash('success', $this->translator->trans('pteroca.email.verification.resend_success'));
        } catch (Exception $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('panel', ['routeName' => 'verify_notice']);
    }
}
