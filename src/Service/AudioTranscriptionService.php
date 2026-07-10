<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AudioTranscriptionService
{
    /**
     * @var array<string, string>
     */
    private const SUPPORTED_MIME_TYPES = [
        'flac' => 'audio/flac',
        'm4a' => 'audio/mp4',
        'mp3' => 'audio/mpeg',
        'mp4' => 'audio/mp4',
        'mpeg' => 'audio/mpeg',
        'mpga' => 'audio/mpeg',
        'oga' => 'audio/ogg',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'webm' => 'audio/webm',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $openaiApiKey,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function transcribe(string $audioPath): string
    {
        $extension = $this->detectExtension($audioPath);
        $mimeType = $this->detectMimeType($extension);
        $fileSize = is_file($audioPath) ? filesize($audioPath) : false;
        $statusCode = null;
        $apiError = [];

        try {
            if ($mimeType === null) {
                throw new \InvalidArgumentException('Unsupported audio extension.');
            }

            $audioContent = file_get_contents($audioPath);

            if ($audioContent === false) {
                throw new \RuntimeException('No se ha podido leer el audio.');
            }

            $formData = new FormDataPart([
                'model' => 'gpt-4o-mini-transcribe',
                'file' => new DataPart($audioContent, basename($audioPath), $mimeType),
            ]);
            $headers = $formData->getPreparedHeaders()->toArray();
            $headers[] = 'Authorization: Bearer ' . $this->openaiApiKey;

            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/audio/transcriptions', [
                'headers' => $headers,
                'body' => $formData->bodyToString(),
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
            $apiError = $this->extractApiError($data);
            $text = trim((string) ($data['text'] ?? ''));

            if ($statusCode >= 400 || $text === '') {
                throw new \RuntimeException('No se ha podido transcribir el audio.');
            }

            return $text;
        } catch (\Throwable $exception) {
            $this->logTranscriptionFailure($statusCode, $exception, $extension, $mimeType, $fileSize === false ? null : $fileSize, $apiError);

            throw new \RuntimeException('No se ha podido transcribir el audio.');
        }
    }

    private function detectExtension(string $audioPath): string
    {
        $extension = strtolower((string) pathinfo($audioPath, PATHINFO_EXTENSION));

        return preg_replace('/[^a-z0-9]/', '', $extension) ?? '';
    }

    private function detectMimeType(string $extension): ?string
    {
        return self::SUPPORTED_MIME_TYPES[$extension] ?? null;
    }

    private function logTranscriptionFailure(
        ?int $statusCode,
        \Throwable $exception,
        string $extension,
        ?string $mimeType,
        ?int $fileSize,
        array $apiError
    ): void {
        $context = [
            'http_status_code' => $statusCode,
            'exception_class' => $exception::class,
            'exception_message' => $this->sanitizeDiagnosticMessage($exception->getMessage()),
            'file_extension' => $extension,
            'detected_mime_type' => $mimeType,
            'file_size' => $fileSize,
        ];

        if (isset($apiError['type'])) {
            $context['api_error_type'] = $apiError['type'];
        }

        if (isset($apiError['code'])) {
            $context['api_error_code'] = $apiError['code'];
        }

        if (isset($apiError['message'])) {
            $context['api_error_message'] = $apiError['message'];
        }

        $this->logger?->error('OpenAI audio transcription failed.', $context);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{type?: string, code?: string, message?: string}
     */
    private function extractApiError(array $data): array
    {
        if (!isset($data['error']) || !is_array($data['error'])) {
            return [];
        }

        $error = [];

        foreach (['type', 'code', 'message'] as $key) {
            $value = $data['error'][$key] ?? null;

            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $error[$key] = $this->sanitizeShortDiagnosticMessage($value);
        }

        return $error;
    }

    private function sanitizeDiagnosticMessage(string $message): string
    {
        $message = str_replace($this->openaiApiKey, '[openai-api-key]', $message);
        $message = preg_replace('/Bearer\s+[^\s]+/i', 'Bearer [openai-api-key]', $message) ?? '[sanitized]';

        return preg_replace('/api_key=[^\s&]+/i', 'api_key=[openai-api-key]', $message) ?? '[sanitized]';
    }
    private function sanitizeShortDiagnosticMessage(string $message): string
    {
        $message = $this->sanitizeDiagnosticMessage($message);
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? '[sanitized]';

        if (strlen($message) <= 180) {
            return $message;
        }

        return substr($message, 0, 177) . '...';
    }
}
