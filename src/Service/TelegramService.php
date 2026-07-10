<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $telegramBotToken,
        private string $projectDir,
        private ?LoggerInterface $logger = null
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

    public function getFilePath(string $fileId): string
    {
        $url = sprintf(
            'https://api.telegram.org/bot%s/getFile',
            $this->telegramBotToken
        );
        $statusCode = null;
        $filePathExists = false;

        try {
            $response = $this->httpClient->request('GET', $url, $this->createTelegramRequestOptions([
                'query' => [
                    'file_id' => $fileId,
                ],
            ]));

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
            $filePath = $data['result']['file_path'] ?? null;
            $filePathExists = is_string($filePath) && trim($filePath) !== '';

            $this->logTelegramDiagnostic('info', 'Telegram getFile completed.', [
                'http_status_code' => $statusCode,
                'result_file_path_exists' => $filePathExists,
            ]);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException('Telegram getFile returned a non-2xx status code.');
            }

            if (($data['ok'] ?? false) !== true || !$filePathExists) {
                throw new \RuntimeException('Telegram getFile response did not include result.file_path.');
            }

            return $filePath;
        } catch (\Throwable $exception) {
            $this->logTelegramDiagnostic('error', 'Telegram getFile failed.', [
                'http_status_code' => $statusCode,
                'result_file_path_exists' => $filePathExists,
                'exception_class' => $exception::class,
                'exception_message' => $this->sanitizeDiagnosticMessage($exception->getMessage()),
            ]);

            throw new \RuntimeException('No se ha podido obtener el archivo de Telegram.');
        }
    }

    public function downloadFile(string $filePath): string
    {
        $url = sprintf(
            'https://api.telegram.org/file/bot%s/%s',
            $this->telegramBotToken,
            ltrim($filePath, '/')
        );
        $tmpPath = null;
        $statusCode = null;

        try {
            $tmpDir = $this->projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'telegram-audio';

            if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
                throw new \RuntimeException('No se ha podido preparar el directorio temporal.');
            }

            $response = $this->httpClient->request('GET', $url, $this->createTelegramRequestOptions());
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException('Telegram file download returned a non-2xx status code.');
            }

            if ($content === '') {
                throw new \RuntimeException('Telegram file download returned an empty body.');
            }

            $tmpPath = $this->createTemporaryAudioPath($tmpDir, $filePath);

            if (file_put_contents($tmpPath, $content, LOCK_EX) === false) {
                throw new \RuntimeException('No se ha podido guardar el audio temporal.');
            }

            $this->logTelegramDiagnostic('info', 'Telegram file download completed.', [
                'http_status_code' => $statusCode,
                'target_temporary_path' => $tmpPath,
            ]);

            return $tmpPath;
        } catch (\Throwable $exception) {
            if (is_string($tmpPath) && is_file($tmpPath)) {
                @unlink($tmpPath);
            }

            $this->logTelegramDiagnostic('error', 'Telegram file download failed.', [
                'http_status_code' => $statusCode,
                'target_temporary_path' => $tmpPath,
                'exception_class' => $exception::class,
                'exception_message' => $this->sanitizeDiagnosticMessage($exception->getMessage()),
            ]);

            throw new \RuntimeException('No se ha podido descargar el audio de Telegram.');
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function createTelegramRequestOptions(array $options = []): array
    {
        $caFile = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');

        if (is_string($caFile) && $caFile !== '' && is_readable($caFile)) {
            $options['cafile'] = $caFile;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logTelegramDiagnostic(string $level, string $message, array $context): void
    {
        $this->logger?->log($level, $message, $context);
    }

    private function sanitizeDiagnosticMessage(string $message): string
    {
        $message = str_replace($this->telegramBotToken, '[telegram-token]', $message);

        return preg_replace('/bot[^\s\/]+/u', 'bot[telegram-token]', $message) ?? '[sanitized]';
    }

    private function createTemporaryAudioPath(string $tmpDir, string $filePath): string
    {
        $extension = strtolower((string) pathinfo(parse_url($filePath, PHP_URL_PATH) ?: $filePath, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';

        if ($extension === '') {
            throw new \RuntimeException('Telegram file path did not include an audio extension.');
        }

        if ($extension === 'oga') {
            $extension = 'ogg';
        }

        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . 'voice_' . bin2hex(random_bytes(8)) . '.' . $extension;

            $handle = @fopen($tmpPath, 'x');
            if ($handle === false) {
                continue;
            }

            fclose($handle);

            return $tmpPath;
        }

        throw new \RuntimeException('No se ha podido guardar el audio temporal.');
    }
}
