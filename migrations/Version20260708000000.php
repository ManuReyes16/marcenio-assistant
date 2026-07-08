<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260708000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope tasks and notes by Telegram chat ID.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE task ADD COLUMN telegram_chat_id VARCHAR(64) DEFAULT '' NOT NULL");
        $this->addSql('CREATE INDEX IDX_TASK_TELEGRAM_CHAT_ID ON task (telegram_chat_id)');
        $this->addSql("ALTER TABLE note ADD COLUMN telegram_chat_id VARCHAR(64) DEFAULT '' NOT NULL");
        $this->addSql('CREATE INDEX IDX_NOTE_TELEGRAM_CHAT_ID ON note (telegram_chat_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_TASK_TELEGRAM_CHAT_ID');
        $this->addSql('DROP INDEX IDX_NOTE_TELEGRAM_CHAT_ID');
        $this->addSql('CREATE TEMPORARY TABLE __temp__task AS SELECT id, tittle, is_done, title FROM task');
        $this->addSql('DROP TABLE task');
        $this->addSql('CREATE TABLE task (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, tittle VARCHAR(255) NOT NULL, is_done BOOLEAN NOT NULL, title VARCHAR(255) NOT NULL)');
        $this->addSql('INSERT INTO task (id, tittle, is_done, title) SELECT id, tittle, is_done, title FROM __temp__task');
        $this->addSql('DROP TABLE __temp__task');
        $this->addSql('CREATE TEMPORARY TABLE __temp__note AS SELECT id, content FROM note');
        $this->addSql('DROP TABLE note');
        $this->addSql('CREATE TABLE note (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, content CLOB NOT NULL)');
        $this->addSql('INSERT INTO note (id, content) SELECT id, content FROM __temp__note');
        $this->addSql('DROP TABLE __temp__note');
    }
}
