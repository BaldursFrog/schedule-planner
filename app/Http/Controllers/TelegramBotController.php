<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
            return response()->json(['status' => 'error', 'message' => 'Неверный запрос']);
        }

        $chatId = $input['message']['chat']['id'];
        $text = $input['message']['text'] ?? '';
        $userId = $input['message']['from']['id'];
        $messageTime = $input['message']['date'] ?? time();

        // Обработка ввода пользователя
        $response = $this->handleUserInput($userId, $chatId, $text, $messageTime);
        $this->sendMessage($chatId, $response);

        return response()->json(['status' => 'success']);
    }

    private function handleUserInput(int $userId, int $chatId, string $text, int $messageTime): string
    {
        // Получение текущего состояния пользователя
        $state = $this->getUserState($userId);
        Log::info("Состояние пользователя {$userId}:", $state);

        // Проверка актуальности состояния (защита от устаревших сообщений)
        if (!empty($state) && isset($state['timestamp']) && $messageTime < $state['timestamp']) {
            Log::warning("Устаревшее сообщение от пользователя {$userId}: {$text}");
            return "Пожалуйста, повторите ввод.";
        }

        // Обработка состояния
        if (!empty($state)) {
            if ($state['step'] === 'waiting_for_group') {
                // Сохранение группы
                $this->saveUserData($userId, ['group' => $text]);
                $this->clearUserState($userId);
                Log::info("Группа сохранена для пользователя {$userId}: {$text}");
                return "Ваша группа: {$text}\nВведите /EnterGoal для указания цели";
            }

            if ($state['step'] === 'waiting_for_goal') {
                // Сохранение цели
                $this->saveUserData($userId, ['goal' => $text]);
                $userData = $this->getUserData($userId);
                $this->clearUserState($userId);
                Log::info("Цель сохранена для пользователя {$userId}: {$text}");
                return "Ваша группа: {$userData['group']}\nВаша цель: {$text}";
            }
        }

        // Обработка команд
        switch ($text) {
            case '/start':
            case '/help':
                return "Привет! Я бот на Laravel.\nДоступные команды:\n/start - Начать\n/schedule - Расписание\n/plan - План\n/EnterGroup - Указать группу\n/EnterGoal - Указать цель";
            case '/schedule':
                return "🕒 Расписание:\nПн-Пт: 9:00-18:00";
            case '/plan':
                return "📝 План на сегодня:\n1. Разработать бота\n2. Протестировать функционал";
            case '/EnterGroup':
                $this->setUserState($userId, ['step' => 'waiting_for_group', 'timestamp' => $messageTime]);
                Log::info("Установлено состояние waiting_for_group для пользователя {$userId}");
                return "Пожалуйста, введите номер вашей группы (например, ПИН-36):";
            case '/EnterGoal':
                $userData = $this->getUserData($userId);
                if (!isset($userData['group'])) {
                    return "Сначала укажите группу с помощью /EnterGroup";
                }
                $this->setUserState($userId, ['step' => 'waiting_for_goal', 'timestamp' => $messageTime]);
                Log::info("Установлено состояние waiting_for_goal для пользователя {$userId}");
                return "Пожалуйста, введите вашу цель (например, Я хочу выучить C++ за две недели):";
            default:
                Log::warning("Неизвестная команда или текст от пользователя {$userId}: {$text}");
                return "Неизвестная команда. Введите /help для списка команд";
        }
    }

    private function saveUserData(int $userId, array $data)
    {
        $filePath = 'user_data.json';
        $existingData = Storage::exists($filePath)
            ? json_decode(Storage::get($filePath), true)
            : [];

        $existingData[$userId] = array_merge($existingData[$userId] ?? [], $data);

        Storage::put($filePath, json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        Log::info("Данные пользователя {$userId} сохранены:", $data);
    }

    private function getUserData(int $userId): array
    {
        $filePath = 'user_data.json';
        if (!Storage::exists($filePath)) {
            return [];
        }

        $data = json_decode(Storage::get($filePath), true);
        return $data[$userId] ?? [];
    }

    private function setUserState(int $userId, array $state)
    {
        $filePath = 'user_states.json';
        $states = Storage::exists($filePath)
            ? json_decode(Storage::get($filePath), true)
            : [];

        $states[$userId] = $state;
        Storage::put($filePath, json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        Log::info("Состояние пользователя {$userId} установлено:", $state);
    }

    private function getUserState(int $userId): array
    {
        $filePath = 'user_states.json';
        if (!Storage::exists($filePath)) {
            return [];
        }

        $states = json_decode(Storage::get($filePath), true);
        return $states[$userId] ?? [];
    }

    private function clearUserState(int $userId)
    {
        $filePath = 'user_states.json';
        $states = Storage::exists($filePath)
            ? json_decode(Storage::get($filePath), true)
            : [];

        unset($states[$userId]);
        Storage::put($filePath, json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        Log::info("Состояние пользователя {$userId} очищено");
    }

    public function getUserDataEndpoint()
    {
        $filePath = 'user_data.json';
        if (!Storage::exists($filePath)) {
            return response()->json(['error' => 'Данные пользователей не найдены'], 404);
        }
    
        $data = json_decode(Storage::get($filePath), true);
        return response()->json($data, 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function validateToken()
    {
        if (empty($this->token)) {
            Log::error('TELEGRAM_BOT_TOKEN не указан в .env');
            abort(500, 'Токен Telegram-бота не настроен');
        }

        if (!preg_match('/^\d+:[\w-]+$/', $this->token)) {
            Log::error('Неверный формат токена Telegram');
            abort(500, 'Неверный формат токена Telegram-бота');
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
            Log::error('Ошибка отправки сообщения в Telegram: ' . $e->getMessage());
        }
    }
}