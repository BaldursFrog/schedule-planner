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
    private $plannerServiceUrl = 'https://4447a48d-40b9-4bae-b16d-2df874d9dcf4.tunnel4.com/api';
    private $activePolls = [];

    public function __construct()
    {
        $this->token = env('TELEGRAM_BOT_TOKEN');
        $this->validateToken();
        $this->client = new Client();
    }

    public function handleWebhook(Request $request)
    {
        $input = $request->all();
        Log::info('Telegram webhook input:', $input);

        if (!isset($input['message'])) {
            return response()->json(['status' => 'error', 'message' => 'Invalid request']);
        }

        $message = $input['message'];
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $text = $message['text'] ?? '';
        $messageTime = $message['date'] ?? time();

        if (strtolower($text) === '/cancel') {
            $response = $this->handleCancelCommand($userId, $chatId);
        } else {
            $response = $this->handleUserInput($userId, $chatId, $text, $messageTime);
        }

        $this->sendMessage($chatId, $response);
        return response()->json(['status' => 'success']);
    }

    private function handleUserInput(int $userId, int $chatId, string $text, int $messageTime): string
    {
        $state = $this->getUserState($userId);

        if (!empty($state)) {
            if (isset($state['timestamp']) && $messageTime < $state['timestamp']) {
                return "⌛ Сообщение устарело. Пожалуйста, повторите ввод.";
            }

            if (isset($state['pending_action']) && $state['pending_action'] === 'generate_plan') {
                return $this->handlePlanGenerationDataCollection($userId, $chatId, $text, $state);
            }
        }

        switch ($text) {
            case '/start':
            case '/help':
                return $this->getHelpMessage();
            
            case '/schedule':
                return "🕒 Расписание:\nПн-Пт: 9:00-18:00\nСб: 10:00-14:00";
            
            case '/plan':
                return "📋 Текущий план:\n1. Изучение PHP\n2. Практика с Laravel";
            
            case '/EnterGroup':
                $this->setUserState($userId, [
                    'step' => 'waiting_for_group',
                    'timestamp' => time()
                ]);
                return "📚 Введите номер вашей группы (например, ПИН-36):";
            
            case '/EnterGoal':
                if (!$this->getUserData($userId)['group']) {
                    return "⚠️ Сначала укажите группу через /EnterGroup";
                }
                $this->setUserState($userId, [
                    'step' => 'waiting_for_goal',
                    'timestamp' => time()
                ]);
                return "🎯 Введите вашу учебную цель:";
            
            case '/GeneratePlan':
                return $this->initiatePlanGenerationFlow($userId, $chatId);
            
            case '/Cancel':
                return $this->handleCancelCommand($userId, $chatId);
            
            default:
                return $this->handleUserState($userId, $text, $state);
        }
    }

    private function getHelpMessage(): string
    {
        return "🤖 Доступные команды:\n"
            . "/start - Начать работу\n"
            . "/help - Справка\n"
            . "/schedule - Расписание\n"
            . "/plan - Текущий план\n"
            . "/EnterGroup - Указать группу\n"
            . "/EnterGoal - Указать цель\n"
            . "/GeneratePlan - Создать план\n"
            . "/Cancel - Отменить операцию";
    }

    private function handleUserState(int $userId, string $text, ?array $state): string
    {
        if (empty($state)) return "❌ Неизвестная команда";

        switch ($state['step']) {
            case 'waiting_for_group':
                $this->saveUserData($userId, ['group' => $text]);
                $this->clearUserState($userId);
                return "✅ Группа сохранена! Теперь введите /EnterGoal для указания цели";
            
            case 'waiting_for_goal':
                $this->saveUserData($userId, ['goal' => $text]);
                $this->clearUserState($userId);
                $group = $this->getUserData($userId)['group'];
                return "✅ Цель сохранена!\nГруппа: {$group}\nЦель: {$text}";
            
            default:
                return "❌ Неизвестная команда";
        }
    }

    private function initiatePlanGenerationFlow(int $userId, int $chatId): string
    {
        if (isset($this->activePolls[$userId])) {
            return "⏳ Генерация плана уже выполняется. Дождитесь завершения.";
        }

        $userData = $this->getUserData($userId);
        $missing = $this->getMissingData($userData);

        if (!empty($missing)) {
            return $this->initiateDataCollection($userId, $missing);
        }

        return $this->executePlanGeneration($userId, $chatId, $userData);
    }

    private function getMissingData(array $userData): array
    {
        $missing = [];
        if (empty($userData['group'])) $missing[] = 'group';
        if (empty($userData['goal'])) $missing[] = 'goal';
        return $missing;
    }

    private function initiateDataCollection(int $userId, array $missing): string
    {
        $this->setUserState($userId, [
            'pending_action' => 'generate_plan',
            'missing_data' => $missing,
            'current_step' => 0,
            'timestamp' => time()
        ]);
        
        return $this->generateDataRequestMessage($missing[0]);
    }

    private function generateDataRequestMessage(string $field): string
    {
        $messages = [
            'group' => "📝 Для генерации плана укажите вашу группу:",
            'goal' => "🎯 Теперь введите вашу учебную цель:"
        ];
        return $messages[$field] ?? "ℹ️ Введите требуемую информацию:";
    }

    private function handlePlanGenerationDataCollection(
        int $userId,
        int $chatId,
        string $text,
        array $state
    ): string {
        $missing = $state['missing_data'];
        $currentStep = $state['current_step'];
        $currentField = $missing[$currentStep];

        $this->saveUserData($userId, [$currentField => $text]);
        $nextStep = $currentStep + 1;

        if ($nextStep >= count($missing)) {
            $this->clearUserState($userId);
            return $this->executePlanGeneration($userId, $chatId, $this->getUserData($userId));
        }

        $this->setUserState($userId, [
            'pending_action' => 'generate_plan',
            'missing_data' => $missing,
            'current_step' => $nextStep,
            'timestamp' => time()
        ]);

        return $this->generateDataRequestMessage($missing[$nextStep]);
    }

    private function executePlanGeneration(int $userId, int $chatId, array $userData): string
    {
        try {
            $response = $this->client->post("{$this->plannerServiceUrl}/generate-plan", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => [
                    'user_id' => $userId,
                    'goal' => $userData['goal'],
                    'group_id' => $userData['group']
                ]
            ]);

            if ($response->getStatusCode() === 202) {
                $responseData = json_decode($response->getBody(), true);
                $this->saveJobData($userId, $responseData['job_id']);
                $this->startPolling($userId, $chatId, $responseData['job_id'], 
                    $userData['goal'], $userData['group']);
                
                return "🚀 Генерация плана начата!\n"
                     . "Группа: {$userData['group']}\n"
                     . "Цель: {$userData['goal']}\n"
                     . "Я пришлю план сразу как он будет готов!";
            }
        } catch (\Exception $e) {
            Log::error("Plan generation error: " . $e->getMessage());
        }

        return "❌ Ошибка при запуске генерации плана";
    }

    private function startPolling(int $userId, int $chatId, string $jobId, string $goal, string $group)
    {
        $this->activePolls[$userId] = [
            'cancelled' => false,
            'attempt' => 1
        ];
        $this->pollPlanResult($userId, $chatId, $jobId, $goal, $group, 1);
    }

    private function pollPlanResult(int $userId, int $chatId, string $jobId, string $goal, string $group, int $attempt)
    {
        $maxAttempts = 12;
        $interval = 15;

        if (!isset($this->activePolls[$userId]) || $this->activePolls[$userId]['cancelled']) {
            unset($this->activePolls[$userId]);
            $this->clearJobData($userId);
            return;
        }

        if ($attempt > $maxAttempts) {
            $this->sendMessage($chatId, "⌛ Генерация плана занимает больше времени. Попробуйте позже.");
            unset($this->activePolls[$userId]);
            $this->clearJobData($userId);
            return;
        }

        try {
            $response = $this->client->get("{$this->plannerServiceUrl}/get-plan-result/{$jobId}", [
                'headers' => ['Accept' => 'application/json']
            ]);

            $responseData = json_decode($response->getBody(), true);
            
            if ($responseData['status'] === 'completed') {
                $this->sendFormattedPlan($chatId, $responseData['plan_data']);
            } elseif ($responseData['status'] === 'failed') {
                $errorMsg = $responseData['error_details'] ?? 'Неизвестная ошибка';
                $this->sendMessage($chatId, "❌ Ошибка генерации: {$errorMsg}");
            }

            if (in_array($responseData['status'], ['completed', 'failed'])) {
                unset($this->activePolls[$userId]);
                $this->clearJobData($userId);
                return;
            }

            if ($attempt < $maxAttempts) {
                sleep($interval);
                $this->pollPlanResult($userId, $chatId, $jobId, $goal, $group, $attempt + 1);
            }
        } catch (\Exception $e) {
            Log::error("Polling error: " . $e->getMessage());
            $this->sendMessage($chatId, "⚠️ Ошибка проверки статуса");
            unset($this->activePolls[$userId]);
            $this->clearJobData($userId);
        }
    }

    private function sendFormattedPlan(int $chatId, array $planData)
    {
        $formatted = "📘 *{$planData['plan_title']}*\n";
        $formatted .= "⏳ Продолжительность: {$planData['estimated_duration_weeks']}\n\n";

        foreach ($planData['weekly_overview'] as $week) {
            $formatted .= "📌 *Неделя {$week['week_number']}: {$week['weekly_goal']}*\n";
            
            foreach ($week['daily_tasks'] as $day) {
                $formatted .= "\n*{$day['day_name']}*\n";
                
                foreach ($day['learning_activities'] as $activity) {
                    $formatted .= "⏰ {$activity['suggested_slot']} ({$activity['estimated_duration_minutes']} мин)\n";
                    $formatted .= "🔹 *{$activity['topic']}*\n{$activity['description']}\n";
                    
                    if (!empty($activity['resources'])) {
                        $formatted .= "📚 Ресурсы: " . implode(', ', $activity['resources']) . "\n";
                    }
                    $formatted .= "\n";
                }
            }
            $formatted .= "\n";
        }

        if (!empty($planData['general_recommendations'])) {
            $formatted .= "\n💡 *Рекомендации:*\n{$planData['general_recommendations']}";
        }

        $this->sendMessage($chatId, $formatted);
    }

    private function handleCancelCommand(int $userId, int $chatId): string
    {
        if (isset($this->activePolls[$userId])) {
            $this->activePolls[$userId]['cancelled'] = true;
            unset($this->activePolls[$userId]);
            $this->clearJobData($userId);
            return "✅ Генерация плана отменена";
        }
        return "ℹ️ Нет активных операций для отмены";
    }

    // Вспомогательные методы для работы с данными
    private function validateToken()
    {
        if (empty($this->token) || !preg_match('/^\d+:[\w-]+$/', $this->token)) {
            Log::error('Invalid Telegram token');
            abort(500, 'Invalid bot configuration');
        }
    }

    private function sendMessage(int $chatId, string $text)
    {
        try {
            $this->client->post("https://api.telegram.org/bot{$this->token}/sendMessage", [
                'form_params' => [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'Markdown'
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Message send error: ' . $e->getMessage());
        }
    }

    // Методы работы с хранилищем
    private function setUserState(int $userId, array $state)
    {
        $states = Storage::exists('user_states.json') 
            ? json_decode(Storage::get('user_states.json'), true)
            : [];
        
        $states[$userId] = $state;
        Storage::put('user_states.json', json_encode($states, JSON_PRETTY_PRINT));
    }

    private function getUserState(int $userId): array
    {
        if (!Storage::exists('user_states.json')) return [];
        $states = json_decode(Storage::get('user_states.json'), true);
        return $states[$userId] ?? [];
    }

    private function clearUserState(int $userId)
    {
        $states = Storage::exists('user_states.json') 
            ? json_decode(Storage::get('user_states.json'), true)
            : [];
        
        unset($states[$userId]);
        Storage::put('user_states.json', json_encode($states, JSON_PRETTY_PRINT));
    }

    private function saveUserData(int $userId, array $data)
    {
        $existing = Storage::exists('user_data.json') 
            ? json_decode(Storage::get('user_data.json'), true)
            : [];
        
        $existing[$userId] = array_merge($existing[$userId] ?? [], $data);
        Storage::put('user_data.json', json_encode($existing, JSON_PRETTY_PRINT));
    }

    private function getUserData(int $userId): array
    {
        if (!Storage::exists('user_data.json')) return [];
        $data = json_decode(Storage::get('user_data.json'), true);
        return $data[$userId] ?? [];
    }

    private function saveJobData(int $userId, string $jobId)
    {
        $jobs = Storage::exists('user_jobs.json') 
            ? json_decode(Storage::get('user_jobs.json'), true)
            : [];
        
        $jobs[$userId] = $jobId;
        Storage::put('user_jobs.json', json_encode($jobs, JSON_PRETTY_PRINT));
    }

    private function clearJobData(int $userId)
    {
        $jobs = Storage::exists('user_jobs.json') 
            ? json_decode(Storage::get('user_jobs.json'), true)
            : [];
        
        unset($jobs[$userId]);
        Storage::put('user_jobs.json', json_encode($jobs, JSON_PRETTY_PRINT));
    }

    public function getUserDataEndpoint()
    {
        return response()->json(
            Storage::exists('user_data.json') 
                ? json_decode(Storage::get('user_data.json'), true)
                : []
        );
    }
}