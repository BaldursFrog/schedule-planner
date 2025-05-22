<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramBotController extends Controller
{
    private string $token;
    private Client $client;
    private string $plannerServiceUrl = 'https://2dbeaf81-f8d3-4283-aaf1-a3c5697149f8.tunnel4.com//api';
    /**
     * @var array<int, array{cancelled: bool, start_time: int, job_id: string, chat_id: int}>
     */
    private array $activePolls = [];

    public function __construct()
    {
        $this->token = env('TELEGRAM_BOT_TOKEN');
        $this->validateToken();
        $this->client = new Client([
            'timeout' => 100,
            'connect_timeout' => 25,
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

    /**
     * @param array{step?: string, timestamp?: int, pending_action?: string, missing_data?: string[], current_step?: int} $state
     */
    private function handleUserInput(int $userId, int $chatId, string $text, int $messageTime): string
    {
        $state = $this->getUserState($userId);

        if (!empty($state)) {
            if (isset($state['timestamp']) && $messageTime < $state['timestamp']) {
                return "⌛ Сообщение устарело. Пожалуйста, повторите ввод.";
            }

            if (
                isset($state['pending_action']) &&
                $state['pending_action'] === 'generate_plan' &&
                isset($state['missing_data'], $state['current_step'], $state['timestamp'])
            ) {
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
                $userData = $this->getUserData($userId);
                if (!isset($userData['group'])) {
                    return "⚠️ Сначала укажите группу через /EnterGroup";
                }
                $this->setUserState($userId, [
                    'step' => 'waiting_for_goal',
                    'timestamp' => time()
                ]);
                return "🎯 Введите вашу учебную цель:";

            case '/GeneratePlan':
                return $this->initiatePlanGenerationFlow($userId, $chatId);

            case '/GetPlan':
                return $this->handleGetPlan($userId, $chatId);

            case '/ClearQueue':
                return $this->handleClearQueue($userId, $chatId);

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
            . "/GetPlan - Получить готовый план\n"
            . "/ClearQueue - Очистить очередь задач\n"
            . "/Cancel - Отменить операцию";
    }

    /**
     * @param array{step?: string, timestamp?: int, pending_action?: string, missing_data?: string[], current_step?: int} $state
     */
    private function handleUserState(int $userId, string $text, ?array $state): string
    {
        if (empty($state) || !isset($state['step'])) {
            return "❌ Неизвестная команда. Используйте /help для списка команд.";
        }

        switch ($state['step']) {
            case 'waiting_for_group':
                $this->saveUserData($userId, ['group' => $text]);
                $this->clearUserState($userId);
                return "✅ Группа сохранена! Теперь введите /EnterGoal для указания цели";

            case 'waiting_for_goal':
                $this->saveUserData($userId, ['goal' => $text]);
                $this->clearUserState($userId);
                $group = $this->getUserData($userId)['group'] ?? 'Не указана';
                return "✅ Цель сохранена!\nГруппа: {$group}\nЦель: {$text}";

            default:
                return "❌ Неизвестная команда. Используйте /help для списка команд.";
        }
    }

    private function initiatePlanGenerationFlow(int $userId, int $chatId): string
    {
        if (isset($this->activePolls[$userId])) {
            $jobId = $this->activePolls[$userId]['job_id'] ?? 'неизвестный';
            return "⏳ Генерация плана уже выполняется (Job ID: {$jobId}). Дождитесь завершения или используйте /Cancel.";
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
        if (!isset($userData['group'])) {
            $missing[] = 'group';
        }
        if (!isset($userData['goal'])) {
            $missing[] = 'goal';
        }
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
            $userData = $this->getUserData($userId);
            if (!isset($userData['group'], $userData['goal'])) {
                return "❌ Не удалось получить полные данные пользователя. Попробуйте снова.";
            }
            return $this->executePlanGeneration($userId, $chatId, $userData);
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
        if (isset($this->activePolls[$userId])) {
            $jobId = $this->activePolls[$userId]['job_id'] ?? 'неизвестный';
            Log::info("Попытка повторного запуска генерации плана для user {$userId}, job {$jobId}. Отклонено.");
            return "⏳ Генерация плана уже выполняется (Job ID: {$jobId}). Дождитесь завершения или используйте /Cancel.";
        }

        $existingJobData = $this->getJobData($userId);
        if ($existingJobData) {
            $jobId = $existingJobData['job_id'] ?? null;
            $jobStatus = $jobId ? $this->checkJobStatus($jobId) : 'unknown';

            if (in_array($jobStatus, ['pending', 'processing'])) {
                Log::info("Попытка повторного запуска генерации плана для user {$userId}, job {$jobId}. Задача в процессе.");
                return "⏳ Генерация плана уже выполняется (Job ID: {$jobId}). Дождитесь завершения или используйте /Cancel.";
            } elseif ($jobStatus === 'completed') {
                if (
                    isset($existingJobData['goal'], $existingJobData['group']) &&
                    $existingJobData['goal'] === $userData['goal'] &&
                    $existingJobData['group'] === $userData['group']
                ) {
                    Log::info("План уже сгенерирован для user {$userId}, job {$jobId} с той же целью и группой.");
                    return "✅ План уже сгенерирован ранее для этой цели и группы (Job ID: {$jobId}). Используйте /GetPlan, чтобы увидеть его.";
                }
            }
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

                $this->saveJobData($userId, [
                    'job_id' => $jobId,
                    'goal' => $userData['goal'],
                    'group' => $userData['group']
                ]);
                $this->startPolling($userId, $chatId, $jobId, $userData['goal'], $userData['group']);

                return "🚀 Генерация плана начата!\n"
                     . "▸ Группа: {$userData['group']}\n"
                     . "▸ Цель: {$userData['goal']}\n"
                     . "Я пришлю план, как только он будет готов!";
            }
        } catch (RequestException $e) {
            $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : 'Нет ответа';
            Log::error("Ошибка генерации плана для user {$userId}: " . $e->getMessage(), [
                'response' => $responseBody,
                'code' => $e->getCode()
            ]);
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
            'job_id' => $jobId,
            'chat_id' => $chatId // Сохраняем chat_id
        ];
        Log::info("Начат опрос для user {$userId}, job {$jobId}, chat {$chatId}");
        $this->pollPlanResult($userId, $jobId, $goal, $group);
    }

    private function pollPlanResult(int $userId, string $jobId): void
    {
        // Проверяем, что пользователь все еще в activePolls
        if (!isset($this->activePolls[$userId])) {
            Log::info("Опрос остановлен для user {$userId}: пользователь удален из activePolls.");
            return;
        }

        $chatId = $this->activePolls[$userId]['chat_id'] ?? null;
        if (!$chatId) {
            Log::error("chat_id не найден для user {$userId}, job {$jobId}. Остановка опроса.");
            unset($this->activePolls[$userId]);
            $this->clearJobData($userId);
            return;
        }

        if ($this->activePolls[$userId]['cancelled']) {
            Log::info("Опрос остановлен для user {$userId}: отменен.");
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
                Log::info("План успешно отправлен для user {$userId}, job {$jobId}, chat {$chatId}.");
            } elseif ($status === 'failed') {
                $errorMsg = $responseData['error_details'] ?? 'Неизвестная ошибка';
                $this->sendMessage($chatId, "❌ Ошибка генерации: {$errorMsg}. Попробуйте снова.");
                Log::info("Генерация плана не удалась для user {$userId}, job {$jobId}.");
            } else {
                $this->sendMessage($chatId, "⏳ План еще генерируется (Job ID: {$jobId}). Попробуйте снова позже с помощью /GetPlan.");
                Log::info("План еще не готов для user {$userId}, job {$jobId}.");
                return; // Не завершаем задачу, чтобы пользователь мог проверить позже
            }
        } catch (RequestException $e) {
            Log::error("Ошибка опроса для user {$userId}, job {$jobId}: " . $e->getMessage());
            Log::debug('Детали ошибки:', ['trace' => $e->getTraceAsString()]);
            $this->sendMessage($chatId, "⚠️ Ошибка проверки статуса: " . $e->getMessage() . ". Попробуйте снова.");
        }

        // Завершаем задачу после первой попытки
        unset($this->activePolls[$userId]);
        if ($status !== 'pending' && $status !== 'processing') {
            $this->clearJobData($userId);
        }
    }

    private function handleGetPlan(int $userId, int $chatId): string
    {
        $jobData = $this->getJobData($userId);
        if (!$jobData || !isset($jobData['job_id'])) {
            return "❌ Нет активных или завершенных задач. Используйте /GeneratePlan для создания плана.";
        }

        $jobId = $jobData['job_id'];
        try {
            $response = $this->client->get("{$this->plannerServiceUrl}/get-plan-result/{$jobId}", [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 30,
            ]);

            $responseData = json_decode($response->getBody(), true);
            $status = $responseData['status'] ?? 'unknown';

            if ($status === 'completed') {
                $this->sendFormattedPlan($chatId, $responseData['plan_data']);
                return "✅ План отправлен!";
            } elseif (in_array($status, ['pending', 'processing'])) {
                return "⏳ План еще генерируется (Job ID: {$jobId}). Дождитесь завершения.";
            } else {
                return "❌ Ошибка получения плана для Job ID {$jobId}. Попробуйте снова.";
            }
        } catch (RequestException $e) {
            Log::error("Ошибка при получении плана для user {$userId}, job {$jobId}: " . $e->getMessage());
            return "❌ Не удалось получить план: " . $e->getMessage() . ". Попробуйте позже.";
        }
    }

    private function checkJobStatus(string $jobId): string
    {
        try {
            $response = $this->client->get("{$this->plannerServiceUrl}/get-plan-result/{$jobId}", [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 30,
            ]);
            $responseData = json_decode($response->getBody(), true);
            return $responseData['status'] ?? 'unknown';
        } catch (RequestException $e) {
            Log::error("Ошибка при проверке статуса задачи {$jobId}: " . $e->getMessage());
            return 'failed';
        }
    }

    /**
 * @param array{plan_title: string, estimated_duration_weeks: string, weekly_overview: array<int, array{week_number: int, weekly_goal: string, daily_tasks: array<int, array{day_name: string, learning_activities: array<int, array{topic: string, description: string, suggested_slot: string, estimated_duration_minutes: int, resources?: array<int, string>}>}>, general_recommendations?: string} $planData
 */
    private function sendFormattedPlan(int $chatId, array $planData): void
    {
        // Send header
        $header = "📘 <b>" . htmlspecialchars($planData['plan_title']) . "</b>\n";
        $header .= "⏳ Продолжительность: " . htmlspecialchars($planData['estimated_duration_weeks']) . "\n";
        $this->sendMessage($chatId, $header);

        // Send each week separately
        foreach ($planData['weekly_overview'] as $week) {
            $weekMessage = "📌 <b>Неделя " . htmlspecialchars($week['week_number']) . ": " . htmlspecialchars($week['weekly_goal']) . "</b>\n";
            foreach ($week['daily_tasks'] as $day) {
                $weekMessage .= "\n<b>" . htmlspecialchars($day['day_name']) . "</b>\n";
                foreach ($day['learning_activities'] as $activity) {
                    $weekMessage .= "⏰ " . htmlspecialchars($activity['suggested_slot']) . " (" . htmlspecialchars($activity['estimated_duration_minutes']) . " мин)\n";
                    $weekMessage .= "🔹 <b>" . htmlspecialchars($activity['topic']) . "</b>\n" . htmlspecialchars($activity['description']) . "\n";
                    if (!empty($activity['resources']) && is_array($activity['resources'])) {
                        $weekMessage .= "📚 Ресурсы: " . implode(', ', array_map('htmlspecialchars', $activity['resources'])) . "\n";
                    }
                    $weekMessage .= "\n";
                }
            }
            // Check length and send
            if (mb_strlen($weekMessage, 'UTF-8') > 4096) {
                Log::warning("Сообщение для недели {$week['week_number']} слишком длинное: " . mb_strlen($weekMessage, 'UTF-8') . " символов");
                $this->sendMessage($chatId, "⚠️ Неделя {$week['week_number']} слишком длинная для отправки. Обратитесь к веб-версии плана.");
            } else {
                $this->sendMessage($chatId, $weekMessage);
            }
        }

        // Send recommendations if present
        if (!empty($planData['general_recommendations'])) {
            $recommendations = "💡 <b>Рекомендации:</b>\n" . htmlspecialchars($planData['general_recommendations']);
            if (mb_strlen($recommendations, 'UTF-8') > 4096) {
                Log::warning("Рекомендации слишком длинные: " . mb_strlen($recommendations, 'UTF-8') . " символов");
                $this->sendMessage($chatId, "⚠️ Рекомендации слишком длинные для отправки. Обратитесь к веб-версии плана.");
            } else {
                $this->sendMessage($chatId, $recommendations);
            }
        }

        Log::info("Отправка плана для chat_id {$chatId} завершена");
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
            Log::info("Генерация плана успешно отменена для user {$userId}, job {$jobId}.");
            return "✅ Генерация плана отменена";
        } catch (\Exception $e) {
            Log::error("Ошибка отправки сообщения об отмене для user {$userId}: " . $e->getMessage());
            Log::debug('Детали ошибки:', ['trace' => $e->getTraceAsString()]);
            return "⚠️ Ошибка при отмене: " . $e->getMessage() . ". Операция остановлена, попробуйте снова.";
        }
    }

    private function handleClearQueue(int $userId, int $chatId): string
    {
        $clearedCount = 0;

        if (isset($this->activePolls[$userId])) {
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
                    Log::info("Задача {$jobId} для user {$userId} отменена через /ClearQueue.");
                    $clearedCount++;
                } catch (RequestException $e) {
                    Log::error("Ошибка отмены задачи {$jobId} для user {$userId} через /ClearQueue: " . $e->getMessage());
                }
            }
        }

        $jobData = $this->getJobData($userId);
        if ($jobData && isset($jobData['job_id'])) {
            $jobId = $jobData['job_id'];
            try {
                $this->client->post("{$this->plannerServiceUrl}/cancel-plan/{$jobId}", [
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => 10,
                ]);
                Log::info("Завершенная задача {$jobId} для user {$userId} отменена через /ClearQueue.");
                $this->clearJobData($userId);
                $clearedCount++;
            } catch (RequestException $e) {
                Log::error("Ошибка отмены завершенной задачи {$jobId} для user {$userId} через /ClearQueue: " . $e->getMessage());
            }
        }

        try {
            $response = $this->client->post("{$this->plannerServiceUrl}/clear-queue", [
                'headers' => ['Accept' => 'application/json'],
                'json' => ['user_id' => $userId],
                'timeout' => 10,
            ]);
            $responseData = json_decode($response->getBody(), true);
            if ($response->getStatusCode() === 200 && $responseData['status'] === 'success') {
                Log::info("Очередь для user {$userId} очищена на сервере.");
                $clearedCount += $responseData['cleared_count'] ?? 0;
            }
        } catch (RequestException $e) {
            Log::warning("Эндпоинт /clear-queue не поддерживается или недоступен для user {$userId}: " . $e->getMessage());
        }

        if ($clearedCount > 0) {
            return "✅ Очередь задач очищена. Отменено {$clearedCount} заданий.";
        }
        return "ℹ️ Очередь задач пуста или не удалось выполнить очистку.";
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
                    'parse_mode' => 'HTML' // Switch to HTML
                ],
                'timeout' => 10,
            ]);
            Log::debug("Сообщение успешно отправлено в чат {$chatId}: " . substr($text, 0, 100) . "...");
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
        if (!Storage::exists('user_states.json')) {
            return [];
        }
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
        if (!Storage::exists('user_data.json')) {
            return [];
        }
        $data = json_decode(Storage::get('user_data.json'), true);
        return $data[$userId] ?? [];
    }

    /**
     * @param array{job_id: string, goal: string, group: string} $jobData
     */
    private function saveJobData(int $userId, array $jobData): void
    {
        $jobs = Storage::exists('user_jobs.json')
            ? json_decode(Storage::get('user_jobs.json'), true)
            : [];

        $jobs[$userId] = $jobData;
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

    /**
     * @return array{job_id: string, goal: string, group: string}|null
     */
    private function getJobData(int $userId): ?array
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