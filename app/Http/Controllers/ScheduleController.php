<?php

namespace App\Http\Controllers;

use App\Services\ScheduleParserService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpClient\HttpClient;

class ScheduleController extends Controller
{
    protected $scheduleParserService;

    public function __construct(ScheduleParserService $scheduleParserService)
    {
        $this->scheduleParserService = $scheduleParserService;
    }

    public function showSchedule(string $groupName): JsonResponse
    {
        $scheduleData = $this->scheduleParserService->getWeekSchedule($groupName);

        $dayOrder = [
            1 => 'Понедельник',
            2 => 'Вторник',
            3 => 'Среда',
            4 => 'Четверг',
            5 => 'Пятница',
            6 => 'Суббота',
            7 => 'Воскресенье',
        ];

        $weekTypes = [
            0 => '1 числитель',
            1 => '1 знаменатель',
            2 => '2 числитель',
            3 => '2 знаменатель',
        ];

        $formattedSchedule = [];
        foreach ($dayOrder as $dayNumber => $dayName) {
            if (isset($scheduleData[$dayNumber])) {
                $formattedSchedule[$dayName] = [];
                foreach ($scheduleData[$dayNumber] as $weekTypeCode => $lessons) {
                    $weekTypeName = $weekTypes[$weekTypeCode] ?? 'Неизвестный тип недели';
                    $formattedSchedule[$dayName][$weekTypeName] = array_map(function ($lesson) {
                        unset($lesson['teacher']); // Удаляем ключ 'teacher'
                        unset($lesson['room']);    // Удаляем ключ 'room'
                        return $lesson;
                    }, $lessons);
                }
            }
        }

        return response()->json($formattedSchedule);
    }
}