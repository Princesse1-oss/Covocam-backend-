<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260805210016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservation ADD commission DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE trajet ADD date_heure_depart TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE trajet ADD bagage_autorise BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE trajet DROP date_depart');
        $this->addSql('ALTER TABLE trajet DROP heure_depart');
        $this->addSql('ALTER TABLE trajet RENAME COLUMN quartier_retour TO point_retour');
        $this->addSql('ALTER TABLE vehicule DROP photo');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservation DROP commission');
        $this->addSql('ALTER TABLE trajet ADD heure_depart TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE trajet DROP bagage_autorise');
        $this->addSql('ALTER TABLE trajet RENAME COLUMN point_retour TO quartier_retour');
        $this->addSql('ALTER TABLE trajet RENAME COLUMN date_heure_depart TO date_depart');
        $this->addSql('ALTER TABLE vehicule ADD photo VARCHAR(255) DEFAULT NULL');
    }
}
