<?php

namespace App\Tests\Service;

use App\Service\TelegramService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class TelegramServiceTest extends TestCase
{
    public function testTelegramFileMetadataIsRequestedCorrectly(): void
    {
        $capturedRequest = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedRequest): MockResponse {
            $capturedRequest = [$method, $url, $options];

            return new MockResponse(json_encode([
                'ok' => true,
                'result' => [
                    'file_path' => 'voice/file_123.oga',
                ],
            ], JSON_THROW_ON_ERROR));
        });
        $logger = new RecordingLogger();

        $service = new TelegramService($httpClient, 'SECRET_VALUE', dirname(__DIR__, 2), $logger);

        self::assertSame('voice/file_123.oga', $service->getFilePath('voice-file-id'));
        self::assertSame('GET', $capturedRequest[0]);
        self::assertStringStartsWith('https://api.telegram.org/bot', $capturedRequest[1]);
        self::assertStringEndsWith('/getFile?file_id=voice-file-id', $capturedRequest[1]);
        self::assertSame('voice-file-id', $capturedRequest[2]['query']['file_id']);
        $this->assertTelegramRequestUsesConfiguredCaFile($capturedRequest[2]);
        self::assertArrayNotHasKey('verify_peer', $capturedRequest[2]);
        self::assertArrayNotHasKey('verify_host', $capturedRequest[2]);
        self::assertSame([
            'http_status_code' => 200,
            'result_file_path_exists' => true,
        ], $logger->records[0]['context']);
    }

    public function testTelegramFileMetadataLogsMissingFilePathWithoutResponseBody(): void
    {
        $logger = new RecordingLogger();
        $service = new TelegramService(
            new MockHttpClient(new MockResponse(json_encode(['ok' => true, 'result' => []], JSON_THROW_ON_ERROR))),
            'SECRET_VALUE',
            dirname(__DIR__, 2),
            $logger
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No se ha podido obtener el archivo de Telegram.');

        try {
            $service->getFilePath('voice-file-id');
        } finally {
            self::assertSame(200, $logger->records[0]['context']['http_status_code']);
            self::assertFalse($logger->records[0]['context']['result_file_path_exists']);
            self::assertArrayNotHasKey('response_body', $logger->records[0]['context']);
        }
    }

    public function testAudioFileIsDownloadedInsideProjectTemporaryDirectory(): void
    {
        $projectDir = dirname(__DIR__, 2);
        $capturedRequest = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedRequest): MockResponse {
            $capturedRequest = [$method, $url, $options];

            return new MockResponse("audio\x00bytes");
        });
        $logger = new RecordingLogger();
        $service = new TelegramService($httpClient, 'SECRET_VALUE', $projectDir, $logger);

        $downloadedPath = $service->downloadFile('voice/file_123.ogg');

        try {
            self::assertFileExists($downloadedPath);
            self::assertSame("audio\x00bytes", file_get_contents($downloadedPath));
            self::assertStringStartsWith($projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'telegram-audio', $downloadedPath);
            self::assertStringEndsWith('.ogg', $downloadedPath);
            self::assertDirectoryExists(dirname($downloadedPath));
            self::assertSame('GET', $capturedRequest[0]);
            self::assertStringStartsWith('https://api.telegram.org/file/bot', $capturedRequest[1]);
            self::assertStringEndsWith('/voice/file_123.ogg', $capturedRequest[1]);
            $this->assertTelegramRequestUsesConfiguredCaFile($capturedRequest[2]);
            self::assertArrayNotHasKey('verify_peer', $capturedRequest[2]);
            self::assertArrayNotHasKey('verify_host', $capturedRequest[2]);
            self::assertSame(200, $logger->records[0]['context']['http_status_code']);
            self::assertSame($downloadedPath, $logger->records[0]['context']['target_temporary_path']);
        } finally {
            if (is_file($downloadedPath)) {
                unlink($downloadedPath);
            }
        }
    }

    public function testTelegramOgaFileIsDownloadedWithLocalOggExtensionAndSameBinaryContent(): void
    {
        $projectDir = dirname(__DIR__, 2);
        $service = new TelegramService(
            new MockHttpClient(new MockResponse("audio\x00bytes")),
            'SECRET_VALUE',
            $projectDir
        );

        $downloadedPath = $service->downloadFile('voice/file_123.oga');

        try {
            self::assertFileExists($downloadedPath);
            self::assertSame("audio\x00bytes", file_get_contents($downloadedPath));
            self::assertStringStartsWith($projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'telegram-audio', $downloadedPath);
            self::assertStringEndsWith('.ogg', $downloadedPath);
            self::assertNotSame('oga', pathinfo($downloadedPath, PATHINFO_EXTENSION));
        } finally {
            if (is_file($downloadedPath)) {
                unlink($downloadedPath);
            }
        }
    }
    public function testAudioDownloadDoesNotDecodeBinaryAsJson(): void
    {
        $service = new TelegramService(
            new MockHttpClient(new MockResponse("not-json\xFF\x00audio")),
            'SECRET_VALUE',
            dirname(__DIR__, 2)
        );

        $downloadedPath = $service->downloadFile('voice/file_123.ogg');

        try {
            self::assertSame("not-json\xFF\x00audio", file_get_contents($downloadedPath));
        } finally {
            if (is_file($downloadedPath)) {
                unlink($downloadedPath);
            }
        }
    }

    /**
     * @param array<string, mixed> $requestOptions
     */
    private function assertTelegramRequestUsesConfiguredCaFile(array $requestOptions): void
    {
        $caFile = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');

        if (!is_string($caFile) || $caFile === '' || !is_readable($caFile)) {
            self::assertArrayNotHasKey('cafile', $requestOptions);
            return;
        }

        self::assertSame($caFile, $requestOptions['cafile']);
    }

    public function testAudioDownloadFailureLogsSanitizedDiagnosticsAndRemovesTemporaryFile(): void
    {
        $logger = new RecordingLogger();
        $service = new TelegramService(
            new MockHttpClient(new MockResponse('not found', ['http_code' => 404])),
            'SECRET_VALUE',
            dirname(__DIR__, 2),
            $logger
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No se ha podido descargar el audio de Telegram.');

        try {
            $service->downloadFile('voice/missing.oga');
        } finally {
            $failureContext = $logger->records[0]['context'];
            self::assertSame('error', $logger->records[0]['level']);
            self::assertSame(404, $failureContext['http_status_code']);
            self::assertArrayHasKey('target_temporary_path', $failureContext);
            if (is_string($failureContext['target_temporary_path'])) {
                self::assertFileDoesNotExist($failureContext['target_temporary_path']);
            } else {
                self::assertNull($failureContext['target_temporary_path']);
            }
            self::assertSame(\RuntimeException::class, $failureContext['exception_class']);
            self::assertStringNotContainsString('SECRET_VALUE', implode(' ', $failureContext));
            self::assertArrayNotHasKey('response_body', $failureContext);
        }
    }

    public function testTransportExceptionDiagnosticsDoNotExposeTokenBearingUrls(): void
    {
        $logger = new RecordingLogger();
        $httpClient = new MockHttpClient(function (): MockResponse {
            throw new TransportException('Could not reach https://api.telegram.org/file/botSECRET_VALUE/voice/file.oga');
        });
        $service = new TelegramService($httpClient, 'SECRET_VALUE', dirname(__DIR__, 2), $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No se ha podido descargar el audio de Telegram.');

        try {
            $service->downloadFile('voice/file.oga');
        } finally {
            $context = $logger->records[0]['context'];
            self::assertSame(TransportException::class, $context['exception_class']);
            self::assertStringNotContainsString('SECRET_VALUE', $context['exception_message']);
            self::assertStringContainsString('bot[telegram-token]', $context['exception_message']);
        }
    }
}

class RecordingLogger implements LoggerInterface
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
