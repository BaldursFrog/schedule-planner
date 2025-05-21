<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GeneratePlanJob;
use App\Models\PlanGenerationJob as PlanJobStatus;         // Наша задача для генерации плана
use Illuminate\Http\Request; // Наша модель для статусов задач
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;             // Для генерации UUID

class PlanController extends Controller
{
    /**
     * Принимает запрос на генерацию учебного плана.
     * Валидирует входные данные, создает запись о задаче в БД,
     * отправляет задачу GeneratePlanJob в очередь и возвращает job_id.
     *
     * Эндпоинт: POST /api/generate-plan
     * Ожидаемое тело запроса (JSON):
     * {
     *   "user_id": 123,
     *   "goal": "Изучить Laravel за месяц",
     *   "group_id": "ПИН-36"
     * }
     */
    public function requestPlanGeneration(Request $request)
    {
        // Логируем входящий запрос
        Log::info('[PlanController] Received request to /api/generate-plan', $request->all());

        // Валидация входных данных
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|min:1',
            'goal' => 'required|string|min:5|max:1000', // Немного увеличил мин. и макс. длину цели
            'group_id' => 'required|string|min:1|max:50', // Для ID группы
        ]);

        if ($validator->fails()) {
            Log::warning('[PlanController] Validation failed for /api/generate-plan', ['errors' => $validator->errors()->toArray()]);

            return response()->json(['errors' => $validator->errors()], 400); // 400 Bad Request
        }

        // Получаем проверенные данные
        $userId = $request->input('user_id');
        $goal = $request->input('goal');
        $groupId = $request->input('group_id');

        // Генерируем уникальный ID для этой задачи
        $jobId = (string) Str::uuid();

        try {
            // 1. Создаем запись в таблице plan_generation_jobs
            PlanJobStatus::create([
                'id' => $jobId, // Наш сгенерированный UUID
                'user_id' => $userId,
                'goal' => $goal,
                'group_id' => $groupId,
                'status' => 'pending', // Начальный статус
                // result по умолчанию будет null
            ]);
            Log::info("[PlanController] Created PlanGenerationJob record in DB with ID: {$jobId}");

            // 2. Отправляем задачу GeneratePlanJob в очередь, передавая все необходимые данные, включая jobId
            GeneratePlanJob::dispatch($userId, $goal, $groupId, $jobId);
            Log::info("[PlanController] Dispatched GeneratePlanJob with Job ID: {$jobId} for user_id: {$userId}");

            // 3. Отвечаем клиенту (Георгию), что задача принята и передаем jobId
            return response()->json([
                'status' => 'pending',
                'message' => 'Plan generation task has been queued successfully.',
                'job_id' => $jobId,
            ], 202); // 202 Accepted - запрос принят, но обработка не завершена

        } catch (\Exception $e) {
            Log::error('[PlanController] Error creating DB record or dispatching GeneratePlanJob', [
                'job_id_attempted' => $jobId, // Логируем ID, который пытались использовать
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);

            // Если что-то пошло не так на этапе создания записи или диспетчеризации
            return response()->json(['status' => 'error', 'message' => 'Failed to queue plan generation task due to an internal error.'], 500);
        }
    }

    /**
     * Возвращает статус и результат генерации плана по его ID.
     *
     * Эндпоинт: GET /api/get-plan-result/{job_id}
     */
    public function getPlanResult(string $job_id) // Laravel автоматически подставит {job_id} из URL сюда
    {
        Log::info("[PlanController] Received request to /api/get-plan-result for Job ID: {$job_id}");

        // Проверяем, валидный ли UUID нам передали
        if (! Str::isUuid($job_id)) {
            Log::warning("[PlanController] Invalid UUID format for Job ID: {$job_id}");

            return response()->json(['error' => 'Invalid Job ID format.'], 400);
        }

        // Ищем задачу в базе данных по её ID
        $jobStatusEntry = PlanJobStatus::find($job_id);

        // Если задача с таким ID не найдена
        if (! $jobStatusEntry) {
            Log::warning("[PlanController] Job not found for ID: {$job_id}");

            return response()->json(['error' => 'Job not found.'], 404); // 404 Not Found
        }

        // Формируем ответ в зависимости от статуса задачи
        $responsePayload = ['job_id' => $jobStatusEntry->id, 'status' => $jobStatusEntry->status];

        if ($jobStatusEntry->status === 'completed') {
            // Если задача завершена, добавляем результат (план)
            // Модель автоматически декодирует 'result' из JSON в массив благодаря $casts
            $responsePayload['plan_data'] = $jobStatusEntry->result;
            Log::info("[PlanController] Job ID: {$job_id} is completed. Returning plan data.");

            return response()->json($responsePayload, 200); // 200 OK
        } elseif ($jobStatusEntry->status === 'failed') {
            // Если задача провалена, добавляем сообщение об ошибке
            $responsePayload['error_details'] = $jobStatusEntry->result; // В result у нас JSON с ошибкой
            Log::warning("[PlanController] Job ID: {$job_id} has failed. Returning error details.");

            return response()->json($responsePayload, 200); // Возвращаем 200, но со статусом failed и деталями
        } else {
            // Если 'pending' или 'processing'
            Log::info("[PlanController] Job ID: {$job_id} is still {$jobStatusEntry->status}.");

            return response()->json($responsePayload, 202); // 202 Accepted (или 200 со статусом pending/processing)
        }
    }
}
