<?php

namespace App\Service;

use App\Entity\Task;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;

class TaskService
{
    public function __construct(
        private TaskRepository $taskRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * @return Task[]
     */
    public function findAll(string $telegramChatId): array
    {
        return $this->taskRepository->findBy(
            ['telegramChatId' => $telegramChatId],
            ['id' => 'ASC']
        );
    }

    public function create(string $telegramChatId, string $title): Task
    {
        $task = new Task();
        $task->setTitle($title);
        $task->setTelegramChatId($telegramChatId);
        $task->setIsDone(false);

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    public function markDone(string $telegramChatId, int $id): ?Task
    {
        $task = $this->taskRepository->findOneBy([
            'id' => $id,
            'telegramChatId' => $telegramChatId,
        ]);

        if (!$task instanceof Task) {
            return null;
        }

        $task->setIsDone(true);
        $this->entityManager->flush();

        return $task;
    }

    public function delete(string $telegramChatId, int $id): ?Task
    {
        $task = $this->taskRepository->findOneBy([
            'id' => $id,
            'telegramChatId' => $telegramChatId,
        ]);

        if (!$task instanceof Task) {
            return null;
        }

        $this->entityManager->remove($task);
        $this->entityManager->flush();

        return $task;
    }
}
