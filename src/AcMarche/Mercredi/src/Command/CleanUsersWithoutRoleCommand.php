<?php

namespace AcMarche\Mercredi\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Supprime les comptes sans rôle créés il y a plus de N mois (inscriptions jamais
 * rattachées à un parent, un animateur ou une école).
 * Un compte encore lié à une fiche n'est jamais supprimé.
 */
#[AsCommand(
    name: 'mercredi:clean-users-without-role',
    description: 'Supprime les comptes utilisateurs sans rôle créés il y a plus de 3 mois',
)]
final class CleanUsersWithoutRoleCommand extends Command
{
    /**
     * Tables qui référencent user.id sans ON DELETE CASCADE : à vider avant.
     */
    private const array DEPENDENT_TABLES = ['token', 'reset_password_request'];

    /**
     * Un compte lié à une de ces tables est utilisé : on le garde.
     */
    private const array LINK_TABLES = ['user_tuteur', 'user_animateur', 'user_ecole'];

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('months', null, InputOption::VALUE_REQUIRED, 'Âge minimum du compte, en mois', '3')
            ->addOption('flush', null, InputOption::VALUE_NONE, 'Exécute les suppressions (sinon dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $months = $input->getOption('months');
        $flush = (bool) $input->getOption('flush');

        if (!ctype_digit((string) $months) || (int) $months < 1) {
            $io->error('--months doit être un entier supérieur ou égal à 1.');

            return Command::INVALID;
        }

        $before = new \DateTimeImmutable(\sprintf('-%d months', (int) $months));

        $notLinked = implode(' AND ', array_map(
            static fn (string $table): string => \sprintf('NOT EXISTS (SELECT 1 FROM `%s` l WHERE l.user_id = u.id)', $table),
            self::LINK_TABLES,
        ));
        // JSON_LENGTH fonctionne avec le type JSON de MySQL comme avec le LONGTEXT de MariaDB.
        $users = $this->connection->fetchAllAssociative(
            'SELECT u.id, u.username, u.created_at, u.last_login FROM `user` u
             WHERE JSON_LENGTH(u.roles) = 0 AND u.created_at < ? AND '.$notLinked.'
             ORDER BY u.id',
            [$before->format('Y-m-d H:i:s')],
        );

        $io->title(\sprintf('Comptes sans rôle créés avant le %s', $before->format('d/m/Y')));

        if ([] === $users) {
            $io->success('Rien à faire : aucun compte à supprimer.');

            return Command::SUCCESS;
        }

        if ($io->isVerbose()) {
            $io->table(
                ['Id', 'Username', 'Créé le', 'Dernière connexion'],
                array_map(static fn (array $user): array => [
                    $user['id'],
                    $user['username'],
                    $user['created_at'],
                    $user['last_login'] ?? '-',
                ], $users),
            );
        }

        $io->definitionList(
            ['Comptes à supprimer' => \count($users)],
            ['dont déjà connectés au moins une fois' => \count(array_filter(array_column($users, 'last_login')))],
        );

        if (!$flush) {
            $io->note('Dry-run : aucune suppression. Utilisez --flush pour appliquer, -v pour la liste des comptes.');

            return Command::SUCCESS;
        }

        $ids = array_map(intval(...), array_column($users, 'id'));

        $this->connection->transactional(static function (Connection $connection) use ($ids): void {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (self::DEPENDENT_TABLES as $table) {
                    $connection->executeStatement(
                        \sprintf('DELETE FROM `%s` WHERE user_id IN (?)', $table),
                        [$chunk],
                        [ArrayParameterType::INTEGER],
                    );
                }
                // La condition sur les rôles est répétée : un compte qui a reçu un rôle entre-temps est épargné.
                $connection->executeStatement(
                    'DELETE FROM `user` WHERE id IN (?) AND JSON_LENGTH(roles) = 0',
                    [$chunk],
                    [ArrayParameterType::INTEGER],
                );
            }
        });

        $io->success(\sprintf('%d compte(s) supprimé(s).', \count($ids)));

        return Command::SUCCESS;
    }
}
