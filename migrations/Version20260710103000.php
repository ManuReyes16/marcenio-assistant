<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260710103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove legacy misspelled task.tittle column from SQLite schema.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->platform instanceof SQLitePlatform,
            'This migration rebuilds the task table using SQLite-specific SQL.'
        );

        if ($this->hasColumn('task', 'tittle')) {
            $this->addSql('DROP INDEX IF EXISTS IDX_TASK_TELEGRAM_CHAT_ID');
            $this->addSql("CREATE TEMPORARY TABLE __temp__task AS SELECT id, COALESCE(NULLIF(title, ''), tittle) AS title, telegram_chat_id, is_done FROM task");
            $this->addSql('DROP TABLE task');
            $this->addSql('CREATE TABLE task (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, telegram_chat_id VARCHAR(64) NOT NULL, is_done BOOLEAN NOT NULL)');
            $this->addSql('INSERT INTO task (id, title, telegram_chat_id, is_done) SELECT id, title, telegram_chat_id, is_done FROM __temp__task');
            $this->addSql('DROP TABLE __temp__task');
        }

        if ($this->tableExists('task')) {
            $this->addSql('CREATE INDEX IF NOT EXISTS IDX_TASK_TELEGRAM_CHAT_ID ON task (telegram_chat_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Reintroducing the legacy task.tittle column is not supported.');
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        );
    }

    private function hasColumn(string $table, string $column): bool
    {
        foreach ($this->columns($table) as $metadata) {
            if ($metadata['name'] === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{name: string}>
     */
    private function columns(string $table): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        return $this->connection->fetchAllAssociative(sprintf('PRAGMA table_info(%s)', $table));
    }
}
