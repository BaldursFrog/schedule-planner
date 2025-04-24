<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Edvardpotter\GigaChat\GigaChatOAuth;
use Throwable;

class GeneratePlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $userId;
    public string $goal;

    public $tries = 2;
    public $timeout = 180; 
    public $backoff = 30;

    public function __construct(int $userId, string $goal)
    {
        $this->userId = $userId;
        $this->goal = $goal;
    }

    public function handle(): void
    {
        Log::info("[GeneratePlanJob] Starting for User ID: {$this->userId}, Goal: {$this->goal}");

        // --- ШАГ 1: Чтение свободного  времени и выбор недели ---
        $freeTimeJsonPath = base_path('free_time_example.json');
        $currentWeekType = "1 числитель";

        $scheduleInfoForPrompt = '';
        $relevantFreeSlotsData = [];

        try {
            if (!File::exists($freeTimeJsonPath)) {
                throw new \Exception("Free time schedule file not found: {$freeTimeJsonPath}");
            }
            $jsonString = File::get($freeTimeJsonPath);
            $freeTimeData = json_decode($jsonString, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                 throw new \Exception("Failed to decode free time JSON: " . json_last_error_msg());
            }

            // Фильтруем свободные слоты по типу недели
            foreach ($freeTimeData as $dayName => $daySchedule) {
                if (isset($daySchedule[$currentWeekType]) && is_array($daySchedule[$currentWeekType])) {
                    if (!empty($daySchedule[$currentWeekType])) {
                         $relevantFreeSlotsData[$dayName] = $daySchedule[$currentWeekType];
                    }
                }
            }

            // Формируем текстовое описание свободных слотов для промпта
            if (empty($relevantFreeSlotsData)) {
                 Log::warning("[GeneratePlanJob] No relevant free slots found for week type: {$currentWeekType}. User ID: {$this->userId}");
                 $scheduleInfoForPrompt = "На текущую неделю ({$currentWeekType}) у меня нет данных о свободном времени. Предложи гибкий график.";
            } else {
                 $freeTimeText = "Мое доступное свободное время для учебы на этой неделе ({$currentWeekType}):\n";
                 foreach($relevantFreeSlotsData as $day => $slots) {
                     $formattedSlots = [];
                     foreach($slots as $slot) {
                         $from = $slot['from'] ?? '??';
                         $to = $slot['to'] ?? '??';
                         $formattedSlots[] = "с {$from} до {$to}";
                     }
                     if (!empty($formattedSlots)) {
                        $freeTimeText .= "- {$day}: " . implode(', ', $formattedSlots) . "\n";
                     }
                 }
                 $scheduleInfoForPrompt = trim($freeTimeText);
                 Log::info("[GeneratePlanJob] Processed free time slots for User ID: {$this->userId}");
            }

        } catch (Throwable $e) {
            Log::error("[GeneratePlanJob] Failed to load or process free time JSON. User ID: {$this->userId}. Error: {$e->getMessage()}");
            $this->fail($e);
            return;
        }

        // --- ШАГ 2: Формирование промпта с запросом JSON ---

        $jsonStructureExample = <<<JSON
{
  "plan_title": "Учебный план: [Название цели]",
  "estimated_duration_weeks": "[Примерное количество недель]",
  "weeks": [
    {
      "week_number": 1,
      "weekly_goal": "[Цель на неделю 1]",
      "tasks": [
        {
          "day_name": "[Название дня]",
          "suggested_slot": "[Предлагаемый слот ЧЧ:ММ-ЧЧ:ММ]",
          "topic": "[Тема/Модуль]",
          "description": "[Описание задачи/активности]",
          "estimated_minutes": "[Время в минутах]",
          "resources": ["[Ресурс 1]", "[Ресурс 2]"]
        }
      ]
    }
  ]
}
JSON;

        // Формируем сам промпт
        $prompt = "Составь, пожалуйста, пошаговый учебный план для достижения цели: '{$this->goal}'.\n\n" .
                  $scheduleInfoForPrompt . "\n\n" . 
                  "План должен быть реалистичным и использовать ТОЛЬКО указанные свободные промежутки времени для распределения учебных задач. " .
                  "ВАЖНО: Твой ответ должен быть СТРОГО валидным JSON объектом, без какого-либо текста до или после него, точно соответствующим следующей структуре:\n" .
                  "```json\n" .
                  $jsonStructureExample .
                  "\n```\n" .
                  "Заполни все поля актуальными данными для запрашиваемого учебного плана. " .
                  "Поле 'suggested_slot' должно быть одним из свободных промежутков времени, указанных ранее. " .
                  "Поле 'resources' должно содержать массив строк с названиями или ссылками на ресурсы.";

        Log::info("[GeneratePlanJob] Generated GigaChat prompt (requesting JSON) for User ID: {$this->userId}");
        

        // --- ШАГ 3: Вызов GigaChat API ---
        $clientId = env('GIGACHAT_CLIENT_ID');
        $clientSecret = env('GIGACHAT_CLIENT_SECRET'); // Auth Key

        if (!$clientId || !$clientSecret) { /* ... обработка ошибки ... */ $this->fail('...'); return; }

        $certPath = base_path('russiantrustedca.pem');
        $certOptions = file_exists($certPath) ? ['verify' => $certPath] : ['verify' => false];
        if ($certOptions['verify'] === false) { Log::warning("[GeneratePlanJob] Certificate not found, disabling SSL verification."); }

        try {
        
            Log::info("[GeneratePlanJob] Attempting to get GigaChat access token for User ID: {$this->userId}");
            $oauthClient = new GigaChatOAuth($clientId, $clientSecret, $certOptions['verify'] ?? false);
            $accessToken = $oauthClient->getAccessToken();
            $token = $accessToken->getAccessToken();
            Log::info('[GeneratePlanJob] GigaChat access token obtained.');

            
            $apiUrl = 'https://gigachat.devices.sberbank.ru/api/v1/chat/completions';
            Log::info('[GeneratePlanJob] Sending direct HTTP request to GigaChat.', ['user_id' => $this->userId]);

            $requestBody = [
                
                'model' => 'GigaChat-Max', 
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.6, 
                
            ];

            $response = Http::withToken($token)
                          ->withOptions($certOptions)
                          ->acceptJson() 
                          ->timeout(90)
                          ->post($apiUrl, $requestBody);

    
            if ($response->successful()) {
                // Получаем текст ответа - GigaChat должен вернуть строку, содержащую JSON
                $rawResponseText = $response->json('choices.0.message.content'); // Пытаемся извлечь контент

                if (empty($rawResponseText)) {
                     // Если контент пустой или не найден
                     Log::error('[GeneratePlanJob] GigaChat response content is empty or not found in expected path.', ['user_id' => $this->userId, 'response' => $response->json()]);
                     $this->fail('GigaChat response content is empty.'); return;
                }

                Log::debug('[GeneratePlanJob] Raw response content from GigaChat (expecting JSON string):', ['raw_response' => $rawResponseText]);

                // Пытаемся декодировать полученный текст как JSON
                // Иногда AI может добавить ```json ``` вокруг, убираем их
                $cleanedJsonString = trim($rawResponseText);
                if (str_starts_with($cleanedJsonString, '```json')) {
                    $cleanedJsonString = substr($cleanedJsonString, 7);
                }
                 if (str_ends_with($cleanedJsonString, '```')) {
                    $cleanedJsonString = substr($cleanedJsonString, 0, -3);
                }
                $cleanedJsonString = trim($cleanedJsonString);


                $planData = json_decode($cleanedJsonString, true);

                // Проверяем результат декодирования
                if (json_last_error() === JSON_ERROR_NONE && is_array($planData)) {
                    Log::info('[GeneratePlanJob] GigaChat API Success. Plan received and parsed as JSON.', ['user_id' => $this->userId]);
                    Log::debug('[GeneratePlanJob] Parsed plan data (preview):', ['plan_title' => $planData['plan_title'] ?? 'N/A', 'weeks_count' => count($planData['weeks'] ?? [])]);

                  

                } else {
                    Log::error('[GeneratePlanJob] GigaChat responded, but failed to decode the response content as JSON.', [
                        'user_id' => $this->userId,
                        'json_error' => json_last_error_msg(),
                        'raw_response_content_preview' => mb_substr($rawResponseText, 0, 500) . '...'
                    ]);
                    $this->fail('Failed to decode JSON response from GigaChat content.');
                    return;
                }
            } else {
                // Ошибка HTTP от API GigaChat
                Log::error('[GeneratePlanJob] GigaChat API call failed', [ /* ... */ ]);
                $response->throw();
            }
        } catch (Throwable $e) {
            Log::error('[GeneratePlanJob] GigaChat process exception occurred', [ /* ... */ ]);
            $this->fail($e); return;
        }

        Log::info("[GeneratePlanJob] Successfully finished for User ID: {$this->userId}");
    }

    public function failed(Throwable $exception): void
    {
        Log::critical("[GeneratePlanJob] FAILED permanently after attempts for User ID: {$this->userId}", [ /* ... */ ]);
            
    }
}