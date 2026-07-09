<?php

namespace App\Service;

class MultipleCommandHandler
{
    public function __construct(
        private MultipleCommandInterpreter $multipleCommandInterpreter,
        private BotCommandHandler $botCommandHandler
    ) {
    }

    public function handle(string $telegramChatId, string $text): string
    {
        $replies = [];

        foreach ($this->multipleCommandInterpreter->interpret($text) as $command) {
            $replies[] = $this->botCommandHandler->handle($telegramChatId, $command);
        }

        return implode("\n\n", $replies);
    }
}
