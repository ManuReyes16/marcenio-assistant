<?php

namespace App\Tests\Service;

use App\Entity\Note;
use App\Entity\Task;
use App\Service\AiCommandInterpreter;
use App\Service\BotCommandHandler;
use App\Service\InternalCommandValidator;
use App\Service\MultipleCommandHandler;
use App\Service\MultipleCommandInterpreter;
use App\Service\NoteService;
use App\Service\OpenAiCommandInterpreter;
use App\Service\TaskService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MultipleCommandHandlerTest extends TestCase
{
    private const TELEGRAM_CHAT_ID = '123456';

    public function testItHandlesOneInstruction(): void
    {
        $taskService = $this->createMock(TaskService::class);
        $taskService
            ->expects(self::once())
            ->method('create')
            ->with(self::TELEGRAM_CHAT_ID, 'llamar al dentista')
            ->willReturn($this->createTask(null, 'llamar al dentista', false));

        $handler = $this->createHandler('/nota should not be used', $taskService);

        self::assertSame(
            'Tarea guardada en la base de datos: llamar al dentista',
            $handler->handle(self::TELEGRAM_CHAT_ID, 'tengo que llamar al dentista')
        );
    }

    public function testItHandlesTwoTaskInstructions(): void
    {
        $createdTasks = [];
        $taskService = $this->createMock(TaskService::class);
        $taskService
            ->expects(self::exactly(2))
            ->method('create')
            ->willReturnCallback(function (string $telegramChatId, string $title) use (&$createdTasks): Task {
                $createdTasks[] = [$telegramChatId, $title];
                return $this->createTask(null, $title, false);
            });

        $handler = $this->createHandler("/tarea comprar pan\n/tarea llamar a Ana", $taskService);

        self::assertSame(
            "Tarea guardada en la base de datos: comprar pan\n\n"
                . 'Tarea guardada en la base de datos: llamar a Ana',
            $handler->handle(self::TELEGRAM_CHAT_ID, 'apunta comprar pan y llamar a Ana')
        );
        self::assertSame([
            [self::TELEGRAM_CHAT_ID, 'comprar pan'],
            [self::TELEGRAM_CHAT_ID, 'llamar a Ana'],
        ], $createdTasks);
    }

    public function testItHandlesTaskAndNoteInTheSameMessage(): void
    {
        $taskService = $this->createMock(TaskService::class);
        $taskService
            ->expects(self::once())
            ->method('create')
            ->with(self::TELEGRAM_CHAT_ID, 'comprar café')
            ->willReturn($this->createTask(null, 'comprar café', false));

        $noteService = $this->createMock(NoteService::class);
        $noteService
            ->expects(self::once())
            ->method('create')
            ->with(self::TELEGRAM_CHAT_ID, 'idea para el proyecto')
            ->willReturn($this->createNote(null, 'idea para el proyecto'));

        $handler = $this->createHandler("/tarea comprar café\n/nota idea para el proyecto", $taskService, $noteService);

        self::assertSame(
            "Tarea guardada en la base de datos: comprar café\n\n"
                . 'Nota guardada en la base de datos: idea para el proyecto',
            $handler->handle(self::TELEGRAM_CHAT_ID, 'haz una tarea de comprar café y guarda la idea para el proyecto')
        );
    }

    public function testItListsTasksAfterCreatingOne(): void
    {
        $task = $this->createTask(1, 'comprar pan', false);
        $taskService = $this->createMock(TaskService::class);
        $taskService
            ->expects(self::once())
            ->method('create')
            ->with(self::TELEGRAM_CHAT_ID, 'comprar pan')
            ->willReturn($task);
        $taskService
            ->expects(self::once())
            ->method('findAll')
            ->with(self::TELEGRAM_CHAT_ID)
            ->willReturn([$task]);

        $handler = $this->createHandler("/tarea comprar pan\n/tareas", $taskService);

        self::assertSame(
            "Tarea guardada en la base de datos: comprar pan\n\n"
                . "Tareas guardadas:\n\n"
                . "1 - comprar pan\n",
            $handler->handle(self::TELEGRAM_CHAT_ID, 'apunta comprar pan y luego dime mis tareas')
        );
    }

    public function testItRejectsInvalidCommandsInMixedOpenAiOutput(): void
    {
        $taskService = $this->createMock(TaskService::class);
        $taskService
            ->expects(self::once())
            ->method('create')
            ->with(self::TELEGRAM_CHAT_ID, 'comprar pan')
            ->willReturn($this->createTask(null, 'comprar pan', false));

        $noteService = $this->createMock(NoteService::class);
        $noteService
            ->expects(self::once())
            ->method('create')
            ->with(self::TELEGRAM_CHAT_ID, 'idea válida')
            ->willReturn($this->createNote(null, 'idea válida'));

        $handler = $this->createHandler(
            "/tarea comprar pan\n/recordatorio beber agua\n/hecha 0\n/nota idea válida",
            $taskService,
            $noteService
        );

        self::assertSame(
            "Tarea guardada en la base de datos: comprar pan\n\n"
                . 'Nota guardada en la base de datos: idea válida',
            $handler->handle(self::TELEGRAM_CHAT_ID, 'haz varias cosas')
        );
    }

    public function testItPreservesExecutionOrder(): void
    {
        $calls = [];

        $taskService = $this->createMock(TaskService::class);
        $taskService
            ->expects(self::once())
            ->method('create')
            ->willReturnCallback(function (string $telegramChatId, string $title) use (&$calls): Task {
                $calls[] = 'task:' . $title;
                return $this->createTask(null, $title, false);
            });

        $noteService = $this->createMock(NoteService::class);
        $noteService
            ->expects(self::once())
            ->method('create')
            ->willReturnCallback(function (string $telegramChatId, string $content) use (&$calls): Note {
                $calls[] = 'note:' . $content;
                return $this->createNote(null, $content);
            });
        $noteService
            ->expects(self::once())
            ->method('findAll')
            ->willReturnCallback(function (string $telegramChatId) use (&$calls): array {
                $calls[] = 'notes';
                return [$this->createNote(1, 'primera')];
            });

        $handler = $this->createHandler("/nota primera\n/tarea segunda\n/notas", $taskService, $noteService);

        self::assertSame(
            "Nota guardada en la base de datos: primera\n\n"
                . "Tarea guardada en la base de datos: segunda\n\n"
                . "Notas guardadas:\n\n"
                . "1 - primera\n",
            $handler->handle(self::TELEGRAM_CHAT_ID, 'primero una nota, luego una tarea y después lista notas')
        );
        self::assertSame(['note:primera', 'task:segunda', 'notes'], $calls);
    }

    private function createHandler(
        string $openAiOutput,
        ?TaskService $taskService = null,
        ?NoteService $noteService = null
    ): MultipleCommandHandler {
        $validator = new InternalCommandValidator();
        $openAiCommandInterpreter = new OpenAiCommandInterpreter(
            new MockHttpClient($this->createOpenAiResponse($openAiOutput)),
            'test-key',
            $validator
        );
        $multipleCommandInterpreter = new MultipleCommandInterpreter(
            new AiCommandInterpreter(),
            $openAiCommandInterpreter,
            $validator
        );

        return new MultipleCommandHandler(
            $multipleCommandInterpreter,
            new BotCommandHandler(
                $taskService ?? $this->createStub(TaskService::class),
                $noteService ?? $this->createStub(NoteService::class)
            )
        );
    }

    private function createOpenAiResponse(string $command): MockResponse
    {
        return new MockResponse(json_encode([
            'output' => [
                [
                    'content' => [
                        [
                            'text' => $command,
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function createTask(?int $id, string $title, bool $isDone): Task
    {
        $task = new Task();
        $task->setTitle($title);
        $task->setIsDone($isDone);

        if ($id !== null) {
            $property = new \ReflectionProperty($task, 'id');
            $property->setValue($task, $id);
        }

        return $task;
    }

    private function createNote(?int $id, string $content): Note
    {
        $note = new Note();
        $note->setContent($content);

        if ($id !== null) {
            $property = new \ReflectionProperty($note, 'id');
            $property->setValue($note, $id);
        }

        return $note;
    }
}
