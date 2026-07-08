<?php

namespace App\Tests\Service;

use App\Entity\Note;
use App\Entity\Task;
use App\Service\BotCommandHandler;
use App\Service\NoteService;
use App\Service\TaskService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BotCommandHandlerTest extends TestCase
{
    public function testItShowsHelp(): void
    {
        $handler = $this->createHandler();

        self::assertSame(
            "Hola, soy Marcenio Assistant 🤖\n\n"
                . "Comandos disponibles:\n\n"
                . "/ayuda - Ver comandos disponibles\n"
                . "/tarea comprar pan - Guardar una tarea\n"
                . "/tareas - Ver tareas guardadas\n"
                . "/hecha 1 - Marcar una tarea como hecha\n"
                . "/borrar-tarea 1 - Borrar una tarea\n"
                . "/nota idea para el proyecto - Guardar una nota\n"
                . "/notas - Ver notas guardadas\n"
                . "/borrar-nota 1 - Borrar una nota",
            $handler->handle('/ayuda')
        );
    }

    public function testItListsTasks(): void
    {
        $taskService = $this->createMock(TaskService::class);
        $taskService
            ->expects(self::once())
            ->method('findAll')
            ->willReturn([
                $this->createTask(1, 'comprar pan', false),
                $this->createTask(2, 'llamar al dentista', true),
            ]);

        $handler = $this->createHandler($taskService);

        self::assertSame(
            "Tareas guardadas:\n\n"
                . "1 - comprar pan\n"
                . "2 - llamar al dentista ✅\n",
            $handler->handle('/tareas')
        );
    }

    public function testItShowsEmptyTaskListMessage(): void
    {
        $taskService = $this->createMock(TaskService::class);
        $taskService
            ->expects(self::once())
            ->method('findAll')
            ->willReturn([]);

        $handler = $this->createHandler($taskService);

        self::assertSame('No tienes tareas guardadas todavía.', $handler->handle('/tareas'));
    }

    #[DataProvider('taskDoneProvider')]
    public function testItHandlesTaskDoneCommands(string $command, ?Task $task, string $expectedReply): void
    {
        $taskService = $this->createMock(TaskService::class);
        $taskService
            ->expects(self::once())
            ->method('markDone')
            ->with(7)
            ->willReturn($task);

        $handler = $this->createHandler($taskService);

        self::assertSame($expectedReply, $handler->handle($command));
    }

    /**
     * @return iterable<string, array{string, ?Task, string}>
     */
    public static function taskDoneProvider(): iterable
    {
        yield 'existing task' => [
            '/hecha 7',
            self::createTask(7, 'comprar leche', false),
            'Tarea marcada como hecha: comprar leche',
        ];

        yield 'missing task' => [
            '/hecha 7',
            null,
            'No he encontrado ninguna tarea con el ID 7',
        ];
    }

    #[DataProvider('commandWithoutServiceCallProvider')]
    public function testItHandlesCommandsThatDoNotNeedServices(string $command, string $expectedReply): void
    {
        $taskService = $this->createMock(TaskService::class);
        $taskService->expects(self::never())->method(self::anything());

        $noteService = $this->createMock(NoteService::class);
        $noteService->expects(self::never())->method(self::anything());

        $handler = $this->createHandler($taskService, $noteService);

        self::assertSame($expectedReply, $handler->handle($command));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commandWithoutServiceCallProvider(): iterable
    {
        yield 'invalid done id' => ['/hecha siete', 'Dime el número de la tarea. Ejemplo: /hecha 1'];
        yield 'missing done id' => ['/hecha', 'Dime qué tarea quieres marcar como hecha. Ejemplo: /hecha 1'];
        yield 'invalid delete task id' => ['/borrar-tarea siete', 'Dime el número de la tarea que quieres borrar. Ejemplo: /borrar-tarea 1'];
        yield 'missing delete task id' => ['/borrar-tarea', 'Dime qué tarea quieres borrar. Ejemplo: /borrar-tarea 1'];
        yield 'invalid delete note id' => ['/borrar-nota siete', 'Dime el número de la nota que quieres borrar. Ejemplo: /borrar-nota 1'];
        yield 'missing delete note id' => ['/borrar-nota', 'Dime qué nota quieres borrar. Ejemplo: /borrar-nota 1'];
        yield 'empty task title' => ['/tarea    ', 'Dime qué tarea quieres guardar. Ejemplo: /tarea comprar pan'];
        yield 'missing task title' => ['/tarea', 'Dime qué tarea quieres guardar. Ejemplo: /tarea comprar pan'];
        yield 'empty note content' => ['/nota    ', 'Dime qué nota quieres guardar. Ejemplo: /nota idea para el proyecto'];
        yield 'missing note content' => ['/nota', 'Dime qué nota quieres guardar. Ejemplo: /nota idea para el proyecto'];
        yield 'unknown command' => [
            'hola',
            "No he entendido el mensaje todavía.\n\n"
                . "Prueba con /ayuda, /tarea, /tareas, /hecha, /borrar-tarea, /nota, /notas o /borrar-nota.",
        ];
    }

    public function testItDeletesATask(): void
    {
        $taskService = $this->createMock(TaskService::class);
        $taskService
            ->expects(self::once())
            ->method('delete')
            ->with(3)
            ->willReturn($this->createTask(3, 'comprar café', false));

        $handler = $this->createHandler($taskService);

        self::assertSame('Tarea borrada: comprar café', $handler->handle('/borrar-tarea 3'));
    }

    public function testItCreatesATask(): void
    {
        $taskService = $this->createMock(TaskService::class);
        $taskService
            ->expects(self::once())
            ->method('create')
            ->with('comprar pan')
            ->willReturn($this->createTask(null, 'comprar pan', false));

        $handler = $this->createHandler($taskService);

        self::assertSame('Tarea guardada en la base de datos: comprar pan', $handler->handle('/tarea comprar pan'));
    }

    public function testItListsNotes(): void
    {
        $noteService = $this->createMock(NoteService::class);
        $noteService
            ->expects(self::once())
            ->method('findAll')
            ->willReturn([
                $this->createNote(1, 'idea para el proyecto'),
                $this->createNote(2, 'llamar a Ana'),
            ]);

        $handler = $this->createHandler(noteService: $noteService);

        self::assertSame(
            "Notas guardadas:\n\n"
                . "1 - idea para el proyecto\n"
                . "2 - llamar a Ana\n",
            $handler->handle('/notas')
        );
    }

    public function testItShowsEmptyNoteListMessage(): void
    {
        $noteService = $this->createMock(NoteService::class);
        $noteService
            ->expects(self::once())
            ->method('findAll')
            ->willReturn([]);

        $handler = $this->createHandler(noteService: $noteService);

        self::assertSame('No tienes notas guardadas todavía.', $handler->handle('/notas'));
    }

    public function testItDeletesANote(): void
    {
        $noteService = $this->createMock(NoteService::class);
        $noteService
            ->expects(self::once())
            ->method('delete')
            ->with(4)
            ->willReturn($this->createNote(4, 'idea para el proyecto'));

        $handler = $this->createHandler(noteService: $noteService);

        self::assertSame('Nota borrada: idea para el proyecto', $handler->handle('/borrar-nota 4'));
    }

    public function testItCreatesANote(): void
    {
        $noteService = $this->createMock(NoteService::class);
        $noteService
            ->expects(self::once())
            ->method('create')
            ->with('idea para el proyecto')
            ->willReturn($this->createNote(null, 'idea para el proyecto'));

        $handler = $this->createHandler(noteService: $noteService);

        self::assertSame('Nota guardada en la base de datos: idea para el proyecto', $handler->handle('/nota idea para el proyecto'));
    }

    private function createHandler(?TaskService $taskService = null, ?NoteService $noteService = null): BotCommandHandler
    {
        return new BotCommandHandler(
            $taskService ?? $this->createStub(TaskService::class),
            $noteService ?? $this->createStub(NoteService::class)
        );
    }

    private static function createTask(?int $id, string $title, bool $isDone): Task
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

    private static function createNote(?int $id, string $content): Note
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
