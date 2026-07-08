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
    public function findAll(): array
    {
        return $this->noteRepository->findAll();
    }

    public function create(string $content): Note
    {
        $note = new Note();
        $note->setContent($content);

        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return $note;
    }

    public function delete(int $id): ?Note
    {
        $note = $this->noteRepository->find($id);

        if (!$note instanceof Note) {
            return null;
        }

        $this->entityManager->remove($note);
        $this->entityManager->flush();

        return $note;
    }
}
