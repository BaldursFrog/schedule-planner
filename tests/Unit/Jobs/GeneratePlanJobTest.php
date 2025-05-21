<?php

namespace Tests\Unit\Jobs;

use Tests\TestCase;
use App\Jobs\GeneratePlanJob;
use App\Models\PlanGenerationJob as PlanJobStatusModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GeneratePlanJobTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;
    private string $goal;
    private string $groupId;
    private string $jobId;

    private string $fakeMietServiceBaseUrl = 'https://fake-miet-service-for-test.com';
    private string $fakeGigaChatApiUrl = 'https://gigachat.devices.sberbank.ru/api/v1/chat/completions';
    private string $fakeGigaChatOAuthUrl = 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth';

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = 123;
        $this->goal = 'Изучить Laravel юнит-тесты';
        $this->groupId = 'TEST-GROUP';
        $this->jobId = (string) Str::uuid();

        PlanJobStatusModel::create([
            'id' => $this->jobId,
            'user_id' => $this->userId,
            'goal' => $this->goal,
            'group_id' => $this->groupId,
            'status' => 'pending',
        ]);

        putenv('MIET_SCHEDULE_SERVICE_URL=' . $this->fakeMietServiceBaseUrl);
        putenv('GIGACHAT_CLIENT_ID=test_client_id_for_phpunit');
        putenv('GIGACHAT_CLIENT_SECRET=test_client_secret_for_phpunit');
        Http::preventStrayRequests();
    }

    /**
     * Тест случая, когда GigaChat возвращает успешный HTTP ответ, но некорректный JSON в поле content.
     */
    public function test_generate_plan_job_handles_gigachat_malformed_json_in_content(): void
    {
         Http::fake([
            $this->fakeMietServiceBaseUrl . '/current-week' => Http::response(['type_name' => '1 числитель test'], 200),
            $this->fakeMietServiceBaseUrl . '/free-time/' . $this->groupId => Http::response([], 200),
            $this->fakeGigaChatOAuthUrl => Http::response(['access_token' => 'fake-test-token', 'expires_at' => time() + 3600], 200),
            $this->fakeGigaChatApiUrl => Http::response(['choices' => [['message' => ['content' => 'Это не JSON { ```']]]], 200),
        ]);

        $job = new GeneratePlanJob($this->userId, $this->goal, $this->groupId, $this->jobId);
        $job->handle();

        $this->assertDatabaseHas('plan_generation_jobs', [
            'id' => $this->jobId,
            'status' => 'failed',
        ]);
        $jobStatus = PlanJobStatusModel::find($this->jobId);
        $this->assertIsArray($jobStatus->result);
        $this->assertStringContainsString('Failed to decode plan JSON from GigaChat content', $jobStatus->result['error']);
        $this->assertStringContainsString('Syntax error', $jobStatus->result['error']);
        $this->assertArrayHasKey('extracted_string_preview', $jobStatus->result);
        $this->assertStringContainsString('Это не JSON { ```', $jobStatus->result['extracted_string_preview']);
    }

    /**
     * Тест случая, когда GigaChat API возвращает 200, но поле 'content' отсутствует.
     */
    public function test_generate_plan_job_handles_gigachat_missing_content_field(): void
    {
        Http::fake([
            $this->fakeMietServiceBaseUrl . '/current-week' => Http::response(['type_name' => '1 числитель test'], 200),
            $this->fakeMietServiceBaseUrl . '/free-time/' . $this->groupId => Http::response([], 200),
            $this->fakeGigaChatOAuthUrl => Http::response(['access_token' => 'fake-test-token', 'expires_at' => time() + 3600], 200),
            $this->fakeGigaChatApiUrl => Http::response(['choices' => [['message' => ['text_instead_of_content' => 'some text']]]], 200),
        ]);

        $job = new GeneratePlanJob($this->userId, $this->goal, $this->groupId, $this->jobId);
        $job->handle();

        $this->assertDatabaseHas('plan_generation_jobs', [
            'id' => $this->jobId,
            'status' => 'failed',
        ]);
        $jobStatus = PlanJobStatusModel::find($this->jobId);
        $this->assertIsArray($jobStatus->result);
        $this->assertEquals('GigaChat API call successful but content field is missing or empty.', $jobStatus->result['error']);
        $this->assertIsArray($jobStatus->result['response']);
    }


    /**
     * Тест случая, когда MIET Service для /current-week возвращает ошибку HTTP.
     * Job должен использовать значения по умолчанию и все равно попытаться сгенерировать план.
     */
    public function test_generate_plan_job_handles_miet_current_week_http_failure(): void
    {
        $defaultSchedulePlanData = ["plan_title" => "План с дефолтным расписанием"];
        $fakeGigaChatApiResponseForDefault = ['choices' => [['message' => ['content' => '```json'.json_encode($defaultSchedulePlanData).'```']]]];

        Http::fake([
            $this->fakeMietServiceBaseUrl . '/current-week' => Http::response(null, 500),
            $this->fakeMietServiceBaseUrl . '/free-time/' . $this->groupId => Http::response([], 200),
            $this->fakeGigaChatOAuthUrl => Http::response(['access_token' => 'fake-test-token', 'expires_at' => time() + 3600], 200),
            $this->fakeGigaChatApiUrl => Http::response($fakeGigaChatApiResponseForDefault, 200),
        ]);

        $job = new GeneratePlanJob($this->userId, $this->goal, $this->groupId, $this->jobId);
        $job->handle();

        $this->assertDatabaseHas('plan_generation_jobs', [
            'id' => $this->jobId,
            'status' => 'completed',
        ]);
         $jobStatus = PlanJobStatusModel::find($this->jobId);
         $this->assertNotNull($jobStatus->result);
         $this->assertEquals($defaultSchedulePlanData, $jobStatus->result);
    }

    /**
     * Тест случая, когда оба MIET Service эндпоинта возвращают ошибку HTTP.
     */
    public function test_generate_plan_job_handles_both_miet_services_http_failure(): void
    {
        $defaultSchedulePlanData = ["plan_title" => "План с общим расписанием"];
        $fakeGigaChatApiResponseForDefault = ['choices' => [['message' => ['content' => '```json'.json_encode($defaultSchedulePlanData).'```']]]];

        Http::fake([
            $this->fakeMietServiceBaseUrl . '/current-week' => Http::response(null, 500),
            $this->fakeMietServiceBaseUrl . '/free-time/' . $this->groupId => Http::response(null, 500),
            $this->fakeGigaChatOAuthUrl => Http::response(['access_token' => 'fake-test-token', 'expires_at' => time() + 3600], 200),
            $this->fakeGigaChatApiUrl => Http::response($fakeGigaChatApiResponseForDefault, 200),
        ]);

        $job = new GeneratePlanJob($this->userId, $this->goal, $this->groupId, $this->jobId);
        $job->handle();

        $this->assertDatabaseHas('plan_generation_jobs', [
            'id' => $this->jobId,
            'status' => 'completed',
        ]);
         $jobStatus = PlanJobStatusModel::find($this->jobId);
         $this->assertNotNull($jobStatus->result);
         $this->assertEquals($defaultSchedulePlanData, $jobStatus->result);
    }

    protected function tearDown(): void
    {
        putenv('MIET_SCHEDULE_SERVICE_URL');
        putenv('GIGACHAT_CLIENT_ID');
        putenv('GIGACHAT_CLIENT_SECRET');
        parent::tearDown();
    }
}