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
    public function findAll(): array
    {
        return $this->taskRepository->findAll();
    }

    public function create(string $title): Task
    {
        $task = new Task();
        $task->setTitle($title);
        $task->setIsDone(false);

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    public function markDone(int $id): ?Task
    {
        $task = $this->taskRepository->find($id);

        if (!$task instanceof Task) {
            return null;
        }

        $task->setIsDone(true);
        $this->entityManager->flush();

        return $task;
    }

    public function delete(int $id): ?Task
    {
        $task = $this->taskRepository->find($id);

        if (!$task instanceof Task) {
            return null;
        }

        $this->entityManager->remove($task);
        $this->entityManager->flush();

        return $task;
    }
}
