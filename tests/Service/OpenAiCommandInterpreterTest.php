<?php

namespace App\Tests\Service;

use App\Service\OpenAiCommandInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OpenAiCommandInterpreterTest extends TestCase
{
    #[DataProvider('validOpenAiResponseProvider')]
    public function testItKeepsValidOpenAiCommands(string $openAiCommand): void
    {
        $interpreter = new OpenAiCommandInterpreter(
            new MockHttpClient($this->createOpenAiResponse($openAiCommand)),
            'test-key'
        );

        self::assertSame($openAiCommand, $interpreter->interpret('mensaje natural'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validOpenAiResponseProvider(): iterable
    {
        yield 'task with text' => ['/tarea comprar pan'];
        yield 'tasks list' => ['/tareas'];
        yield 'done task id' => ['/hecha 12'];
        yield 'delete task id' => ['/borrar-tarea 3'];
        yield 'note with text' => ['/nota idea para el proyecto'];
        yield 'notes list' => ['/notas'];
        yield 'delete note id' => ['/borrar-nota 7'];
        yield 'help' => ['/ayuda'];
    }

    #[DataProvider('invalidOpenAiResponseProvider')]
    public function testItReturnsHelpForInvalidOpenAiCommands(string $openAiCommand): void
    {
        $interpreter = new OpenAiCommandInterpreter(
            new MockHttpClient($this->createOpenAiResponse($openAiCommand)),
            'test-key'
        );

        self::assertSame('/ayuda', $interpreter->interpret('mensaje natural'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidOpenAiResponseProvider(): iterable
    {
        yield 'unknown slash command' => ['/recordatorio comprar pan'];
        yield 'plain text' => ['comprar pan'];
        yield 'empty task text' => ['/tarea    '];
        yield 'missing task text' => ['/tarea'];
        yield 'empty note text' => ['/nota    '];
        yield 'missing note text' => ['/nota'];
        yield 'missing done id' => ['/hecha'];
        yield 'zero done id' => ['/hecha 0'];
        yield 'negative done id' => ['/hecha -1'];
        yield 'decimal done id' => ['/hecha 1.5'];
        yield 'non-numeric done id' => ['/hecha siete'];
        yield 'extra done text' => ['/hecha 1 ahora'];
        yield 'missing delete task id' => ['/borrar-tarea'];
        yield 'invalid delete task id' => ['/borrar-tarea abc'];
        yield 'missing delete note id' => ['/borrar-nota'];
        yield 'invalid delete note id' => ['/borrar-nota 2 abc'];
        yield 'extra argument on list command' => ['/tareas hoy'];
        yield 'extra argument on notes command' => ['/notas todas'];
        yield 'extra argument on help command' => ['/ayuda por favor'];
    }

    #[DataProvider('slashCommandProvider')]
    public function testItValidatesDirectSlashCommandsWithoutCallingOpenAi(string $input, string $expectedCommand): void
    {
        $httpClient = new MockHttpClient($this->createOpenAiResponse('/tareas'));
        $interpreter = new OpenAiCommandInterpreter($httpClient, 'test-key');

        self::assertSame($expectedCommand, $interpreter->interpret($input));
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testItReturnsMultipleValidatedCommands(): void
    {
        $interpreter = new OpenAiCommandInterpreter(
            new MockHttpClient($this->createOpenAiResponse("/tarea comprar pan\n/recordatorio inválido\n/nota idea")),
            'test-key'
        );

        self::assertSame(
            ['/tarea comprar pan', '/nota idea'],
            $interpreter->interpretMany('mensaje natural con varias instrucciones')
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function slashCommandProvider(): iterable
    {
        yield 'valid direct command' => ['/nota idea guardada', '/nota idea guardada'];
        yield 'invalid direct command' => ['/recordatorio comprar pan', '/ayuda'];
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
}
