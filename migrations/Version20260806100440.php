<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260806100440 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservation ALTER commission TYPE DOUBLE PRECISION');
        $this->addSql('ALTER TABLE reservation ALTER commission DROP DEFAULT');
        $this->addSql('ALTER TABLE trajet DROP quartier_retour');
        $this->addSql('ALTER TABLE vehicule DROP photo');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservation ALTER commission TYPE INT');
        $this->addSql('ALTER TABLE reservation ALTER commission SET DEFAULT 0');
        $this->addSql('ALTER TABLE trajet ADD quartier_retour VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ADD photo VARCHAR(255) DEFAULT NULL');
    }
}
