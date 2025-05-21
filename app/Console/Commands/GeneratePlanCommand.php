<?php

namespace App\Console\Commands;

use App\Jobs\GeneratePlanJob;
use App\Models\PlanGenerationJob as PlanJobStatus;        
use Illuminate\Console\Command; 
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;             
use Throwable;

class GeneratePlanCommand extends Command
{
    /**
     * Имя и сигнатура команды.
     */
    protected $signature = 'plan:generate 
                            {userId : The ID of the user} 
                            {goal : The learning goal for the plan} 
                            {groupId : The ID of the group for fetching schedule}';

    /**
     * Описание команды.
     */
    protected $description = 'Dispatches a job to generate a learning plan using GigaChat and tracks its status';

    /**
     * Выполнение команды.
     */
    public function handle(): int
    {
        try {
            $userId = (int) $this->argument('userId');
            $goal = $this->argument('goal');
            $groupId = $this->argument('groupId');

            if ($userId <= 0) { /* ... ошибка ... */ return Command::FAILURE;
            }
            if (empty(trim($goal))) { /* ... ошибка ... */ return Command::FAILURE;
            }
            if (empty(trim($groupId))) { /* ... ошибка ... */ return Command::FAILURE;
            }

        } catch (Throwable $e) {
            $this->error('Error processing command arguments: '.$e->getMessage());

            return Command::INVALID;
        }

        
        $jobId = (string) Str::uuid();

        $this->info("Attempting to dispatch GeneratePlanJob with Job ID: {$jobId} for User ID: {$userId}, Goal: \"{$goal}\", Group ID: \"{$groupId}\"");

        try {
            // 1. Создаем запись в таблице plan_generation_jobs
            PlanJobStatus::create([
                'id' => $jobId, 
                'user_id' => $userId,
                'goal' => $goal,
                'group_id' => $groupId,
                'status' => 'pending', 
            ]);
            Log::info("[GeneratePlanCommand] Created PlanGenerationJob record in DB with ID: {$jobId}");

            // 2. Отправляем задачу GeneratePlanJob в очередь, передавая все ЧЕТЫРЕ параметра
            GeneratePlanJob::dispatch($userId, $goal, $groupId, $jobId);

            Log::info("[GeneratePlanCommand] GeneratePlanJob dispatched successfully with Job ID: {$jobId}");
            $this->info("GeneratePlanJob dispatched successfully to the queue. Job ID: {$jobId}");
            $this->comment("You can check the status later using: php artisan plan:status {$jobId}"); 

        } catch (Throwable $e) {
            $this->error('An exception occurred: '.$e->getMessage());
            Log::error('[GeneratePlanCommand] Exception during job creation/dispatch', [
                'job_id_attempted' => $jobId, 'user_id' => $userId, 'goal' => $goal, 'group_id' => $groupId,
                'exception' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
