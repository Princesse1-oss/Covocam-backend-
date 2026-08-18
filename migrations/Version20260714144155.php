<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260714144155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE vehicule ADD annee INT NOT NULL');
        $this->addSql('ALTER TABLE vehicule ADD carburant VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ADD boite_vitesse VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ADD climatisation BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE vehicule ADD gps BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE vehicule ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ADD photo_avant VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ADD photo_arriere VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ADD photo_interieur VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ADD photo_coffre VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ADD date_creation TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE vehicule ADD date_modification TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ALTER marque TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE vehicule ALTER modele TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE vehicule ALTER couleur TYPE VARCHAR(30)');
        $this->addSql('ALTER TABLE vehicule ALTER plaque_immatriculation TYPE VARCHAR(20)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_292FFF1D77A865CE ON vehicule (plaque_immatriculation)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_292FFF1D77A865CE');
        $this->addSql('ALTER TABLE vehicule DROP annee');
        $this->addSql('ALTER TABLE vehicule DROP carburant');
        $this->addSql('ALTER TABLE vehicule DROP boite_vitesse');
        $this->addSql('ALTER TABLE vehicule DROP climatisation');
        $this->addSql('ALTER TABLE vehicule DROP gps');
        $this->addSql('ALTER TABLE vehicule DROP description');
        $this->addSql('ALTER TABLE vehicule DROP photo_avant');
        $this->addSql('ALTER TABLE vehicule DROP photo_arriere');
        $this->addSql('ALTER TABLE vehicule DROP photo_interieur');
        $this->addSql('ALTER TABLE vehicule DROP photo_coffre');
        $this->addSql('ALTER TABLE vehicule DROP date_creation');
        $this->addSql('ALTER TABLE vehicule DROP date_modification');
        $this->addSql('ALTER TABLE vehicule ALTER marque TYPE VARCHAR(30)');
        $this->addSql('ALTER TABLE vehicule ALTER modele TYPE VARCHAR(30)');
        $this->addSql('ALTER TABLE vehicule ALTER couleur TYPE VARCHAR(20)');
        $this->addSql('ALTER TABLE vehicule ALTER plaque_immatriculation TYPE VARCHAR(30)');
    }
}
