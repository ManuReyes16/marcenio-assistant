<?php

namespace App\Service;

class MultipleCommandInterpreter
{
    public function __construct(
        private AiCommandInterpreter $aiCommandInterpreter,
        private OpenAiCommandInterpreter $openAiCommandInterpreter,
        private InternalCommandValidator $internalCommandValidator
    ) {
    }

    /**
     * @return string[]
     */
    public function interpret(string $text): array
    {
        $text = trim($text);
        $interpretedText = $this->aiCommandInterpreter->interpret($text);

        if ($interpretedText !== $text || str_starts_with($text, '/')) {
            $command = $this->internalCommandValidator->validate($interpretedText);
            return $command === null ? ['/ayuda'] : [$command];
        }

        try {
            $commands = $this->openAiCommandInterpreter->interpretMany($text);
        } catch (\Throwable) {
            $commands = [];
        }

        return $commands === [] ? ['/ayuda'] : $commands;
    }
}
