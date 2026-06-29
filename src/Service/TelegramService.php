<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $telegramBotToken
    ) {
    }

    public function sendMessage(int|string $chatId, string $text): void
    {
        $url = sprintf(
            'https://api.telegram.org/bot%s/sendMessage',
            $this->telegramBotToken
        );

        $this->httpClient->request('POST', $url, [
            'verify_peer' => false,
            'verify_host' => false,
            'json' => [
                'chat_id' => $chatId,
                'text' => $text,
            ],
        ]);
    }

    public function getUpdates(): array
    {
        $url = sprintf(
            'https://api.telegram.org/bot%s/getUpdates',
            $this->telegramBotToken
        );

        $response = $this->httpClient->request('GET', $url, [
            'verify_peer' => false,
            'verify_host' => false,
        ]);

        return $response->toArray();
    }
}