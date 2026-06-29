<?php

namespace App\Service;

class AiCommandInterpreter
{
    public function interpret(string $text): string
    {
        $originalText = trim($text);
        $normalizedText = mb_strtolower($originalText);

        if (str_starts_with($originalText, '/')) {
            return $originalText;
        }

        if (
            $normalizedText === 'tareas' ||
            $normalizedText === 'mis tareas' ||
            $normalizedText === 'ver tareas' ||
            $normalizedText === 'muéstrame las tareas' ||
            $normalizedText === 'muestrame las tareas' ||
            $normalizedText === 'enséñame las tareas' ||
            $normalizedText === 'ensename las tareas' ||
            $normalizedText === 'qué tareas tengo' ||
            $normalizedText === 'que tareas tengo' ||
            $normalizedText === 'qué tareas tengo pendientes' ||
            $normalizedText === 'que tareas tengo pendientes'
        ) {
            return '/tareas';
        }

        if (
            $normalizedText === 'notas' ||
            $normalizedText === 'mis notas' ||
            $normalizedText === 'ver notas' ||
            $normalizedText === 'muéstrame las notas' ||
            $normalizedText === 'muestrame las notas' ||
            $normalizedText === 'enséñame las notas' ||
            $normalizedText === 'ensename las notas'
        ) {
            return '/notas';
        }

        if (preg_match('/^(marca como hecha|marcar como hecha|hecha|termina|terminar|completa|completar) (la )?tarea (\d+)$/iu', $originalText, $matches)) {
            return '/hecha ' . $matches[3];
        }

        if (preg_match('/^(borra|borrar|elimina|eliminar) (la )?tarea (\d+)$/iu', $originalText, $matches)) {
            return '/borrar-tarea ' . $matches[3];
        }

        if (preg_match('/^(borra|borrar|elimina|eliminar) (la )?nota (\d+)$/iu', $originalText, $matches)) {
            return '/borrar-nota ' . $matches[3];
        }

        if (
            str_starts_with($normalizedText, 'apúntame ') ||
            str_starts_with($normalizedText, 'apuntame ') ||
            str_starts_with($normalizedText, 'acuérdate de ') ||
            str_starts_with($normalizedText, 'acuerdate de ') ||
            str_starts_with($normalizedText, 'recuérdame ') ||
            str_starts_with($normalizedText, 'recuerdame ') ||
            str_starts_with($normalizedText, 'tengo que ') ||
            str_starts_with($normalizedText, 'añade tarea ') ||
            str_starts_with($normalizedText, 'anade tarea ') ||
            str_starts_with($normalizedText, 'crea una tarea ') ||
            str_starts_with($normalizedText, 'crear tarea ') ||
            str_starts_with($normalizedText, 'nueva tarea ') ||
            str_starts_with($normalizedText, 'tarea ')
        ) {
            $task = preg_replace(
                '/^apúntame |^apuntame |^acuérdate de |^acuerdate de |^recuérdame |^recuerdame |^tengo que |^añade tarea |^anade tarea |^crea una tarea |^crear tarea |^nueva tarea |^tarea /iu',
                '',
                $originalText
            );

            $task = trim($task);

            if ($task !== '') {
                return '/tarea ' . $task;
            }
        }

        if (
            str_starts_with($normalizedText, 'guarda una nota sobre ') ||
            str_starts_with($normalizedText, 'guardar nota sobre ') ||
            str_starts_with($normalizedText, 'guarda nota ') ||
            str_starts_with($normalizedText, 'nueva nota ') ||
            str_starts_with($normalizedText, 'anota ') ||
            str_starts_with($normalizedText, 'nota ') ||
            str_starts_with($normalizedText, 'guárdame una idea ') ||
            str_starts_with($normalizedText, 'guardame una idea ') ||
            str_starts_with($normalizedText, 'guárdame una idea sobre ') ||
            str_starts_with($normalizedText, 'guardame una idea sobre ')
        ) {
            $note = preg_replace(
                '/^guarda una nota sobre |^guardar nota sobre |^guarda nota |^nueva nota |^anota |^nota |^guárdame una idea sobre |^guardame una idea sobre |^guárdame una idea |^guardame una idea /iu',
                '',
                $originalText
            );

            $note = trim($note);

            if ($note !== '') {
                return '/nota ' . $note;
            }
        }

        return $originalText;
    }
}