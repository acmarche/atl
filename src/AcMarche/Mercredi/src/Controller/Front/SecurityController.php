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
    /** Session key holding the email-form error, read once by the login page. */
    public const LINK_ERROR_SESSION_KEY = 'login_link_error';
    /** Session key holding the time a link was re-sent, read once by the link-sent page. */
    public const LINK_RESENT_SESSION_KEY = 'login_link_resent_at';

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

        $session = $request->getSession();
        $emailError = $session->remove(self::LINK_ERROR_SESSION_KEY);

        return $this->render(
            '@AcMarcheMercredi/security/login.html.twig',
            [
                'last_username' => $authenticationUtils->getLastUsername(),
                'error' => $authenticationUtils->getLastAuthenticationError(),
                'email' => (string) $session->get(self::LINK_EMAIL_SESSION_KEY, ''),
                'email_error' => \is_string($emailError) ? $emailError : null,
                'organisation' => $this->organisationRepository->getOrganisation(),
            ],
        );
    }

    #[Route(path: '/login/with/email', name: 'app_login_with_email', methods: ['POST'])]
    public function loginWithEmail(Request $request): Response
    {
        $email = trim((string) $request->request->get('email', $request->request->get('username', '')));

        if (!$this->isCsrfTokenValid('authenticate', (string) $request->request->get('_csrf_token'))) {
            return $this->backToLogin($request, $email, 'La page a expiré. Veuillez réessayer.');
        }

        if ('' === $email) {
            return $this->backToLogin($request, $email, 'Indiquez votre adresse e-mail.');
        }

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE)) {
            return $this->backToLogin(
                $request,
                $email,
                "Cette adresse e-mail ne semble pas complète. Vérifiez-la, par exemple\u{00A0}: prenom.nom@exemple.be",
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

                return $this->backToLogin(
                    $request,
                    $email,
                    'Le message n\'a pas pu être envoyé. Réessayez dans quelques minutes, ou connectez-vous avec votre mot de passe.',
                );
            }
        }

        $session = $request->getSession();
        $session->set(self::LINK_EMAIL_SESSION_KEY, $email);
        if ($request->request->getBoolean('resend')) {
            $session->set(self::LINK_RESENT_SESSION_KEY, (new \DateTimeImmutable())->format('H:i'));
        }

        return $this->redirectToRoute('app_login_link_sent');
    }

    #[Route(path: '/login/lien-envoye', name: 'app_login_link_sent', methods: ['GET'])]
    public function linkSent(Request $request): Response
    {
        $session = $request->getSession();
        $email = $session->get(self::LINK_EMAIL_SESSION_KEY);

        if (!\is_string($email) || '' === $email) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('@AcMarcheMercredi/security/link_sent.html.twig', [
            'email' => $email,
            'resent_at' => $session->remove(self::LINK_RESENT_SESSION_KEY),
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

    /**
     * Post/Redirect/Get: the error is shown on /login, so a refresh never re-posts the form.
     */
    private function backToLogin(Request $request, string $email, string $error): Response
    {
        $session = $request->getSession();
        $session->set(self::LINK_EMAIL_SESSION_KEY, $email);
        $session->set(self::LINK_ERROR_SESSION_KEY, $error);

        return $this->redirectToRoute('app_login', [], Response::HTTP_SEE_OTHER);
    }
}
