<?php

namespace App\Tests\Command;

use AcMarche\Mercredi\Command\CleanUsersWithoutRoleCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanUsersWithoutRoleCommandTest extends TestCase
{
    private const array USERS = [
        ['id' => 10, 'username' => 'spam@example.com', 'created_at' => '2026-01-02 03:00:00', 'last_login' => null],
        ['id' => 11, 'username' => 'old@example.com', 'created_at' => '2026-02-02 10:00:00', 'last_login' => '2026-02-03 10:00:00'],
    ];

    /** @var list<array{string, list<mixed>}> */
    private array $selects = [];

    /** @var list<array{string, list<int>}> */
    private array $statements = [];

    public function testDryRunDeletesNothing(): void
    {
        $tester = $this->createTester(self::USERS);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([], $this->statements);
        self::assertStringContainsString('Dry-run', $tester->getDisplay());
    }

    public function testSelectsRolelessUnlinkedAccountsOlderThanThreeMonths(): void
    {
        $this->createTester(self::USERS)->execute([]);

        [$sql, $params] = $this->selects[0];
        self::assertStringContainsString('JSON_LENGTH(u.roles) = 0', $sql);
        self::assertStringContainsString('u.created_at < ?', $sql);
        foreach (['user_tuteur', 'user_animateur', 'user_ecole'] as $table) {
            self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM `'.$table.'`', $sql);
        }
        self::assertSame((new \DateTimeImmutable('-3 months'))->format('Y-m-d'), substr($params[0], 0, 10));
    }

    public function testMonthsOptionMovesTheCutoff(): void
    {
        $this->createTester(self::USERS)->execute(['--months' => '12']);

        self::assertSame((new \DateTimeImmutable('-12 months'))->format('Y-m-d'), substr($this->selects[0][1][0], 0, 10));
    }

    public function testFlushDeletesDependentRowsBeforeUsers(): void
    {
        $tester = $this->createTester(self::USERS);

        self::assertSame(Command::SUCCESS, $tester->execute(['--flush' => true]));
        self::assertSame([
            ['DELETE FROM `token` WHERE user_id IN (?)', [10, 11]],
            ['DELETE FROM `reset_password_request` WHERE user_id IN (?)', [10, 11]],
            ['DELETE FROM `user` WHERE id IN (?) AND JSON_LENGTH(roles) = 0', [10, 11]],
        ], $this->statements);
    }

    public function testNothingToDelete(): void
    {
        $tester = $this->createTester([]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--flush' => true]));
        self::assertSame([], $this->statements);
    }

    public function testRejectsInvalidMonths(): void
    {
        $tester = $this->createTester(self::USERS);

        self::assertSame(Command::INVALID, $tester->execute(['--months' => '0', '--flush' => true]));
        self::assertSame([], $this->selects);
        self::assertSame([], $this->statements);
    }

    /**
     * @param list<array<string, mixed>> $users
     */
    private function createTester(array $users): CommandTester
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params) use ($users): array {
                $this->selects[] = [$sql, $params];

                return $users;
            },
        );
        $connection->method('transactional')->willReturnCallback(
            static fn (\Closure $callback): mixed => $callback($connection),
        );
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params): int {
                $this->statements[] = [$sql, $params[0]];

                return \count($params[0]);
            },
        );

        return new CommandTester(new CleanUsersWithoutRoleCommand($connection));
    }
}
