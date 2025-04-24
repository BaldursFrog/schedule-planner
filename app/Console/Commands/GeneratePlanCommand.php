<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\GeneratePlanJob; // Подключаем наш класс Задачи
use Illuminate\Support\Facades\Log; // Для логирования
use Throwable; // Для перехвата ошибок

class GeneratePlanCommand extends Command
{
    /**
     * Имя и сигнатура команды.
     * {userId} - Обязательный аргумент ID пользователя.
     * {goal} - Обязательный аргумент - цель обучения (в кавычках, если с пробелами).
     *
     * @var string
     */
    protected $signature = 'plan:generate {userId : The ID of the user} {goal : The learning goal for the plan}';

    /**
     * Описание команды.
     *
     * @var string
     */
    protected $description = 'Dispatches a job to generate a learning plan using GigaChat based on user goal and schedule';

    /**
     * Выполнение команды.
     */
    public function handle(): int
    {
        // Получаем аргументы, переданные в команду
        // Например, при вызове "php artisan plan:generate 123 "Изучить Docker""
        // $userId будет 123, $goal будет "Изучить Docker"
        try {
             // Преобразуем userId в целое число
             $userId = (int)$this->argument('userId');
             $goal = $this->argument('goal');

             // Простая проверка, что userId - это число больше 0
             if ($userId <= 0) {
                 $this->error('Invalid User ID provided. Must be a positive integer.');
                 return Command::FAILURE; // Используем стандартные константы Laravel для кодов возврата
             }
             // Простая проверка, что цель не пустая
             if (empty(trim($goal))) {
                  $this->error('Goal cannot be empty.');
                  return Command::FAILURE;
             }

        } catch (Throwable $e) {
            // Ловим ошибку, если аргументы не удалось получить или преобразовать
             $this->error('Error processing command arguments: ' . $e->getMessage());
             return Command::INVALID; // Стандартный код для неверных аргументов/опций
        }


        // Выводим информацию в консоль о том, что сейчас произойдет
        $this->info("Attempting to dispatch GeneratePlanJob for User ID: {$userId}, Goal: \"{$goal}\"");

        try {
            // Создаем и отправляем нашу задачу GeneratePlanJob в очередь.
            // Laravel сам позаботится о сериализации и отправке в Redis (согласно .env).
            GeneratePlanJob::dispatch($userId, $goal);

            // Логируем факт успешной отправки
            Log::info("GeneratePlanJob dispatched successfully via command for User ID: {$userId}, Goal: \"{$goal}\"");
            // Сообщаем пользователю в консоли
            $this->info('GeneratePlanJob dispatched successfully to the queue.');

        } catch (Throwable $e) {
             // Ловим ошибку, если при отправке задачи в очередь что-то пошло не так
             // (например, Redis недоступен, или ошибка сериализации)
             $this->error("An exception occurred while dispatching the job: " . $e->getMessage());
             Log::error('GeneratePlanJob dispatch exception from command', ['user_id' => $userId, 'goal' => $goal, 'exception' => $e->getMessage()]);
             // Возвращаем код общей ошибки
             return Command::FAILURE;
        }

        // Возвращаем код успешного завершения команды
        return Command::SUCCESS;
    }
}