<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les colonnes de signalement sur la table message (est_signale, raison_signalement, date_signalement)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD est_signale BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE message ADD raison_signalement TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD date_signalement TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP date_signalement');
        $this->addSql('ALTER TABLE message DROP raison_signalement');
        $this->addSql('ALTER TABLE message DROP est_signale');
    }
}