<?php

namespace App\Services;

use GuzzleHttp\Client;
use Exception;
use Illuminate\Support\Facades\Log;

class ScheduleParserService
{
    protected Client $httpClient;
    protected string $scheduleApiUrl = 'https://www.miet.ru/schedule/data'; // Обязательно вставьте сюда реальный URL API
    protected string $cacertPath = 'C:\Users\darin\Downloads\cacert.pem'; // Укажите найденный вами путь

    public function __construct()
    {
        $this->httpClient = new Client([
            'verify' => $this->cacertPath,
        ]);
    }

    public function getWeekSchedule(string $groupName): array
{
    Log::info('Запрос расписания для группы: ' . $groupName);
    Log::info('URL API: ' . $this->scheduleApiUrl);

    try {
        $response = $this->httpClient->post($this->scheduleApiUrl, [
            'form_params' => [
                'group' => $groupName,
            ],
        ]);

        Log::info('Статус HTTP ответа: ' . $response->getStatusCode());
        Log::info('Тело ответа API: ' . $response->getBody());

        $data = json_decode($response->getBody(), true);
        Log::info('Данные после json_decode: ' . print_r($data, true));

        if (!isset($data['Data'])) {
            Log::warning('В ответе API отсутствует ключ "Data".');
            return [];
        }

        $lessonsByDayAndWeekType = [];
        $dayNames = [
            1 => 'понедельник',
            2 => 'вторник',
            3 => 'среда',
            4 => 'четверг',
            5 => 'пятница',
            6 => 'суббота',
            7 => 'воскресенье',
        ];
    
        foreach ($data['Data'] as $lessonData) {
            $day = $lessonData['Day'];
            $weekType = $lessonData['DayNumber'];
    
            if (!isset($lessonsByDayAndWeekType[$day])) {
                $lessonsByDayAndWeekType[$day] = [];
            }
            if (!isset($lessonsByDayAndWeekType[$day][$weekType])) {
                $lessonsByDayAndWeekType[$day][$weekType] = [];
            }
    
            $lessonsByDayAndWeekType[$day][$weekType][] = [
                'time_from' => substr($lessonData['Time']['TimeFrom'], 11, 5),
                'time_to' => substr($lessonData['Time']['TimeTo'], 11, 5),
                'subject' => $lessonData['Class']['Name'],
                'room' => $lessonData['Room']['Name'],
                'teacher' => $lessonData['Class']['Teacher'],
                // Добавьте другие нужные поля
            ];
        }
    
        // Сортировка занятий по времени начала для каждого дня и типа недели
        foreach ($lessonsByDayAndWeekType as $day => $weekTypeLessons) {
            foreach ($weekTypeLessons as $weekType => $lessons) {
                usort($lessons, function ($a, $b) {
                    return strcmp($a['time_from'], $b['time_from']);
                });
                $lessonsByDayAndWeekType[$day][$weekType] = $lessons;
            }
        }
    
        Log::info('Сформированный и отсортированный массив lessons: ' . print_r($lessonsByDayAndWeekType, true));
        return $lessonsByDayAndWeekType;
    
    } catch (\Exception $e) {
        Log::error('Ошибка при получении расписания: ' . $e->getMessage());
        return [];
    }
}
}
