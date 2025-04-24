<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class TelegramBotController extends Controller
{
    private $token;
    private $client;

    public function __construct()
    {
        $this->token = env('TELEGRAM_BOT_TOKEN');
        $this->validateToken();
        $this->client = new Client();
    }

    public function handleWebhook(Request $request)
    {
        $input = $request->all();
        Log::info('Telegram webhook request:', $input);

        if (!isset($input['message'])) {
            return response()->json(['status' => 'error', 'message' => 'Invalid request']);
        }

        $chatId = $input['message']['chat']['id'];
        $text = $input['message']['text'] ?? '';

        $response = $this->generateResponse($text);
        $this->sendMessage($chatId, $response);

        return response()->json(['status' => 'success']);
    }

    private function validateToken()
    {
        if (empty($this->token)) {
            Log::error('TELEGRAM_BOT_TOKEN is not set in .env');
            abort(500, 'Telegram bot token is not configured');
        }

        if (!preg_match('/^\d+:[\w-]+$/', $this->token)) {
            Log::error('Invalid Telegram token format');
            abort(500, 'Invalid Telegram bot token format');
        }
    }

    private function generateResponse(string $text): string
    {
        switch ($text) {
            case '/start':
            case '/help':
                return "Привет! Я бот на Laravel.\nДоступные команды:\n/start - Начать\n/schedule - Расписание\n/plan - План";
            case '/schedule':
                return "🕒 Расписание:\nПн-Пт: 9:00-18:00";
            case '/plan':
                return "📝 План на сегодня:\n1. Разработать бота\n2. Протестировать функционал";
            default:
                return "Неизвестная команда. Введите /help для списка команд";
        }
    }

    private function sendMessage(int $chatId, string $text)
    {
        try {
            $this->client->post("https://api.telegram.org/bot{$this->token}/sendMessage", [
                'form_params' => [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML'
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram message: ' . $e->getMessage());
        }
    }
}