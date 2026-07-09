<?php

namespace App\Controller;

use App\Service\MultipleCommandHandler;
use App\Service\OpenAiCommandInterpreter;
use App\Service\TelegramService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class TelegramWebhookController extends AbstractController
{
    #[Route('/telegram/webhook', name: 'telegram_webhook', methods: ['POST'])]
    public function webhook(
        Request $request,
        TelegramService $telegramService,
        MultipleCommandHandler $multipleCommandHandler,
        OpenAiCommandInterpreter $openAiCommandInterpreter
    ): JsonResponse {
        $content = $request->getContent();
        $update = json_decode($content, true);

        if (!is_array($update)) {
            return new JsonResponse(['status' => 'invalid_json']);
        }

        $message = $update['message'] ?? null;

        if (!$message) {
            return new JsonResponse(['status' => 'ignored']);
        }

        $chatId = $message['chat']['id'] ?? null;
        $text = trim($message['text'] ?? '');

        if (!$chatId) {
            return new JsonResponse(['status' => 'no_chat_id']);
        }

        if ($text === '') {
            $telegramService->sendMessage($chatId, 'De momento solo entiendo mensajes de texto.');
            return new JsonResponse(['status' => 'ok']);
        }

        if (str_starts_with($text, '/debug-ia ')) {
            $debugText = trim(substr($text, strlen('/debug-ia ')));

            if ($debugText === '') {
                $telegramService->sendMessage($chatId, 'Dime qué texto quieres probar. Ejemplo: /debug-ia acuérdate de comprar café mañana');
                return new JsonResponse(['status' => 'ok']);
            }

            try {
                $openAiResult = $openAiCommandInterpreter->interpret($debugText);

                $telegramService->sendMessage(
                    $chatId,
                    "Debug IA 🤖\n\n"
                    . "Texto original:\n"
                    . $debugText . "\n\n"
                    . "OpenAI interpreta:\n"
                    . $openAiResult
                );

                return new JsonResponse([
                    'status' => 'ok',
                    'debug_text' => $debugText,
                    'openai_result' => $openAiResult,
                ]);
            } catch (\Throwable $exception) {
                $telegramService->sendMessage(
                    $chatId,
                    "Error al consultar OpenAI ❌\n\n"
                    . $exception->getMessage()
                );

                return new JsonResponse([
                    'status' => 'openai_error',
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($text === '/debug-ia') {
            $telegramService->sendMessage($chatId, 'Dime qué texto quieres probar. Ejemplo: /debug-ia acuérdate de comprar café mañana');
            return new JsonResponse(['status' => 'ok']);
        }

        $reply = $multipleCommandHandler->handle((string) $chatId, $text);

        $telegramService->sendMessage($chatId, $reply);

        return new JsonResponse([
            'status' => 'ok',
            'original_text' => $text,
        ]);
    }
}
