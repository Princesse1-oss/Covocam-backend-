<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811164104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE evaluation ADD trajet_id INT NOT NULL');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A575D12A823 FOREIGN KEY (trajet_id) REFERENCES trajet (id)');
        $this->addSql('CREATE INDEX IDX_1323A575D12A823 ON evaluation (trajet_id)');
        $this->addSql('ALTER TABLE trajet ALTER statut TYPE VARCHAR(30)');
        $this->addSql('ALTER TABLE utilisateur ADD total_evaluations INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE utilisateur ALTER note_moyenne SET DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE evaluation DROP CONSTRAINT FK_1323A575D12A823');
        $this->addSql('DROP INDEX IDX_1323A575D12A823');
        $this->addSql('ALTER TABLE evaluation DROP trajet_id');
        $this->addSql('ALTER TABLE trajet ALTER statut TYPE VARCHAR(20)');
        $this->addSql('ALTER TABLE utilisateur DROP total_evaluations');
        $this->addSql('ALTER TABLE utilisateur ALTER note_moyenne DROP DEFAULT');
    }
}
