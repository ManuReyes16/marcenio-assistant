<?php

namespace App\Tests\Controller;

use App\Controller\TelegramWebhookController;
use App\Service\AudioTranscriptionService;
use App\Service\MultipleCommandHandler;
use App\Service\OpenAiCommandInterpreter;
use App\Service\TelegramService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class TelegramWebhookControllerTest extends TestCase
{
    public function testNormalTextMessageStillWorks(): void
    {
        $telegramService = $this->createMock(TelegramService::class);
        $multipleCommandHandler = $this->createMock(MultipleCommandHandler::class);
        $audioTranscriptionService = $this->createMock(AudioTranscriptionService::class);

        $multipleCommandHandler
            ->expects(self::once())
            ->method('handle')
            ->with('123456', '/tareas')
            ->willReturn('Tareas guardadas');

        $telegramService
            ->expects(self::once())
            ->method('sendMessage')
            ->with(123456, 'Tareas guardadas');

        $audioTranscriptionService->expects(self::never())->method('transcribe');

        $response = $this->createController()->webhook(
            $this->createRequest(['message' => ['chat' => ['id' => 123456], 'text' => '/tareas']]),
            $telegramService,
            $multipleCommandHandler,
            $this->createMock(OpenAiCommandInterpreter::class),
            $audioTranscriptionService,
            new NullLogger()
        );

        self::assertSame(['status' => 'ok', 'original_text' => '/tareas'], json_decode($response->getContent(), true));
    }

    public function testVoiceMessageIsDetectedAndTranscriptionIsPassedToMultipleCommandFlow(): void
    {
        $audioPath = $this->createTemporaryAudioFile('voice_success_');
        $telegramService = $this->createTelegramServiceForVoice($audioPath);
        $multipleCommandHandler = $this->createMock(MultipleCommandHandler::class);
        $audioTranscriptionService = $this->createMock(AudioTranscriptionService::class);

        $audioTranscriptionService
            ->expects(self::once())
            ->method('transcribe')
            ->with($audioPath)
            ->willReturn('comprar cafe');
        $multipleCommandHandler
            ->expects(self::once())
            ->method('handle')
            ->with('123456', 'comprar cafe')
            ->willReturn('Tarea guardada en la base de datos: comprar cafe');
        $telegramService
            ->expects(self::once())
            ->method('sendMessage')
            ->with(
                123456,
                "Transcripci\u{00F3}n:\ncomprar cafe\n\nResultado:\nTarea guardada en la base de datos: comprar cafe"
            );

        $response = $this->handleVoice($telegramService, $multipleCommandHandler, $audioTranscriptionService);

        self::assertSame(['status' => 'ok', 'transcription' => 'comprar cafe'], json_decode($response->getContent(), true));
        self::assertFileDoesNotExist($audioPath);
    }

    public function testOneAudioWithOneInstructionReturnsCombinedResult(): void
    {
        $audioPath = $this->createTemporaryAudioFile();
        $telegramService = $this->createTelegramServiceForVoice($audioPath);
        $multipleCommandHandler = $this->createMock(MultipleCommandHandler::class);
        $audioTranscriptionService = $this->createMock(AudioTranscriptionService::class);

        $audioTranscriptionService->method('transcribe')->willReturn('acuerdate de comprar pan');
        $multipleCommandHandler
            ->expects(self::once())
            ->method('handle')
            ->with('123456', 'acuerdate de comprar pan')
            ->willReturn('Tarea guardada en la base de datos: comprar pan');
        $telegramService
            ->expects(self::once())
            ->method('sendMessage')
            ->with(self::anything(), self::stringContains('Resultado:'));

        $this->handleVoice($telegramService, $multipleCommandHandler, $audioTranscriptionService);

        self::assertFileDoesNotExist($audioPath);
    }

    public function testOneAudioWithMultipleInstructionsReturnsCombinedResult(): void
    {
        $audioPath = $this->createTemporaryAudioFile();
        $telegramService = $this->createTelegramServiceForVoice($audioPath);
        $multipleCommandHandler = $this->createMock(MultipleCommandHandler::class);
        $audioTranscriptionService = $this->createMock(AudioTranscriptionService::class);

        $transcription = 'comprar cafe y guardar una nota sobre WhatsApp';
        $combinedReply = "Tarea guardada en la base de datos: comprar cafe\n\nNota guardada en la base de datos: WhatsApp";

        $audioTranscriptionService->method('transcribe')->willReturn($transcription);
        $multipleCommandHandler
            ->expects(self::once())
            ->method('handle')
            ->with('123456', $transcription)
            ->willReturn($combinedReply);
        $telegramService
            ->expects(self::once())
            ->method('sendMessage')
            ->with(123456, "Transcripci\u{00F3}n:\n" . $transcription . "\n\nResultado:\n" . $combinedReply);

        $this->handleVoice($telegramService, $multipleCommandHandler, $audioTranscriptionService);

        self::assertFileDoesNotExist($audioPath);
    }

    public function testAudioWithNoValidInstructionReturnsExistingHelpStyleResponse(): void
    {
        $audioPath = $this->createTemporaryAudioFile();
        $telegramService = $this->createTelegramServiceForVoice($audioPath);
        $multipleCommandHandler = $this->createMock(MultipleCommandHandler::class);
        $audioTranscriptionService = $this->createMock(AudioTranscriptionService::class);

        $audioTranscriptionService->method('transcribe')->willReturn('ruido sin intencion clara');
        $multipleCommandHandler
            ->expects(self::once())
            ->method('handle')
            ->with('123456', 'ruido sin intencion clara')
            ->willReturn('Hola, soy Marcenio Assistant');
        $telegramService
            ->expects(self::once())
            ->method('sendMessage')
            ->with(123456, self::stringContains('Hola, soy Marcenio Assistant'));

        $this->handleVoice($telegramService, $multipleCommandHandler, $audioTranscriptionService);
    }

    public function testTranscriptionFailureReturnsSpanishErrorAndCleansTemporaryFile(): void
    {
        $audioPath = $this->createTemporaryAudioFile();
        $telegramService = $this->createTelegramServiceForVoice($audioPath);
        $multipleCommandHandler = $this->createMock(MultipleCommandHandler::class);
        $audioTranscriptionService = $this->createMock(AudioTranscriptionService::class);

        $audioTranscriptionService
            ->expects(self::once())
            ->method('transcribe')
            ->willThrowException(new \RuntimeException('secret internal detail'));
        $multipleCommandHandler->expects(self::never())->method('handle');
        $telegramService
            ->expects(self::once())
            ->method('sendMessage')
            ->with(123456, "No he podido transcribir el audio. Int\u{00E9}ntalo de nuevo con una nota de voz m\u{00E1}s clara.");

        $response = $this->handleVoice($telegramService, $multipleCommandHandler, $audioTranscriptionService);

        self::assertSame(['status' => 'transcription_error'], json_decode($response->getContent(), true));
        self::assertFileDoesNotExist($audioPath);
    }

    public function testOversizedAudioIsRejectedBeforeDownload(): void
    {
        $telegramService = $this->createMock(TelegramService::class);
        $multipleCommandHandler = $this->createMock(MultipleCommandHandler::class);
        $audioTranscriptionService = $this->createMock(AudioTranscriptionService::class);

        $telegramService->expects(self::never())->method('getFilePath');
        $telegramService->expects(self::never())->method('downloadFile');
        $audioTranscriptionService->expects(self::never())->method('transcribe');
        $multipleCommandHandler->expects(self::never())->method('handle');
        $telegramService
            ->expects(self::once())
            ->method('sendMessage')
            ->with(123456, "El audio es demasiado grande. Env\u{00ED}ame una nota de voz m\u{00E1}s corta.");

        $response = $this->createController()->webhook(
            $this->createRequest(['message' => [
                'chat' => ['id' => 123456],
                'voice' => ['file_id' => 'voice-file-id', 'file_size' => 20 * 1024 * 1024 + 1],
            ]]),
            $telegramService,
            $multipleCommandHandler,
            $this->createMock(OpenAiCommandInterpreter::class),
            $audioTranscriptionService,
            new NullLogger()
        );

        self::assertSame(['status' => 'audio_too_large'], json_decode($response->getContent(), true));
    }

    public function testTelegramFileDownloadFailureReturnsSpanishError(): void
    {
        $logger = new RecordingLogger();
        $telegramService = $this->createMock(TelegramService::class);
        $multipleCommandHandler = $this->createMock(MultipleCommandHandler::class);
        $audioTranscriptionService = $this->createMock(AudioTranscriptionService::class);

        $telegramService
            ->expects(self::once())
            ->method('getFilePath')
            ->willThrowException(new \RuntimeException('Could not reach https://api.telegram.org/file/botSECRET_VALUE/voice/file.oga'));
        $telegramService
            ->expects(self::once())
            ->method('sendMessage')
            ->with(123456, "No he podido descargar el audio de Telegram. Int\u{00E9}ntalo de nuevo.");
        $audioTranscriptionService->expects(self::never())->method('transcribe');
        $multipleCommandHandler->expects(self::never())->method('handle');

        $response = $this->handleVoice($telegramService, $multipleCommandHandler, $audioTranscriptionService, $logger);

        self::assertSame(['status' => 'telegram_download_error'], json_decode($response->getContent(), true));
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception_class']);
        self::assertStringNotContainsString('SECRET_VALUE', $logger->records[0]['context']['exception_message']);
        self::assertStringContainsString('bot[telegram-token]', $logger->records[0]['context']['exception_message']);
    }

    private function handleVoice(
        TelegramService $telegramService,
        MultipleCommandHandler $multipleCommandHandler,
        AudioTranscriptionService $audioTranscriptionService,
        ?LoggerInterface $logger = null
    ): JsonResponse {
        return $this->createController()->webhook(
            $this->createRequest(['message' => [
                'chat' => ['id' => 123456],
                'voice' => ['file_id' => 'voice-file-id', 'file_size' => 1024],
            ]]),
            $telegramService,
            $multipleCommandHandler,
            $this->createMock(OpenAiCommandInterpreter::class),
            $audioTranscriptionService,
            $logger ?? new NullLogger()
        );
    }

    private function createTelegramServiceForVoice(string $audioPath): TelegramService
    {
        $telegramService = $this->createMock(TelegramService::class);
        $telegramService->method('getFilePath')->with('voice-file-id')->willReturn('voice/file.oga');
        $telegramService->method('downloadFile')->with('voice/file.oga')->willReturn($audioPath);

        return $telegramService;
    }

    private function createTemporaryAudioFile(string $prefix = 'voice_'): string
    {
        $audioPath = tempnam(sys_get_temp_dir(), $prefix);
        self::assertIsString($audioPath);
        file_put_contents($audioPath, 'audio');

        return $audioPath;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createRequest(array $payload): Request
    {
        return Request::create('/telegram/webhook', 'POST', [], [], [], [], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function createController(): TelegramWebhookController
    {
        return new TelegramWebhookController();
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
