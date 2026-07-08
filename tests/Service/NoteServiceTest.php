<?php

namespace App\Tests\Service;

use App\Entity\Note;
use App\Repository\NoteRepository;
use App\Service\NoteService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class NoteServiceTest extends TestCase
{
    private const TELEGRAM_CHAT_ID = '123456';

    public function testItFindsNotesForTelegramChat(): void
    {
        $noteRepository = $this->createMock(NoteRepository::class);
        $noteRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(['telegramChatId' => self::TELEGRAM_CHAT_ID], ['id' => 'ASC'])
            ->willReturn([]);

        $service = new NoteService($noteRepository, $this->createStub(EntityManagerInterface::class));

        self::assertSame([], $service->findAll(self::TELEGRAM_CHAT_ID));
    }

    public function testItCreatesNoteForTelegramChat(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (Note $note): bool {
                return $note->getTelegramChatId() === self::TELEGRAM_CHAT_ID
                    && $note->getContent() === 'idea para el proyecto';
            }));
        $entityManager->expects(self::once())->method('flush');

        $service = new NoteService($this->createStub(NoteRepository::class), $entityManager);

        $note = $service->create(self::TELEGRAM_CHAT_ID, 'idea para el proyecto');

        self::assertSame(self::TELEGRAM_CHAT_ID, $note->getTelegramChatId());
    }

    public function testItDeletesOnlyNoteFromTelegramChat(): void
    {
        $note = (new Note())
            ->setTelegramChatId(self::TELEGRAM_CHAT_ID)
            ->setContent('idea para el proyecto');

        $noteRepository = $this->createMock(NoteRepository::class);
        $noteRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['id' => 4, 'telegramChatId' => self::TELEGRAM_CHAT_ID])
            ->willReturn($note);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($note);
        $entityManager->expects(self::once())->method('flush');

        $service = new NoteService($noteRepository, $entityManager);

        self::assertSame($note, $service->delete(self::TELEGRAM_CHAT_ID, 4));
    }
}
