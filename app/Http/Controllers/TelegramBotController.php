<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;

class TelegramBotController extends Controller
{
    private string $token;
    private Client $client;
    private string $plannerServiceUrl = 'https://1e046903-d28b-444d-bdff-685a9c37343a.tunnel4.com/api';
    private array $activePolls = [];

    public function __construct()
    {
        $this->token = env('TELEGRAM_BOT_TOKEN');
        $this->validateToken();
        $this->client = new Client([
            'timeout' => 100,
            'connect_timeout' => 10,
        ]);
    }

    public function handleWebhook(Request $request): JsonResponse
    {
        $input = $request->all();
        Log::info('Входящий вебхук Telegram:', $input);

        if (!isset($input['message'])) {
            return response()->json(['status' => 'error', 'message' => 'Некорректный запрос']);
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
            
            case '/plan':
                $userData = $this->getUserData($userId);
                $goal = $userData['goal'] ?? null;
                return $goal 
                    ? "📋 Цель: {$goal}" 
                    : "❌ Цель не задана. Введите /EnterGoal для указания цели.";
            
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
            . "/plan - Показать текущую цель\n"
            . "/EnterGroup - Указать группу\n"
            . "/EnterGoal - Указать цель\n"
            . "/GeneratePlan - Создать план\n"
            . "/Cancel - Отменить операцию";
    }

    /**
     * @param array{step?: string, timestamp?: int, pending_action?: string, missing_data?: string[], current_step?: int} $state
     */
    private function handleUserState(int $userId, string $text, ?array $state): string
    {
        if (empty($state)) {
            return "❌ Неизвестная команда";
        }

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
            return "⏳ Генерация плана уже выполняется. Дождитесь завершения или используйте /Cancel.";
        }

        $userData = $this->getUserData($userId);
        $missing = $this->getMissingData($userData);

        if (!empty($missing)) {
            return $this->initiateDataCollection($userId, $missing);
        }

        return $this->executePlanGeneration($userId, $chatId, $userData);
    }

    /**
     * @param array{group?: string, goal?: string} $userData
     * @return string[]
     */
    private function getMissingData(array $userData): array
    {
        $missing = [];
        if (empty($userData['group'])) $missing[] = 'group';
        if (empty($userData['goal'])) $missing[] = 'goal';
        return $missing;
    }

    /**
     * @param string[] $missing
     */
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

    /**
     * @param array{pending_action: string, missing_data: string[], current_step: int, timestamp: int} $state
     */
    private function handlePlanGenerationDataCollection(int $userId, int $chatId, string $text, array $state): string
    {
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

    /**
     * @param array{group: string, goal: string} $userData
     */
    private function executePlanGeneration(int $userId, int $chatId, array $userData): string
    {
        $existingJobId = $this->getJobData($userId);
        if ($existingJobId) {
            Log::info("Попытка повторного запуска генерации плана для user {$userId}, job {$existingJobId}. Отклонено.");
            return "⏳ Генерация плана уже выполняется (Job ID: {$existingJobId}). Дождитесь завершения или используйте /Cancel.";
        }

        try {
            Log::debug('Отправляем данные для генерации плана:', [
                'user_id' => $userId,
                'goal' => $userData['goal'],
                'group_id' => $userData['group']
            ]);

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
                $jobId = $responseData['job_id'] ?? null;

                if (!$jobId) {
                    Log::error("Не получен job_id от внешнего сервиса для user {$userId}");
                    return "❌ Ошибка: не получен идентификатор задачи. Попробуйте позже.";
                }

                $this->saveJobData($userId, $jobId);
                $this->startPolling($userId, $chatId, $jobId, $userData['goal'], $userData['group']);
                
                return "🚀 Генерация плана начата!\n"
                     . "▸ Группа: {$userData['group']}\n"
                     . "▸ Цель: {$userData['goal']}\n"
                     . "Я пришлю план, как только он будет готов!";
            }
        } catch (RequestException $e) {
            Log::error("Ошибка генерации плана для user {$userId}: " . $e->getMessage());
            Log::debug('Детали ошибки:', ['trace' => $e->getTraceAsString()]);
            $this->clearJobData($userId);
            return "❌ Не удалось запустить генерацию плана: " . $e->getMessage() . ". Попробуйте снова.";
        }

        $this->clearJobData($userId);
        return "❌ Не удалось запустить генерацию плана. Попробуйте позже.";
    }

