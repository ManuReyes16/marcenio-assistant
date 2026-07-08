<?php

namespace App\Service;

use App\Entity\Note;
use App\Repository\NoteRepository;
use Doctrine\ORM\EntityManagerInterface;

class NoteService
{
    public function __construct(
        private NoteRepository $noteRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * @return Note[]
     */
    public function findAll(string $telegramChatId): array
    {
        return $this->noteRepository->findBy(
            ['telegramChatId' => $telegramChatId],
            ['id' => 'ASC']
        );
    }

    public function create(string $telegramChatId, string $content): Note
    {
        $note = new Note();
        $note->setContent($content);
        $note->setTelegramChatId($telegramChatId);

        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return $note;
    }

    public function delete(string $telegramChatId, int $id): ?Note
    {
        $note = $this->noteRepository->findOneBy([
            'id' => $id,
            'telegramChatId' => $telegramChatId,
        ]);

        if (!$note instanceof Note) {
            return null;
        }

        $this->entityManager->remove($note);
        $this->entityManager->flush();

        return $note;
    }
}
