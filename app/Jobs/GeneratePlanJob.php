<?php

namespace App\Jobs;

use App\Models\PlanGenerationJob as PlanJobStatus;
use Edvardpotter\GigaChat\GigaChatOAuth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeneratePlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $userId;

    public string $goal;

    public string $groupId;

    public string $jobId;

    public int $tries = 2;

    public int $timeout = 180;

    public int $backoff = 30;

    public function __construct(int $userId, string $goal, string $groupId, string $jobId)
    {
        $this->userId = $userId;
        $this->goal = $goal;
        $this->groupId = $groupId;
        $this->jobId = $jobId;
    }

    public function handle(): void
    {
        Log::info(
            "[GeneratePlanJob] Starting for Job ID: {$this->jobId}, User ID: {$this->userId}, Goal: {$this->goal}, Group ID: {$this->groupId}"
        );

        PlanJobStatus::where('id', $this->jobId)->update(['status' => 'processing']);

        // --- ШАГ 1: Получение Информации о Неделе и Свободном Времени ---
        $mietServiceBaseUrl = config('services.miet_schedule.base_url'); 
        $currentWeekType = '1 числитель'; 
        $rawFreeTimeData = null;
        $scheduleInfoForPrompt = 'К сожалению, не удалось получить детальную информацию о твоем расписании. План будет общим, старайся выделять время для учебы, когда это возможно.';

        if (! $mietServiceBaseUrl) {
            Log::error("[GeneratePlanJob][JobID:{$this->jobId}] MIET_SCHEDULE_SERVICE_URL not configured. Using default schedule info.");
        } else {
            try {
                // 1.1 Получаем текущий тип недели
                $targetUrlCurrentWeek = "{$mietServiceBaseUrl}/current-week"; 
                Log::info("[GeneratePlanJob][JobID:{$this->jobId}] Requesting current week type from: {$targetUrlCurrentWeek}");
                $weekInfoResponse = Http::timeout(240)->withOptions(['verify' => false])->get($targetUrlCurrentWeek);

                if ($weekInfoResponse->successful()) {
                    $fetchedWeekType = $weekInfoResponse->json('type_name');
                    if (! empty($fetchedWeekType)) {
                        $currentWeekType = $fetchedWeekType;
                        Log::info("[GeneratePlanJob][JobID:{$this->jobId}] Current week type: '{$currentWeekType}'");
                    } else {
                        Log::warning("[GeneratePlanJob][JobID:{$this->jobId}] MIET Service returned empty week type. Using default: '{$currentWeekType}'");
                    }
                } else {
                    Log::error("[GeneratePlanJob][JobID:{$this->jobId}] Failed to get current week type. URL: {$targetUrlCurrentWeek}, Status: {$weekInfoResponse->status()}, Body: ".substr($weekInfoResponse->body(), 0, 200).". Using default: '{$currentWeekType}'");
                }

                // 1.2 Получаем свободное время
                $targetUrlFreeTime = "{$mietServiceBaseUrl}/free-time/{$this->groupId}"; 
                Log::info("[GeneratePlanJob][JobID:{$this->jobId}] Requesting free time from: {$targetUrlFreeTime}");
                $freeTimeResponse = Http::timeout(240)->withOptions(['verify' => false])->get($targetUrlFreeTime);

                if ($freeTimeResponse->successful()) {
                    $rawFreeTimeData = $freeTimeResponse->json();
                    Log::info("[GeneratePlanJob][JobID:{$this->jobId}] Received free time data from MIET Service.");
                } else {
                    Log::error("[GeneratePlanJob][JobID:{$this->jobId}] Failed to get free time. URL: {$targetUrlFreeTime}, Status: {$freeTimeResponse->status()}, Body: ".substr($freeTimeResponse->body(), 0, 200));
                }

            } catch (Throwable $e) {
                Log::error("[GeneratePlanJob][JobID:{$this->jobId}] Exception while communicating with MIET Service. Error: {$e->getMessage()}");
            }
        }

        // 1.3 Фильтрация и 1.4 Формирование scheduleInfoForPrompt 
        $relevantFreeSlots = [];
        if (is_array($rawFreeTimeData)) {
            foreach ($rawFreeTimeData as $dayName => $daySchedule) {
                if (isset($daySchedule[$currentWeekType]) && is_array($daySchedule[$currentWeekType]) && ! empty($daySchedule[$currentWeekType])) {
                    $relevantFreeSlots[$dayName] = $daySchedule[$currentWeekType];
                }
            }
        }
        if (empty($relevantFreeSlots)) {
            if ($mietServiceBaseUrl && $rawFreeTimeData !== null) {
                $scheduleInfoForPrompt = "На текущую неделю (тип: {$currentWeekType}) у меня нет точной информации о свободном времени. Пожалуйста, предложи гибкий план.";
            }
        } else {
            $freeTimeText = "Мое доступное свободное время для учебы на этой неделе (тип недели: {$currentWeekType}):\n";
            foreach ($relevantFreeSlots as $day => $slots) {
                $freeTimeText .= "- {$day}:\n";
                foreach ($slots as $slot) {
                    $freeTimeText .= '  - с '.($slot['from'] ?? '??').' до '.($slot['to'] ?? '??')."\n";
                }
            }
            $scheduleInfoForPrompt = trim($freeTimeText);
            Log::info("[GeneratePlanJob][JobID:{$this->jobId}] Processed free time slots for current week type.");
        }


        // --- ШАГ 2: Формирование Промпта для GigaChat ---
        $jsonStructureExample = <<<'JSON'
{
  "plan_title": "Учебный план: [Название цели]",
  "estimated_duration_weeks": "[Примерное количество недель, например, '2 недели', '1 месяц']",
  "weekly_overview": [
    {
      "week_number": 1,
      "weekly_goal": "Цель на неделю 1: [Краткое описание]",
      "daily_tasks": [
        {
          "day_name": "Понедельник",
          "learning_activities": [
            {
              "suggested_slot": "18:00-20:00",
              "topic": "Тема/Модуль: [Название темы]",
              "description": "Активность: [Описание задачи, что конкретно делать]",
              "estimated_duration_minutes": 90,
              "resources": ["[Ресурс 1 (статья/видео/глава)]", "[Ресурс 2]"]
            }
          ]
        }
      ]
    }
  ],
  "general_recommendations": "[Общие советы или рекомендации по изучению]"
}
JSON;
        $prompt = "Составь, пожалуйста, пошаговый учебный план для достижения цели: '{$this->goal}'.\n\n".
                  "Информация о моем свободном времени для учебы:\n{$scheduleInfoForPrompt}\n\n".
                  'План должен быть реалистичным и максимально использовать указанные свободные промежутки времени. '.
                  'Разбей план по неделям и дням. Для каждого дня укажи конкретные учебные задачи, темы, примерное время на их выполнение (в минутах) в рамках доступных свободных слотов, и предложи ресурсы для изучения (книги, сайты, видео), если это возможно. '.
                  'ВАЖНО: Твой ответ должен быть СТРОГО JSON объектом, без какого-либо текста до или после него. '.
                  "Используй следующую структуру JSON:\n".
                  "```json\n".$jsonStructureExample."\n```\n".
                  'Заполни все поля актуальными данными для запрашиваемого учебного плана. '.
                  "В поле 'suggested_slot' указывай один из свободных промежутков времени из предоставленной информации о расписании.";
        Log::info("[GeneratePlanJob][JobID:{$this->jobId}] Generated GigaChat prompt.");

        // --- ШАГ 3: Вызов GigaChat API ---
        $clientId = config('services.gigachat.client_id');
        $clientSecret = config('services.gigachat.client_secret');
        if (! $clientId || ! $clientSecret) { /* ... обработка ... */ $this->failLog('Missing GigaChat credentials');

            return;
        }
        $certPath = base_path('russiantrustedca.pem');
        $certOptions = file_exists($certPath) ? ['verify' => $certPath] : ['verify' => false];
        if ($certOptions['verify'] === false) {
            Log::warning("[GeneratePlanJob][JobID:{$this->jobId}] Certificate file not found or verification disabled.");
        }

        try {
            Log::info("[GeneratePlanJob][JobID:{$this->jobId}] Attempting to get GigaChat access token.");
            $oauthClient = new GigaChatOAuth($clientId, $clientSecret, $certOptions['verify']);
            $accessTokenResponse = $oauthClient->getAccessToken();
            $token = $accessTokenResponse->getAccessToken();
            Log::info('[GeneratePlanJob][JobID:{$this->jobId}] GigaChat access token obtained.');

            $apiUrl = 'https://gigachat.devices.sberbank.ru/api/v1/chat/completions';
            $requestBody = ['model' => 'GigaChat-Pro', 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => 0.6];
            Log::info("[GeneratePlanJob][JobID:{$this->jobId}] Sending direct HTTP request to GigaChat.");
            $response = Http::withToken($token)->withOptions($certOptions)->acceptJson()->timeout($this->timeout - 20)->post($apiUrl, $requestBody);

            if ($response->successful()) {
                $rawApiResponse = $response->json();
                $gigaChatContent = $rawApiResponse['choices'][0]['message']['content'] ?? null;

                if ($gigaChatContent) {
                    $jsonStringFromContent = $gigaChatContent;
                    if (strpos(trim($jsonStringFromContent), '```json') === 0) {
                        $jsonStringFromContent = preg_replace('/^```json\s*/', '', $jsonStringFromContent);
                        $jsonStringFromContent = preg_replace('/\s*```$/', '', $jsonStringFromContent);
                    }
                    $jsonStringFromContent = trim($jsonStringFromContent);
                    Log::debug('[GeneratePlanJob][JobID:{$this->jobId}] Extracted JSON string from GigaChat content for parsing:', ['json_string' => $jsonStringFromContent]);
                    $planData = json_decode($jsonStringFromContent, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($planData)) {
                        Log::info('[GeneratePlanJob][JobID:{$this->jobId}] GigaChat content parsed as JSON successfully.');
                        Log::debug('[GeneratePlanJob][JobID:{$this->jobId}] Parsed plan data:', $planData);
                        PlanJobStatus::where('id', $this->jobId)->update(['status' => 'completed', 'result' => $planData]);
                        Log::info("[GeneratePlanJob][JobID:{$this->jobId}] Plan status updated to 'completed' in DB.");
                    } else {
                        $this->failLog('Failed to decode plan JSON from GigaChat content. JSON error: '.json_last_error_msg(), ['extracted_string_preview' => mb_substr($jsonStringFromContent, 0, 500)]);

                        return;
                    }
                } else {
                    $this->failLog('GigaChat API call successful but content field is missing or empty.', ['response' => $rawApiResponse]);

                    return;
                }
            } else {
                $errorBody = $response->json() ?? $response->body();
                Log::error('[GeneratePlanJob][JobID:{$this->jobId}] GigaChat API call failed', ['status' => $response->status(), 'body' => $errorBody]);
                PlanJobStatus::where('id', $this->jobId)->update(['status' => 'failed', 'result' => ['error' => 'GigaChat API call failed', 'status_code' => $response->status(), 'response_body' => $errorBody]]);
                $response->throw(); 
            }
        } catch (Throwable $e) {
            $this->failLog('GigaChat process exception occurred: '.$e->getMessage(), ['trace_preview' => substr($e->getTraceAsString(), 0, 500)]);

            return;
        }
        Log::info("[GeneratePlanJob][JobID:{$this->jobId}] Successfully finished.");
    }

    /**
     * Хелпер для логирования ошибки и провала задачи.
     */
    private function failLog(string $errorMessage, array $context = []): void
    {
        Log::error("[GeneratePlanJob][JobID:{$this->jobId}] ".$errorMessage, $context);
        PlanJobStatus::where('id', $this->jobId)->update(['status' => 'failed', 'result' => json_encode(array_merge(['error' => $errorMessage], $context))]);
    }

    public function failed(Throwable $exception): void
    {
        Log::critical("[GeneratePlanJob][JobID:{$this->jobId}] FAILED permanently after {$this->tries} tries.", [
            'goal' => $this->goal, 'groupId' => $this->groupId, 'exception_message' => $exception->getMessage(),
        ]);
        $jobStatusEntry = PlanJobStatus::find($this->jobId);
        if ($jobStatusEntry && $jobStatusEntry->status !== 'failed') {
            $jobStatusEntry->update([
                'status' => 'failed',
                'result' => json_encode(['error' => 'Job failed permanently after all retries', 'exception_message' => $exception->getMessage()]),
            ]);
        }
    }
}
