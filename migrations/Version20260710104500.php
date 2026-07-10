<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260710104500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align SQLite note.telegram_chat_id schema with Doctrine mapping.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof SQLitePlatform,
            'This migration rebuilds the note table using SQLite-specific SQL.'
        );

        if ($this->hasDefaultValue('note', 'telegram_chat_id')) {
            $this->addSql('DROP INDEX IF EXISTS IDX_NOTE_TELEGRAM_CHAT_ID');
            $this->addSql('CREATE TEMPORARY TABLE __temp__note AS SELECT id, content, telegram_chat_id FROM note');
            $this->addSql('DROP TABLE note');
            $this->addSql('CREATE TABLE note (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, content CLOB NOT NULL, telegram_chat_id VARCHAR(64) NOT NULL)');
            $this->addSql('INSERT INTO note (id, content, telegram_chat_id) SELECT id, content, telegram_chat_id FROM __temp__note');
            $this->addSql('DROP TABLE __temp__note');
        }

        if ($this->tableExists('note')) {
            $this->addSql('CREATE INDEX IF NOT EXISTS IDX_NOTE_TELEGRAM_CHAT_ID ON note (telegram_chat_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Reintroducing the SQLite default on note.telegram_chat_id is not supported.');
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        );
    }

    private function hasDefaultValue(string $table, string $column): bool
    {
        foreach ($this->columns($table) as $metadata) {
            if ($metadata['name'] === $column) {
                return $metadata['dflt_value'] !== null;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{name: string, dflt_value: mixed}>
     */
    private function columns(string $table): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        return $this->connection->fetchAllAssociative(sprintf('PRAGMA table_info(%s)', $table));
    }
}
