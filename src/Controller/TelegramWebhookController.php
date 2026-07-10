<?php

namespace App\Controller;

use App\Service\AudioTranscriptionService;
use App\Service\MultipleCommandHandler;
use App\Service\OpenAiCommandInterpreter;
use App\Service\TelegramService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class TelegramWebhookController extends AbstractController
{
    private const MAX_AUDIO_BYTES = 20 * 1024 * 1024;

    #[Route('/telegram/webhook', name: 'telegram_webhook', methods: ['POST'])]
    public function webhook(
        Request $request,
        TelegramService $telegramService,
        MultipleCommandHandler $multipleCommandHandler,
        OpenAiCommandInterpreter $openAiCommandInterpreter,
        AudioTranscriptionService $audioTranscriptionService,
        LoggerInterface $logger
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
        $voice = $message['voice'] ?? null;

        if (!$chatId) {
            return new JsonResponse(['status' => 'no_chat_id']);
        }

        if ($text === '') {
            if (is_array($voice)) {
                return $this->handleVoiceMessage(
                    $chatId,
                    $voice,
                    $telegramService,
                    $audioTranscriptionService,
                    $multipleCommandHandler,
                    $logger
                );
            }

            $telegramService->sendMessage($chatId, 'De momento solo entiendo mensajes de texto.');
            return new JsonResponse(['status' => 'ok']);
        }

        if (str_starts_with($text, '/debug-ia ')) {
            $debugText = trim(substr($text, strlen('/debug-ia ')));

            if ($debugText === '') {
                $telegramService->sendMessage($chatId, "Dime qu\u{00E9} texto quieres probar. Ejemplo: /debug-ia acu\u{00E9}rdate de comprar caf\u{00E9} ma\u{00F1}ana");
                return new JsonResponse(['status' => 'ok']);
            }

            try {
                $openAiResult = $openAiCommandInterpreter->interpret($debugText);

                $telegramService->sendMessage(
                    $chatId,
                    "Debug IA \u{1F916}\n\n"
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
                    "Error al consultar OpenAI \u{274C}\n\n"
                    . $exception->getMessage()
                );

                return new JsonResponse([
                    'status' => 'openai_error',
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($text === '/debug-ia') {
            $telegramService->sendMessage($chatId, "Dime qu\u{00E9} texto quieres probar. Ejemplo: /debug-ia acu\u{00E9}rdate de comprar caf\u{00E9} ma\u{00F1}ana");
            return new JsonResponse(['status' => 'ok']);
        }

        $reply = $multipleCommandHandler->handle((string) $chatId, $text);

        $telegramService->sendMessage($chatId, $reply);

        return new JsonResponse([
            'status' => 'ok',
            'original_text' => $text,
        ]);
    }

    /**
     * @param array<string, mixed> $voice
     */
    private function handleVoiceMessage(
        int|string $chatId,
        array $voice,
        TelegramService $telegramService,
        AudioTranscriptionService $audioTranscriptionService,
        MultipleCommandHandler $multipleCommandHandler,
        LoggerInterface $logger
    ): JsonResponse {
        $fileId = $voice['file_id'] ?? null;
        $fileSize = $voice['file_size'] ?? null;

        if (!is_string($fileId) || trim($fileId) === '') {
            $telegramService->sendMessage($chatId, 'No he podido leer el audio de Telegram.');
            return new JsonResponse(['status' => 'telegram_file_error']);
        }

        if (is_numeric($fileSize) && (int) $fileSize > self::MAX_AUDIO_BYTES) {
            $telegramService->sendMessage($chatId, "El audio es demasiado grande. Env\u{00ED}ame una nota de voz m\u{00E1}s corta.");
            return new JsonResponse(['status' => 'audio_too_large']);
        }

        $audioPath = null;

        try {
            $filePath = $telegramService->getFilePath($fileId);
            $audioPath = $telegramService->downloadFile($filePath);

            if (filesize($audioPath) > self::MAX_AUDIO_BYTES) {
                @unlink($audioPath);
                $telegramService->sendMessage($chatId, "El audio es demasiado grande. Env\u{00ED}ame una nota de voz m\u{00E1}s corta.");
                return new JsonResponse(['status' => 'audio_too_large']);
            }
        } catch (\Throwable $exception) {
            $logger->error('Telegram voice download failed in webhook.', [
                'exception_class' => $exception::class,
                'exception_message' => $this->sanitizeDiagnosticMessage($exception->getMessage()),
                'http_status_code' => null,
                'result_file_path_exists' => null,
                'target_temporary_path' => $audioPath,
            ]);
            $telegramService->sendMessage($chatId, "No he podido descargar el audio de Telegram. Int\u{00E9}ntalo de nuevo.");
            return new JsonResponse(['status' => 'telegram_download_error']);
        }

        try {
            $transcription = $audioTranscriptionService->transcribe($audioPath);
            $reply = $multipleCommandHandler->handle((string) $chatId, $transcription);

            $telegramService->sendMessage(
                $chatId,
                "Transcripci\u{00F3}n:\n"
                . $transcription . "\n\n"
                . "Resultado:\n"
                . $reply
            );

            return new JsonResponse([
                'status' => 'ok',
                'transcription' => $transcription,
            ]);
        } catch (\Throwable) {
            $telegramService->sendMessage($chatId, "No he podido transcribir el audio. Int\u{00E9}ntalo de nuevo con una nota de voz m\u{00E1}s clara.");
            return new JsonResponse(['status' => 'transcription_error']);
        } finally {
            if (is_string($audioPath) && is_file($audioPath)) {
                @unlink($audioPath);
            }
        }
    }

    private function sanitizeDiagnosticMessage(string $message): string
    {
        return preg_replace('/bot[^\s\/]+/u', 'bot[telegram-token]', $message) ?? '[sanitized]';
    }
}
