<?php

namespace AcMarche\Mercredi\Facture\Handler;

use AcMarche\Mercredi\Entity\Facture\Facture;
use AcMarche\Mercredi\Entity\Facture\FactureCron;
use AcMarche\Mercredi\Facture\Factory\FactureFactory;
use AcMarche\Mercredi\Facture\Repository\FactureCronRepository;
use AcMarche\Mercredi\Facture\Repository\FactureRepository;
use AcMarche\Mercredi\Mailer\Factory\AdminEmailFactory;
use AcMarche\Mercredi\Mailer\Factory\FactureEmailFactory;
use AcMarche\Mercredi\Tuteur\Utils\TuteurUtils;
use Carbon\Carbon;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

class FactureCronHandler
{
    /** Nombre de passages en échec d'envoi avant d'abandonner une facture */
    public const int MAX_TENTATIVES = 3;

    public function __construct(
        private FactureCronRepository $factureCronRepository,
        private FactureRepository $factureRepository,
        private FactureEmailFactory $factureEmailFactory,
        private FactureFactory $factureFactory,
        private AdminEmailFactory $adminEmailFactory,
        private TransportInterface $transport,
        #[Target('mailer')]
        private RateLimiterFactoryInterface $mailerLimiter,
    ) {
    }

    public function execute(int $max = 60): array
    {
        $result = [];
        $crons = $this->factureCronRepository->findNotDone();

        $now = Carbon::now();
        foreach ($crons as $cron) {
            if ($cron->getDateLastSync()) {

                $lastSync = new Carbon($cron->getDateLastSync());

                if ($now->diffInMinutes($lastSync, true) < 60) {
                    $result[] = [
                        'message' => ' Last sync too early. Minutes: '.$now->diffInMinutes($lastSync, true),
                    ];
                    continue;
                }
            }

            $cron->setDateLastSync($now->modify('-5 minutes'));
            $this->factureCronRepository->flush();

            $factures = $this->factureRepository->findFacturesByMonthNotSendAndNotPaid($cron->getMonthDate());

            $messageBase = $this->factureEmailFactory->messageFacture(
                $cron->getSubject(),
                $cron->getBody()
            );
            $i = 0;
            // Reste vrai si chaque facture du mois est soit envoyée, soit abandonnée en erreur
            $termine = true;
            foreach ($factures as $facture) {
                // Quota atteint : le reste part au prochain passage (testé avant de générer le pdf)
                if ($i >= $max) {
                    $termine = false;
                    break;
                }

                $messageFacture = clone $messageBase; //sinon attachs multiple

                try {
                    $this->factureFactory->createOnePdf($facture, $cron->getMonthDate(), $cron->force_send);
                } catch (\Exception $e) {
                    $result[] = $this->markError($facture, 'Impossible de générer le pdf: '.$e->getMessage());
                    continue;
                }

                $tuteur = $facture->getTuteur();
                $emails = TuteurUtils::getEmailsOfOneTuteur($tuteur);

                if (\count($emails) < 1) {
                    $result[] = $this->markError($facture, 'Pas de mail');
                    continue;
                }

                $this->factureEmailFactory->setTos($messageFacture, $emails);

                try {
                    $this->factureEmailFactory->attachFactureFromPath($messageFacture, $facture);
                } catch (\Exception $e) {
                    $result[] = $this->markError($facture, 'Pas de pièce jointe: '.$e->getMessage());
                    continue;
                }

                // Same hourly quota as the Messenger worker: the rest goes on the next run
                if (!$this->mailerLimiter->create()->consume()->isAccepted()) {
                    $result[] = ['message' => 'count '.$i];
                    $result[] = ['message' => 'Limite horaire d\'envoi de mails atteinte, suite au prochain passage'];

                    return $result;
                }

                try {
                    // Envoi direct par le transport : pas de file Messenger, la facture
                    // n'est marquée envoyée que si le serveur SMTP a accepté le mail
                    $this->transport->send($messageFacture);
                } catch (TransportExceptionInterface $e) {
                    // Une panne du serveur de mail peut être passagère : la facture n'est
                    // abandonnée qu'après plusieurs passages en échec
                    $tentatives = $facture->incrementEnvoiTentatives();
                    if ($tentatives >= self::MAX_TENTATIVES) {
                        $result[] = $this->markError(
                            $facture,
                            'Envoi refusé après '.$tentatives.' tentatives: '.$e->getMessage()
                        );
                        continue;
                    }
                    $this->factureRepository->flush();
                    $termine = false;
                    $result[] = [
                        'message' => \sprintf(
                            '%d error envoie (tentative %d/%d) %s',
                            $facture->getId(),
                            $tentatives,
                            self::MAX_TENTATIVES,
                            $e->getMessage()
                        ),
                    ];
                    continue;
                }

                $facture->setEnvoyeA(implode(', ', $emails));
                $facture->setEnvoyeLe(new \DateTime());
                $facture->setEnvoiResultat(Facture::ENVOI_OK);
                $this->factureRepository->flush();
                ++$i;
            }
            $result[] = ['message' => 'count '.$i];

            if ($termine) {
                $result[] = $this->finish($cron);
            }
        }

        return $result;
    }

    public function sendResult(array $result): void
    {
        $message = $this->adminEmailFactory->messageToJf('Result envoie facture', json_encode($result));
        $this->transport->send($message);
    }

    /**
     * L'envoi de cette facture est abandonné : elle ne sera plus reprise par les
     * passages suivants et figurera dans le résumé final.
     *
     * @return array{message: string}
     */
    private function markError(Facture $facture, string $error): array
    {
        $facture->setEnvoiResultat($error);
        $this->factureRepository->flush();

        return ['message' => $facture->getId().' erreur: '.$error];
    }

    /**
     * Toutes les factures du mois sont traitées : le résumé part à l'adresse
     * d'expédition, puis la tâche est clôturée. Si le résumé ne part pas, la tâche
     * reste ouverte et il est retenté au prochain passage.
     *
     * @return array{message: string}
     */
    private function finish(FactureCron $cron): array
    {
        $month = $cron->getMonthDate();
        $resume = [
            'envoyees' => $this->factureRepository->countSentByMonth($month),
            'erreurs' => array_map(
                static fn (Facture $facture): array => [
                    'facture' => $facture->getId(),
                    'tuteur' => trim($facture->getNom().' '.$facture->getPrenom()),
                    'erreur' => $facture->getEnvoiResultat(),
                ],
                $this->factureRepository->findFacturesByMonthWithSendError($month)
            ),
        ];

        try {
            $this->transport->send($this->adminEmailFactory->messageFactureCronResume($cron, $resume));
        } catch (TransportExceptionInterface $e) {
            return ['message' => $cron->getId().' résumé non envoyé, nouvel essai au prochain passage: '.$e->getMessage()];
        }

        $cron->results = $resume;
        $cron->setDone(true);
        $this->factureCronRepository->flush();

        return [
            'message' => \sprintf(
                '%d terminé: %d envoyées, %d en erreur',
                $cron->getId(),
                $resume['envoyees'],
                \count($resume['erreurs'])
            ),
        ];
    }
}
