<?php

namespace App\Service;

class BotCommandHandler
{
    public function __construct(
        private TaskService $taskService,
        private NoteService $noteService
    ) {
    }

    public function handle(string $text): string
    {
        if ($text === '/start' || $text === '/ayuda') {
            return "Hola, soy Marcenio Assistant 🤖\n\n"
                . "Comandos disponibles:\n\n"
                . "/ayuda - Ver comandos disponibles\n"
                . "/tarea comprar pan - Guardar una tarea\n"
                . "/tareas - Ver tareas guardadas\n"
                . "/hecha 1 - Marcar una tarea como hecha\n"
                . "/borrar-tarea 1 - Borrar una tarea\n"
                . "/nota idea para el proyecto - Guardar una nota\n"
                . "/notas - Ver notas guardadas\n"
                . "/borrar-nota 1 - Borrar una nota";
        }

        if ($text === '/tareas') {
            $tasks = $this->taskService->findAll();

            if (empty($tasks)) {
                return 'No tienes tareas guardadas todavía.';
            }

            $reply = "Tareas guardadas:\n\n";

            foreach ($tasks as $task) {
                $status = $task->isDone() ? ' ✅' : '';
                $reply .= $task->getId() . ' - ' . $task->getTitle() . $status . "\n";
            }

            return $reply;
        }

        if (str_starts_with($text, '/hecha ')) {
            $taskId = trim(substr($text, strlen('/hecha ')));

            if (!is_numeric($taskId)) {
                return 'Dime el número de la tarea. Ejemplo: /hecha 1';
            }

            $task = $this->taskService->markDone((int) $taskId);

            if (!$task) {
                return 'No he encontrado ninguna tarea con el ID ' . $taskId;
            }

            return 'Tarea marcada como hecha: ' . $task->getTitle();
        }

        if ($text === '/hecha') {
            return 'Dime qué tarea quieres marcar como hecha. Ejemplo: /hecha 1';
        }

        if (str_starts_with($text, '/borrar-tarea ')) {
            $taskId = trim(substr($text, strlen('/borrar-tarea ')));

            if (!is_numeric($taskId)) {
                return 'Dime el número de la tarea que quieres borrar. Ejemplo: /borrar-tarea 1';
            }

            $task = $this->taskService->delete((int) $taskId);

            if (!$task) {
                return 'No he encontrado ninguna tarea con el ID ' . $taskId;
            }

            return 'Tarea borrada: ' . $task->getTitle();
        }

        if ($text === '/borrar-tarea') {
            return 'Dime qué tarea quieres borrar. Ejemplo: /borrar-tarea 1';
        }

        if ($text === '/notas') {
            $notes = $this->noteService->findAll();

            if (empty($notes)) {
                return 'No tienes notas guardadas todavía.';
            }

            $reply = "Notas guardadas:\n\n";

            foreach ($notes as $note) {
                $reply .= $note->getId() . ' - ' . $note->getContent() . "\n";
            }

            return $reply;
        }

        if (str_starts_with($text, '/borrar-nota ')) {
            $noteId = trim(substr($text, strlen('/borrar-nota ')));

            if (!is_numeric($noteId)) {
                return 'Dime el número de la nota que quieres borrar. Ejemplo: /borrar-nota 1';
            }

            $note = $this->noteService->delete((int) $noteId);

            if (!$note) {
                return 'No he encontrado ninguna nota con el ID ' . $noteId;
            }

            return 'Nota borrada: ' . $note->getContent();
        }

        if ($text === '/borrar-nota') {
            return 'Dime qué nota quieres borrar. Ejemplo: /borrar-nota 1';
        }

        if (str_starts_with($text, '/tarea ')) {
            $taskTitle = trim(substr($text, strlen('/tarea ')));

            if ($taskTitle === '') {
                return 'Dime qué tarea quieres guardar. Ejemplo: /tarea comprar pan';
            }

            $this->taskService->create($taskTitle);

            return 'Tarea guardada en la base de datos: ' . $taskTitle;
        }

        if ($text === '/tarea') {
            return 'Dime qué tarea quieres guardar. Ejemplo: /tarea comprar pan';
        }

        if (str_starts_with($text, '/nota ')) {
            $noteContent = trim(substr($text, strlen('/nota ')));

            if ($noteContent === '') {
                return 'Dime qué nota quieres guardar. Ejemplo: /nota idea para el proyecto';
            }

            $this->noteService->create($noteContent);

            return 'Nota guardada en la base de datos: ' . $noteContent;
        }

        if ($text === '/nota') {
            return 'Dime qué nota quieres guardar. Ejemplo: /nota idea para el proyecto';
        }

        return "No he entendido el mensaje todavía.\n\n"
            . "Prueba con /ayuda, /tarea, /tareas, /hecha, /borrar-tarea, /nota, /notas o /borrar-nota.";
    }
}
