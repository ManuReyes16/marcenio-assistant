<?php

namespace App\Tests\Service;

use App\Service\AudioTranscriptionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AudioTranscriptionServiceTest extends TestCase
{
    public function testItReturnsPlainTextTranscription(): void
    {
        $audioPath = $this->createTemporaryAudioFile('ogg');
        $capturedRequest = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedRequest): MockResponse {
            $capturedRequest = [$method, $url, $options];

            return new MockResponse(json_encode(['text' => ' comprar cafe '], JSON_THROW_ON_ERROR));
        });
        $service = new AudioTranscriptionService($httpClient, 'openai-key');

        try {
            self::assertSame('comprar cafe', $service->transcribe($audioPath));
            self::assertSame('POST', $capturedRequest[0]);
            self::assertSame('https://api.openai.com/v1/audio/transcriptions', $capturedRequest[1]);
            self::assertSame('Authorization: Bearer openai-key', $capturedRequest[2]['normalized_headers']['authorization'][0]);
            self::assertMultipartBodyContainsAudioFileAndModel(self::stringifyBody($capturedRequest[2]['body']), basename($audioPath), 'audio/ogg');
        } finally {
            if (is_file($audioPath)) {
                unlink($audioPath);
            }
        }
    }

    public function testUploadedOggKeepsOggFilenameAndAudioOggMimeType(): void
    {
        $audioPath = $this->createTemporaryAudioFile('ogg');
        $capturedRequest = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedRequest): MockResponse {
            $capturedRequest = [$method, $url, $options];

            return new MockResponse(json_encode(['text' => 'audio ok'], JSON_THROW_ON_ERROR));
        });
        $service = new AudioTranscriptionService($httpClient, 'openai-key');

        try {
            self::assertSame('audio ok', $service->transcribe($audioPath));
            $body = self::stringifyBody($capturedRequest[2]['body']);
            self::assertMultipartBodyContainsAudioFileAndModel($body, basename($audioPath), 'audio/ogg');
            self::assertStringContainsString('filename="' . basename($audioPath) . '"', $body);
            self::assertStringContainsString('.ogg"', $body);
        } finally {
            if (is_file($audioPath)) {
                unlink($audioPath);
            }
        }
    }
    public function testUnsupportedAudioExtensionIsRejectedBeforeCallingOpenAi(): void
    {
        $audioPath = $this->createTemporaryAudioFile('txt');
        $logger = new AudioRecordingLogger();
        $httpClient = new MockHttpClient(function (): MockResponse {
            self::fail('OpenAI should not be called for unsupported audio extensions.');
        });
        $service = new AudioTranscriptionService($httpClient, 'openai-key', $logger);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('No se ha podido transcribir el audio.');

            $service->transcribe($audioPath);
        } finally {
            self::assertSame('txt', $logger->records[0]['context']['file_extension']);
            self::assertNull($logger->records[0]['context']['detected_mime_type']);
            self::assertNull($logger->records[0]['context']['http_status_code']);
            if (is_file($audioPath)) {
                unlink($audioPath);
            }
        }
    }

    public function testEmptyTranscriptionIsRejected(): void
    {
        $audioPath = $this->createTemporaryAudioFile('ogg');
        $service = new AudioTranscriptionService(
            new MockHttpClient(new MockResponse(json_encode(['text' => '   '], JSON_THROW_ON_ERROR))),
            'openai-key'
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('No se ha podido transcribir el audio.');

            $service->transcribe($audioPath);
        } finally {
            if (is_file($audioPath)) {
                unlink($audioPath);
            }
        }
    }

    #[DataProvider('openAiFailureStatusProvider')]
    public function testOpenAiErrorResponsesAreRejectedAndSafelyLogged(int $statusCode): void
    {
        $audioPath = $this->createTemporaryAudioFile('ogg');
        $logger = new AudioRecordingLogger();
        $service = new AudioTranscriptionService(
            new MockHttpClient(new MockResponse(json_encode(['error' => [
                'message' => 'internal detail with key openai-key',
                'type' => 'invalid_request_error',
                'code' => 'unsupported_file',
                'param' => 'file',
            ]], JSON_THROW_ON_ERROR), ['http_code' => $statusCode])),
            'openai-key',
            $logger
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('No se ha podido transcribir el audio.');

            $service->transcribe($audioPath);
        } finally {
            $context = $logger->records[0]['context'];
            self::assertSame($statusCode, $context['http_status_code']);
            self::assertSame(\RuntimeException::class, $context['exception_class']);
            self::assertSame('No se ha podido transcribir el audio.', $context['exception_message']);
            self::assertSame('ogg', $context['file_extension']);
            self::assertSame('audio/ogg', $context['detected_mime_type']);
            self::assertSame(5, $context['file_size']);
            self::assertStringNotContainsString('openai-key', implode(' ', array_map(static fn ($value): string => (string) $value, $context)));
            self::assertSame('invalid_request_error', $context['api_error_type']);
            self::assertSame('unsupported_file', $context['api_error_code']);
            self::assertSame('internal detail with key [openai-api-key]', $context['api_error_message']);
            self::assertArrayNotHasKey('param', $context);
            self::assertArrayNotHasKey('response_body', $context);
            if (is_file($audioPath)) {
                unlink($audioPath);
            }
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function openAiFailureStatusProvider(): iterable
    {
        yield 'bad request' => [400];
        yield 'unauthorized' => [401];
        yield 'rate limited' => [429];
    }

    private function createTemporaryAudioFile(string $extension): string
    {
        $audioPath = tempnam(sys_get_temp_dir(), 'transcribe_');
        self::assertIsString($audioPath);
        $audioPathWithExtension = $audioPath . '.' . $extension;
        rename($audioPath, $audioPathWithExtension);
        file_put_contents($audioPathWithExtension, 'audio');

        return $audioPathWithExtension;
    }

    /**
     * @param iterable<string>|string $body
     */
    private static function stringifyBody(iterable|string $body): string
    {
        if (is_string($body)) {
            return $body;
        }

        $stringBody = '';
        foreach ($body as $chunk) {
            $stringBody .= $chunk;
        }

        return $stringBody;
    }

    private static function assertMultipartBodyContainsAudioFileAndModel(string $body, string $filename, string $mimeType): void
    {
        self::assertStringContainsString('name="model"', $body);
        self::assertStringContainsString('gpt-4o-mini-transcribe', $body);
        self::assertStringContainsString('name="file"', $body);
        self::assertStringContainsString('filename="' . $filename . '"', $body);
        self::assertStringContainsString('Content-Type: ' . $mimeType, $body);
    }
}

class AudioRecordingLogger implements LoggerInterface
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    public function emergency(\Stringable|string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(\Stringable|string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(\Stringable|string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(\Stringable|string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(\Stringable|string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(\Stringable|string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}