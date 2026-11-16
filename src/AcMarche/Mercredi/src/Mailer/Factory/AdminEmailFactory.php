<?php

namespace AcMarche\Mercredi\Mailer\Factory;

use AcMarche\Mercredi\Entity\Enfant;
use AcMarche\Mercredi\Entity\Facture\FactureCron;
use AcMarche\Mercredi\Entity\Security\User;
use AcMarche\Mercredi\Entity\Tuteur;
use AcMarche\Mercredi\Mailer\TemplatedEmailFactory;
use AcMarche\Mercredi\Organisation\Traits\OrganisationPropertyInitTrait;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Security\Core\User\UserInterface;

class AdminEmailFactory
{
    use OrganisationPropertyInitTrait;

    public function messageEnfantCreated(User|UserInterface $user, Enfant $enfant): TemplatedEmail
    {
        $message = TemplatedEmailFactory::asPublicEmailJf();
        $message
            ->from($this->getEmailSenderAddress())
            ->to($this->getEmailContact())
            ->subject('Un enfant a été ajouté par '.$user->getNom().' '.$user->getPrenom())
            ->textTemplate('@AcMarcheMercrediEmail/admin/_mail_add_enfant.html.twig')
            ->context(
                [
                    'user' => $user,
                    'enfant' => $enfant,
                    'footer_text' => 'orga',
                    'organisation' => $this->organisation,
                ]
            );

        return $message;
    }

    /**
     * @param Enfant[] $enfants
     */
    public function messagEnfantsOrphelins(array $enfants): TemplatedEmail
    {
        $message = TemplatedEmailFactory::asPublicEmailJf();
        $message
            ->from($this->getEmailSenderAddress())
            ->to($this->getEmailContact())
            ->subject('Des enfants orphelins ont été trouvés')
            ->textTemplate('@AcMarcheMercrediEmail/admin/_mail_orphelins.html.twig')
            ->context(
                [
                    'enfants' => $enfants,
                    'footer_text' => 'orga',
                    'organisation' => $this->organisation,
                ]
            );

        return $message;
    }

    /**
     * @param array|Tuteur[] $tuteurs
     */
    public function messageTuteurArchived(array $tuteurs): TemplatedEmail
    {
        $message = TemplatedEmailFactory::asPublicEmailJf();
        $message
            ->from($this->getEmailSenderAddress())
            ->to($this->getEmailContact())
            ->subject('Les tuteurs ont été archivés')
            ->textTemplate('@AcMarcheMercrediEmail/admin/_mail_tuteurs_archived.html.twig')
            ->context(
                [
                    'tuteurs' => $tuteurs,
                    'footer_text' => 'orga',
                    'organisation' => $this->organisation,
                ]
            );

        return $message;
    }

    public function messageAlert(string $subject, string $texte): TemplatedEmail
    {
        $message = TemplatedEmailFactory::asPublicEmailJf();
        $message
            ->from($this->getEmailSenderAddress())
            ->to($this->getEmailContact())
            ->subject($subject)
            ->text($texte);

        return $message;
    }

    /**
     * Résumé envoyé à l'adresse d'expédition quand tous les envois du mois sont traités.
     *
     * @param array{envoyees: int, erreurs: list<array{facture: int, tuteur: string, erreur: string}>} $resume
     */
    public function messageFactureCronResume(FactureCron $cron, array $resume): TemplatedEmail
    {
        $to = $cron->getFromAdresse();
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $to = $this->getEmailContact();
        }

        $lines = [
            'L\'envoi des factures de '.$cron->getMonthDate().' est terminé.',
            '',
            'Factures envoyées : '.$resume['envoyees'],
            'Factures en erreur : '.\count($resume['erreurs']),
        ];
        if ([] !== $resume['erreurs']) {
            $lines[] = '';
            $lines[] = 'Ces factures n\'ont pas été envoyées :';
            foreach ($resume['erreurs'] as $erreur) {
                $lines[] = \sprintf('- Facture %d (%s) : %s', $erreur['facture'], $erreur['tuteur'], $erreur['erreur']);
            }
        }

        $message = TemplatedEmailFactory::asPublicEmailJf();
        $message
            ->from($this->getEmailSenderAddress())
            ->to($to)
            ->subject('Résultat de l\'envoi des factures '.$cron->getMonthDate())
            ->text(implode("\n", $lines));

        return $message;
    }

    public function messageToJf(string $subject, string $texte): TemplatedEmail
    {
        $message = TemplatedEmailFactory::asPublicEmailJf();
        $message
            ->from($this->getEmailSenderAddress())
            ->to('jf@marche.be')
            ->subject($subject)
            ->text($texte);

        return $message;
    }
}