    private function startPolling(int $userId, int $chatId, string $jobId, string $goal, string $group): void
    {
        if (isset($this->activePolls[$userId])) {
            Log::warning("Опрос уже активен для user {$userId}. Пропускаем новый опрос.");
            return;
        }

        $this->activePolls[$userId] = [
            'cancelled' => false,
            'start_time' => time(),
            'job_id' => $jobId
        ];
        Log::info("Начат опрос для user {$userId}, job {$jobId}");
        $this->pollPlanResult($userId, $chatId, $jobId, $goal, $group);
    }

    private function pollPlanResult(int $userId, int $chatId, string $jobId, string $goal, string $group): void
    {
        $maxTime = 100;
        $interval = 15;
        $startTime = $this->activePolls[$userId]['start_time'] ?? time();

        if ((time() - $startTime) >= $maxTime) {
            Log::warning("Превышено время ожидания (100 сек) для user {$userId}, job {$jobId}.");
            $this->sendMessage($chatId, "⌛ Время ожидания ответа истекло. Пожалуйста, попробуйте снова с помощью /GeneratePlan.");
            unset($this->activePolls[$userId]);
            $this->clearJobData($userId);
            return;
        }

        if (!isset($this->activePolls[$userId]) || $this->activePolls[$userId]['cancelled']) {
            Log::info("Опрос остановлен для user {$userId}: отменен или нет активного опроса.");
            unset($this->activePolls[$userId]);
            $this->clearJobData($userId);
            return;
        }

        try {
            Log::debug("Опрос статуса для user {$userId}, job {$jobId}");
            $response = $this->client->get("{$this->plannerServiceUrl}/get-plan-result/{$jobId}", [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 30,
            ]);

            $responseData = json_decode($response->getBody(), true);
            $status = $responseData['status'] ?? 'unknown';

            Log::debug("Статус опроса для user {$userId}, job {$jobId}: {$status}");

            if ($status === 'completed') {
                $this->sendFormattedPlan($chatId, $responseData['plan_data']);
                Log::info("План успешно отправлен для user {$userId}, job {$jobId}.");
                unset($this->activePolls[$userId]);
                $this->clearJobData($userId);
                return;
            } elseif ($status === 'failed') {
                $errorMsg = $responseData['error_details'] ?? 'Неизвестная ошибка';
                $this->sendMessage($chatId, "❌ Ошибка генерации: {$errorMsg}. Попробуйте снова.");
                Log::info("Генерация плана не удалась для user {$userId}, job {$jobId}.");
                unset($this->activePolls[$userId]);
                $this->clearJobData($userId);
                return;
            }

            sleep($interval);
            $this->pollPlanResult($userId, $chatId, $jobId, $goal, $group);
        } catch (RequestException $e) {
            Log::error("Ошибка опроса для user {$userId}, job {$jobId}: " . $e->getMessage());
            Log::debug('Детали ошибки:', ['trace' => $e->getTraceAsString()]);
            $this->sendMessage($chatId, "⚠️ Ошибка проверки статуса: " . $e->getMessage() . ". Попробуйте снова.");
            unset($this->activePolls[$userId]);
            $this->clearJobData($userId);
        }
    }

    /**
     * @param array{plan_title: string, estimated_duration_weeks: string, weekly_overview: array<array{week_number: int, weekly_goal: string, daily_tasks: array<array{day_name: string, learning_activities: array<array{topic: string, description: string, suggested_slot: string, estimated_duration_minutes: int, resources?: string[]}>}>, general_recommendations?: string} $planData
     */
    private function sendFormattedPlan(int $chatId, array $planData): void
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
                    
                    if (!empty($activity['resources']) && is_array($activity['resources'])) {
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
        if (!isset($this->activePolls[$userId])) {
            return "ℹ️ Нет активных операций для отмены";
        }

        $jobId = $this->activePolls[$userId]['job_id'] ?? null;
        $this->activePolls[$userId]['cancelled'] = true;
        unset($this->activePolls[$userId]);
        $this->clearJobData($userId);

        if ($jobId) {
            try {
                $this->client->post("{$this->plannerServiceUrl}/cancel-plan/{$jobId}", [
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => 10,
                ]);
                Log::info("Запрос на отмену задания {$jobId} для user {$userId} успешно отправлен.");
            } catch (RequestException $e) {
                Log::error("Ошибка при отправке запроса на отмену задания {$jobId} для user {$userId}: " . $e->getMessage());
                Log::debug('Детали ошибки:', ['trace' => $e->getTraceAsString()]);
            }
        }

        try {
            $this->sendMessage($chatId, "✅ Генерация плана отменена");
            Log::info("Генерация плана успешно отменена для user {$userId}, job {$jobId}.");
            return "✅ Генерация плана отменена";
        } catch (\Exception $e) {
            Log::error("Ошибка отправки сообщения об отмене для user {$userId}: " . $e->getMessage());
            Log::debug('Детали ошибки:', ['trace' => $e->getTraceAsString()]);
            return "⚠️ Ошибка при отмене: " . $e->getMessage() . ". Операция остановлена, попробуйте снова.";
        }
    }

