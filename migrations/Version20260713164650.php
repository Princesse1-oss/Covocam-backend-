<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260713164650 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE vehicule DROP CONSTRAINT fk_292fff1dfb88e14f');
        $this->addSql('DROP INDEX idx_292fff1dfb88e14f');
        $this->addSql('ALTER TABLE vehicule ADD annee INT NOT NULL');
        $this->addSql('ALTER TABLE vehicule ADD immatriculation VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE vehicule ADD nb_places INT NOT NULL');
        $this->addSql('ALTER TABLE vehicule ADD carburant VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ADD boite_vitesse VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ADD gps BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE vehicule ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ADD photo_avant VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE vehicule ADD photo_arriere VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE vehicule ADD photo_coffre VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ADD date_creation TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE vehicule ADD date_modification TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ADD conducteur_id INT NOT NULL');
        $this->addSql('ALTER TABLE vehicule DROP plaque_immatriculation');
        $this->addSql('ALTER TABLE vehicule DROP places');
        $this->addSql('ALTER TABLE vehicule DROP utilisateur_id');
        $this->addSql('ALTER TABLE vehicule ALTER marque TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE vehicule ALTER modele TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE vehicule ALTER couleur TYPE VARCHAR(30)');
        $this->addSql('ALTER TABLE vehicule RENAME COLUMN est_defaut TO climatisation');
        $this->addSql('ALTER TABLE vehicule RENAME COLUMN photo TO photo_interieur');
        $this->addSql('ALTER TABLE vehicule ADD CONSTRAINT FK_292FFF1DF16F4AC6 FOREIGN KEY (conducteur_id) REFERENCES utilisateur (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_292FFF1DBE73422E ON vehicule (immatriculation)');
        $this->addSql('CREATE INDEX IDX_292FFF1DF16F4AC6 ON vehicule (conducteur_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE vehicule DROP CONSTRAINT FK_292FFF1DF16F4AC6');
        $this->addSql('DROP INDEX UNIQ_292FFF1DBE73422E');
        $this->addSql('DROP INDEX IDX_292FFF1DF16F4AC6');
        $this->addSql('ALTER TABLE vehicule ADD plaque_immatriculation VARCHAR(30) NOT NULL');
        $this->addSql('ALTER TABLE vehicule ADD places INT NOT NULL');
        $this->addSql('ALTER TABLE vehicule ADD photo VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicule ADD est_defaut BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE vehicule ADD utilisateur_id INT NOT NULL');
        $this->addSql('ALTER TABLE vehicule DROP annee');
        $this->addSql('ALTER TABLE vehicule DROP immatriculation');
        $this->addSql('ALTER TABLE vehicule DROP nb_places');
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
        $this->addSql('ALTER TABLE vehicule DROP conducteur_id');
        $this->addSql('ALTER TABLE vehicule ALTER marque TYPE VARCHAR(30)');
        $this->addSql('ALTER TABLE vehicule ALTER modele TYPE VARCHAR(30)');
        $this->addSql('ALTER TABLE vehicule ALTER couleur TYPE VARCHAR(20)');
        $this->addSql('ALTER TABLE vehicule ADD CONSTRAINT fk_292fff1dfb88e14f FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_292fff1dfb88e14f ON vehicule (utilisateur_id)');
    }
}
