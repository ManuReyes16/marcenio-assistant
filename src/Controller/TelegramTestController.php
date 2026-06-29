<?php

namespace App\Controller;

use App\Service\TelegramService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TelegramTestController extends AbstractController
{
    #[Route('/test-telegram', name: 'test_telegram')]
    public function testTelegram(TelegramService $telegramService): Response
    {
        $chatId = $this->getParameter('telegram_chat_id');

        $telegramService->sendMessage($chatId, 'Hola Manu, Symfony ya está hablando con Telegram 🚀');

        return new Response('Mensaje enviado a Telegram');
    }
}