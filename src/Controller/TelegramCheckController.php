<?php

namespace App\Controller;

use App\Service\BotCommandHandler;
use App\Service\TelegramService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TelegramCheckController extends AbstractController
{
    #[Route('/telegram/check', name: 'telegram_check')]
    public function check(
        TelegramService $telegramService,
        BotCommandHandler $botCommandHandler
    ): Response {
        $updates = $telegramService->getUpdates();

        if (empty($updates['result'])) {
            return new Response('No hay mensajes nuevos.');
        }

        $lastUpdateFile = $this->getParameter('kernel.project_dir') . '/var/last_update_id.txt';

        $lastProcessedUpdateId = 0;

        if (file_exists($lastUpdateFile)) {
            $lastProcessedUpdateId = (int) file_get_contents($lastUpdateFile);
        }

        $processedMessages = 0;

        foreach ($updates['result'] as $update) {
            $updateId = $update['update_id'];

            if ($updateId <= $lastProcessedUpdateId) {
                continue;
            }

            $message = $update['message'] ?? null;

            if (!$message) {
                file_put_contents($lastUpdateFile, $updateId);
                continue;
            }

            $chatId = $message['chat']['id'];
            $text = trim($message['text'] ?? '');

            if ($text === '') {
                $reply = 'De momento solo entiendo mensajes de texto.';
            } else {
                $reply = $botCommandHandler->handle($text);
            }

            $telegramService->sendMessage($chatId, $reply);

            file_put_contents($lastUpdateFile, $updateId);

            $processedMessages++;
        }

        if ($processedMessages === 0) {
            return new Response('No hay mensajes nuevos sin procesar.');
        }

        return new Response('Mensajes nuevos procesados: ' . $processedMessages);
    }
}