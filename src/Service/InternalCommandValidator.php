<?php

namespace App\Service;

class InternalCommandValidator
{
    public function validate(string $command): ?string
    {
        $command = trim($command);

        if (in_array($command, ['/tareas', '/notas', '/ayuda'], true)) {
            return $command;
        }

        if (preg_match('/^\/(?:hecha|borrar-tarea|borrar-nota)\s+[1-9]\d*$/', $command) === 1) {
            return $command;
        }

        if (preg_match('/^\/(?:tarea|nota)\s+(.+)$/u', $command, $matches) === 1 && trim($matches[1]) !== '') {
            return $command;
        }

        return null;
    }

    public function validateOrHelp(string $command): string
    {
        return $this->validate($command) ?? '/ayuda';
    }
}
