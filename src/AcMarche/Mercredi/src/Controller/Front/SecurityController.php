<?php

namespace AcMarche\Mercredi\Controller\Front;

use AcMarche\Mercredi\Mailer\Factory\UserEmailFactory;
use AcMarche\Mercredi\Mailer\NotificationMailer;
use AcMarche\Mercredi\Organisation\Repository\OrganisationRepository;
use AcMarche\Mercredi\Security\Token\TokenManager;
use AcMarche\Mercredi\User\Repository\UserRepository;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    /** Session key holding the address a login link was last requested for. */
    public const LINK_EMAIL_SESSION_KEY = 'login_link_email';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TokenManager $tokenManager,
        private readonly UserEmailFactory $userEmailFactory,
        private readonly NotificationMailer $notificationMailer,
        private readonly OrganisationRepository $organisationRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/login', name: 'app_login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('mercredi_front_profile_redirect');
        }

        return $this->renderLogin(
            passwordError: $authenticationUtils->getLastAuthenticationError(),
            lastUsername: $authenticationUtils->getLastUsername(),
            email: (string) $request->getSession()->get(self::LINK_EMAIL_SESSION_KEY, ''),
        );
    }

    #[Route(path: '/login/with/email', name: 'app_login_with_email', methods: ['POST'])]
    public function loginWithEmail(Request $request): Response
    {
        $email = trim((string) $request->request->get('email', $request->request->get('username', '')));

        if (!$this->isCsrfTokenValid('authenticate', (string) $request->request->get('_csrf_token'))) {
            return $this->renderLogin(
                email: $email,
                emailError: 'La page a expiré. Veuillez réessayer.',
            );
        }

        if ('' === $email) {
            return $this->renderLogin(emailError: 'Indiquez votre adresse e-mail.');
        }

        if (!$this->isValidEmail($email)) {
            return $this->renderLogin(
                email: $email,
                emailError: "Cette adresse e-mail ne semble pas complète. Vérifiez-la, par exemple\u{00A0}: prenom.nom@exemple.be",
            );
        }

        $user = $this->userRepository->loadUserByIdentifier($email);

        // An unknown address gets the same confirmation as a known one,
        // so the page does not reveal who has an account.
        if (null !== $user && null !== $user->getEmail()) {
            try {
                $tokenUrl = $this->tokenManager->getLinkToConnect($user);
                $message = $this->userEmailFactory->messageSendAutoLogin($user, $tokenUrl);
                $this->notificationMailer->sendAsEmailNotification($message, $user->getEmail());
            } catch (\Throwable $exception) {
                $this->logger->error('Login link could not be sent: '.$exception->getMessage(), ['exception' => $exception]);

                return $this->renderLogin(
                    email: $email,
                    emailError: 'Le message n\'a pas pu être envoyé. Réessayez dans quelques minutes, ou connectez-vous avec votre mot de passe.',
                );
            }
        }

        $request->getSession()->set(self::LINK_EMAIL_SESSION_KEY, $email);

        return $this->redirectToRoute('app_login_link_sent');
    }

    #[Route(path: '/login/lien-envoye', name: 'app_login_link_sent', methods: ['GET'])]
    public function linkSent(Request $request): Response
    {
        $email = $request->getSession()->get(self::LINK_EMAIL_SESSION_KEY);

        if (!\is_string($email) || '' === $email) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('@AcMarcheMercredi/security/link_sent.html.twig', [
            'email' => $email,
            'organisation' => $this->organisationRepository->getOrganisation(),
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new LogicException(
            'This method can be blank - it will be intercepted by the logout key on your firewall.',
        );
    }

    private function isValidEmail(string $email): bool
    {
        return false !== filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE);
    }

    private function renderLogin(
        ?\Throwable $passwordError = null,
        string $lastUsername = '',
        string $email = '',
        ?string $emailError = null,
    ): Response {
        return $this->render(
            '@AcMarcheMercredi/security/login.html.twig',
            [
                'last_username' => $lastUsername,
                'error' => $passwordError,
                'email' => $email,
                'email_error' => $emailError,
                'organisation' => $this->organisationRepository->getOrganisation(),
            ],
            new Response(status: null !== $emailError ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK),
        );
    }
}