    private function validateToken(): void
    {
        if (empty($this->token) || !preg_match('/^\d+:[\w-]+$/', $this->token)) {
            Log::error('Некорректный токен Telegram');
            abort(500, 'Некорректная конфигурация бота');
        }
    }

    private function sendMessage(int $chatId, string $text): void
    {
        try {
            $this->client->post("https://api.telegram.org/bot{$this->token}/sendMessage", [
                'form_params' => [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'Markdown'
                ],
                'timeout' => 10,
            ]);
            Log::debug("Сообщение успешно отправлено в чат {$chatId}: {$text}");
        } catch (\Exception $e) {
            Log::error("Ошибка отправки сообщения в чат {$chatId}: " . $e->getMessage());
            Log::debug('Детали ошибки:', ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    /**
     * @param array{step?: string, timestamp?: int, pending_action?: string, missing_data?: string[], current_step?: int} $state
     */
    private function setUserState(int $userId, array $state): void
    {
        $states = Storage::exists('user_states.json') 
            ? json_decode(Storage::get('user_states.json'), true)
            : [];
        
        $states[$userId] = $state;
        $json = json_encode($states, JSON_PRETTY_PRINT);
        if ($json === false) {
            Log::error("Ошибка кодирования JSON для user_states: " . json_last_error_msg());
            return;
        }
        Storage::put('user_states.json', $json);
    }

    /**
     * @return array{step?: string, timestamp?: int, pending_action?: string, missing_data?: string[], current_step?: int}
     */
    private function getUserState(int $userId): array
    {
        if (!Storage::exists('user_states.json')) return [];
        $states = json_decode(Storage::get('user_states.json'), true);
        return $states[$userId] ?? [];
    }

    private function clearUserState(int $userId): void
    {
        $states = Storage::exists('user_states.json') 
            ? json_decode(Storage::get('user_states.json'), true)
            : [];
        
        unset($states[$userId]);
        $json = json_encode($states, JSON_PRETTY_PRINT);
        if ($json === false) {
            Log::error("Ошибка кодирования JSON для user_states: " . json_last_error_msg());
            return;
        }
        Storage::put('user_states.json', $json);
    }

    /**
     * @param array{group?: string, goal?: string} $data
     */
    private function saveUserData(int $userId, array $data): void
    {
        $existing = Storage::exists('user_data.json') 
            ? json_decode(Storage::get('user_data.json'), true)
            : [];
        
        $existing[$userId] = array_merge($existing[$userId] ?? [], $data);
        $json = json_encode($existing, JSON_PRETTY_PRINT);
        if ($json === false) {
            Log::error("Ошибка кодирования JSON для user_data: " . json_last_error_msg());
            return;
        }
        Storage::put('user_data.json', $json);
    }

    /**
     * @return array{group?: string, goal?: string}
     */
    private function getUserData(int $userId): array
    {
        if (!Storage::exists('user_data.json')) return [];
        $data = json_decode(Storage::get('user_data.json'), true);
        return $data[$userId] ?? [];
    }

    private function saveJobData(int $userId, string $jobId): void
    {
        $jobs = Storage::exists('user_jobs.json') 
            ? json_decode(Storage::get('user_jobs.json'), true)
            : [];
        
        $jobs[$userId] = $jobId;
        $json = json_encode($jobs, JSON_PRETTY_PRINT);
        if ($json === false) {
            Log::error("Ошибка кодирования JSON для user_jobs: " . json_last_error_msg());
            return;
        }
        Storage::put('user_jobs.json', $json);
    }

    private function clearJobData(int $userId): void
    {
        $jobs = Storage::exists('user_jobs.json') 
            ? json_decode(Storage::get('user_jobs.json'), true)
            : [];
        
        unset($jobs[$userId]);
        $json = json_encode($jobs, JSON_PRETTY_PRINT);
        if ($json === false) {
            Log::error("Ошибка кодирования JSON для user_jobs: " . json_last_error_msg());
            return;
        }
        Storage::put('user_jobs.json', $json);
    }

    private function getJobData(int $userId): ?string
    {
        if (!Storage::exists('user_jobs.json')) {
            return null;
        }
        $jobs = json_decode(Storage::get('user_jobs.json'), true);
        return $jobs[$userId] ?? null;
    }

    public function getUserDataEndpoint(): JsonResponse
    {
        return response()->json(
            Storage::exists('user_data.json') 
                ? json_decode(Storage::get('user_data.json'), true)
                : []
        );
    }
}