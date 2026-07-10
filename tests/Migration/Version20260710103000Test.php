<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260710103000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/migrations/Version20260710103000.php';

class Version20260710103000Test extends TestCase
{
    public function testItRemovesLegacyTittleColumnAndKeepsTaskInsertsWorking(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE task (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, tittle VARCHAR(255) NOT NULL, is_done BOOLEAN NOT NULL, title VARCHAR(255) NOT NULL, telegram_chat_id VARCHAR(64) DEFAULT \'\' NOT NULL)');
        $connection->executeStatement('CREATE INDEX IDX_TASK_TELEGRAM_CHAT_ID ON task (telegram_chat_id)');
        $connection->insert('task', [
            'id' => 1,
            'tittle' => 'legacy title',
            'title' => '',
            'telegram_chat_id' => '111',
            'is_done' => 1,
        ]);
        $connection->insert('task', [
            'id' => 2,
            'tittle' => 'ignored legacy title',
            'title' => 'current title',
            'telegram_chat_id' => '222',
            'is_done' => 0,
        ]);

        $migration = new Version20260710103000($connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $columns = array_column($connection->fetchAllAssociative('PRAGMA table_info(task)'), 'name');
        self::assertSame(['id', 'title', 'telegram_chat_id', 'is_done'], $columns);

        self::assertSame([
            ['id' => 1, 'title' => 'legacy title', 'telegram_chat_id' => '111', 'is_done' => 1],
            ['id' => 2, 'title' => 'current title', 'telegram_chat_id' => '222', 'is_done' => 0],
        ], $connection->fetchAllAssociative('SELECT id, title, telegram_chat_id, is_done FROM task ORDER BY id'));

        self::assertSame(
            [['name' => 'IDX_TASK_TELEGRAM_CHAT_ID']],
            $connection->fetchAllAssociative("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'task' ORDER BY name")
        );

        $connection->executeStatement(
            'INSERT INTO task (title, telegram_chat_id, is_done) VALUES (?, ?, ?)',
            ['new task', '333', 0]
        );

        self::assertSame(
            ['title' => 'new task', 'telegram_chat_id' => '333', 'is_done' => 0],
            $connection->fetchAssociative('SELECT title, telegram_chat_id, is_done FROM task WHERE id = 3')
        );
    }
}
