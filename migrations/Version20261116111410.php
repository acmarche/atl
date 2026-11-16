<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116111410 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the result of the mail delivery on each invoice';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facture ADD envoi_resultat LONGTEXT DEFAULT NULL, ADD envoi_tentatives SMALLINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facture DROP envoi_resultat, DROP envoi_tentatives');
    }
}
