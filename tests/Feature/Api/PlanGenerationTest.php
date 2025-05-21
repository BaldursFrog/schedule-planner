<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;  
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Illuminate\Support\Facades\Queue;  
use App\Jobs\GeneratePlanJob;          
use App\Models\PlanGenerationJob as PlanJobStatus;  
use Illuminate\Support\Str;

class PlanGenerationTest extends TestCase
{
    use RefreshDatabase;  

    /**
     * Тест успешного запроса на генерацию плана.
     */
    public function test_can_request_plan_generation_successfully(): void
    {
        Queue::fake(); // Мокируем очередь 

        $requestData = [
            'user_id' => 123,
            'goal' => 'Изучить юнит-тесты в Laravel',
            'group_id' => 'TEST-GROUP-01'
        ];

        // Отправляем POST-запрос на наш API эндпоинт
        $response = $this->postJson('/api/generate-plan', $requestData);

         
        $response->assertStatus(202);  

        
        $response->assertJsonStructure([
            'status',
            'message',
            'job_id'
        ]);
 
        $response->assertJsonFragment(['status' => 'pending']);
        $jobId = $response->json('job_id');  
        $this->assertTrue(Str::isUuid($jobId));  

        // Проверяем, что задача была отправлена в очередь
        Queue::assertPushed(GeneratePlanJob::class, function ($job) use ($requestData, $jobId) {
            return $job->userId === $requestData['user_id'] &&
                   $job->goal === $requestData['goal'] &&
                   $job->groupId === $requestData['group_id'] &&
                   $job->jobId === $jobId;
        });

         
        $this->assertDatabaseHas('plan_generation_jobs', [
            'id' => $jobId,
            'user_id' => $requestData['user_id'],
            'goal' => $requestData['goal'],
            'group_id' => $requestData['group_id'],
            'status' => 'pending'
        ]);
    }

    /**
     * Тест валидации при запросе на генерацию плана.
     */
    public function test_plan_generation_request_validation(): void
    {
        // Без user_id
        $response = $this->postJson('/api/generate-plan', ['goal' => 'test', 'group_id' => 'test']);
        $response->assertStatus(400) 
                 ->assertJsonValidationErrors(['user_id']);

        // Без goal
        $response = $this->postJson('/api/generate-plan', ['user_id' => 1, 'group_id' => 'test']);
        $response->assertStatus(400)
                 ->assertJsonValidationErrors(['goal']);

        // Без group_id
        $response = $this->postJson('/api/generate-plan', ['user_id' => 1, 'goal' => 'test']);
        $response->assertStatus(400)
                 ->assertJsonValidationErrors(['group_id']);
    }

    /**
     * Тест получения результата для существующей завершенной задачи.
     */
    public function test_can_get_completed_plan_result(): void
    {

        $jobId = (string) Str::uuid();
        $planResultData = ['plan_title' => 'Тестовый план', 'weeks' => []];
        PlanJobStatus::create([
            'id' => $jobId,
            'user_id' => 1,
            'goal' => 'test goal',
            'group_id' => 'test-group',
            'status' => 'completed',
            'result' => $planResultData 
        ]);


        $response = $this->getJson("/api/get-plan-result/{$jobId}");

        $response->assertStatus(200);
        $response->assertJson([
            'job_id' => $jobId,
            'status' => 'completed',
            'plan_data' => $planResultData
        ]);
    }

    /**
     * Тест получения результата для задачи в обработке.
     */
    public function test_can_get_processing_plan_result(): void
    {
        $jobId = (string) Str::uuid();
        PlanJobStatus::create([
            'id' => $jobId, 'user_id' => 1, 'goal' => 'test', 'group_id' => 'test', 'status' => 'processing'
        ]);

        $response = $this->getJson("/api/get-plan-result/{$jobId}");

        $response->assertStatus(202); 
        $response->assertJson([
            'job_id' => $jobId,
            'status' => 'processing'
        ]);
    }

    /**
     * Тест получения результата для несуществующей задачи.
     */
    public function test_get_plan_result_for_non_existent_job(): void
    {
        $nonExistentJobId = (string) Str::uuid();
        $response = $this->getJson("/api/get-plan-result/{$nonExistentJobId}");

        $response->assertStatus(404); 
        $response->assertJson(['error' => 'Job not found.']);
    }
}