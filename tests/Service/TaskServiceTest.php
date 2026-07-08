<?php

namespace App\Tests\Service;

use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\TaskService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class TaskServiceTest extends TestCase
{
    private const TELEGRAM_CHAT_ID = '123456';

    public function testItFindsTasksForTelegramChat(): void
    {
        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(['telegramChatId' => self::TELEGRAM_CHAT_ID], ['id' => 'ASC'])
            ->willReturn([]);

        $service = new TaskService($taskRepository, $this->createStub(EntityManagerInterface::class));

        self::assertSame([], $service->findAll(self::TELEGRAM_CHAT_ID));
    }

    public function testItCreatesTaskForTelegramChat(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (Task $task): bool {
                return $task->getTelegramChatId() === self::TELEGRAM_CHAT_ID
                    && $task->getTitle() === 'comprar pan'
                    && $task->isDone() === false;
            }));
        $entityManager->expects(self::once())->method('flush');

        $service = new TaskService($this->createStub(TaskRepository::class), $entityManager);

        $task = $service->create(self::TELEGRAM_CHAT_ID, 'comprar pan');

        self::assertSame(self::TELEGRAM_CHAT_ID, $task->getTelegramChatId());
    }

    public function testItMarksOnlyTaskFromTelegramChatAsDone(): void
    {
        $task = (new Task())
            ->setTelegramChatId(self::TELEGRAM_CHAT_ID)
            ->setTitle('comprar pan')
            ->setIsDone(false);

        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['id' => 7, 'telegramChatId' => self::TELEGRAM_CHAT_ID])
            ->willReturn($task);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = new TaskService($taskRepository, $entityManager);

        self::assertSame($task, $service->markDone(self::TELEGRAM_CHAT_ID, 7));
        self::assertTrue($task->isDone());
    }

    public function testItDeletesOnlyTaskFromTelegramChat(): void
    {
        $task = (new Task())
            ->setTelegramChatId(self::TELEGRAM_CHAT_ID)
            ->setTitle('comprar pan')
            ->setIsDone(false);

        $taskRepository = $this->createMock(TaskRepository::class);
        $taskRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['id' => 7, 'telegramChatId' => self::TELEGRAM_CHAT_ID])
            ->willReturn($task);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($task);
        $entityManager->expects(self::once())->method('flush');

        $service = new TaskService($taskRepository, $entityManager);

        self::assertSame($task, $service->delete(self::TELEGRAM_CHAT_ID, 7));
    }
}
