<?php

namespace App\Tests\Service;

use App\Service\AiCommandInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiCommandInterpreterTest extends TestCase
{
    #[DataProvider('naturalLanguageCommandProvider')]
    public function testItInterpretsNaturalSpanishPhrases(string $input, string $expectedCommand): void
    {
        $interpreter = new AiCommandInterpreter();

        self::assertSame($expectedCommand, $interpreter->interpret($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function naturalLanguageCommandProvider(): iterable
    {
        yield 'remember task' => [
            'acuérdate de comprar café mañana',
            '/tarea comprar café mañana',
        ];

        yield 'need to task' => [
            'tengo que llamar al dentista',
            '/tarea llamar al dentista',
        ];

        yield 'save idea as note' => [
            'guárdame una idea sobre conectar WhatsApp',
            '/nota conectar WhatsApp',
        ];

        yield 'show pending tasks' => [
            'qué tareas tengo pendientes',
            '/tareas',
        ];
    }

    #[DataProvider('slashCommandProvider')]
    public function testItKeepsSlashCommandsUnchanged(string $command): void
    {
        $interpreter = new AiCommandInterpreter();

        self::assertSame($command, $interpreter->interpret($command));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function slashCommandProvider(): iterable
    {
        yield 'task command' => ['/tarea comprar pan'];
        yield 'tasks command' => ['/tareas'];
        yield 'note command' => ['/nota idea para el proyecto'];
        yield 'delete note command' => ['/borrar-nota 1'];
    }
}
