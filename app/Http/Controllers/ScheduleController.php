<?php

namespace App\Http\Controllers;

use App\Services\ScheduleParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ScheduleController extends Controller
{
    protected ScheduleParserService $scheduleParserService;

    // Карта дней недели
    protected array $dayOrder = [
        1 => 'Понедельник',
        2 => 'Вторник',
        3 => 'Среда',
        4 => 'Четверг',
        5 => 'Пятница',
        6 => 'Суббота',
        7 => 'Воскресенье',
    ];

    public function __construct(ScheduleParserService $scheduleParserService)
    {
        $this->scheduleParserService = $scheduleParserService;
    }

    public function getCurrentWeekInfo(): JsonResponse
    {
        $weekInfo = $this->scheduleParserService->getCurrentWeekInfo();

        if ($weekInfo === null) {
            return response()->json(
                ['message' => 'Информация о текущей неделе недоступна.
            Возможно, семестр еще не начался.'],
                404,
            );
        }

        return response()->json($weekInfo);
    }

    // Показывает расписание занятий для группы в формате JSON
    public function showSchedule(string $groupName): JsonResponse
    {
        $scheduleData = $this->scheduleParserService->getWeekSchedule($groupName);
        $weekTypesMap = $this->scheduleParserService->getWeekTypesMap();

        if (empty($scheduleData)) {
            Log::warning("Расписание для группы {$groupName} не найдено или произошла ошибка.");
            return response()->json(['message' => 'Расписание не найдено для группы ' . $groupName], 404);
        }

        $formattedSchedule = [];
        // Сортируем дни по порядку dayOrder
        foreach ($this->dayOrder as $dayNumber => $dayName) {
            if (isset($scheduleData[$dayNumber])) {
                $formattedSchedule[$dayName] = [];
                // Сортируем типы недель по их индексу (0, 1, 2, 3)
                ksort($scheduleData[$dayNumber]); // Сортируем по ключу (индексу типа недели)
                foreach ($scheduleData[$dayNumber] as $weekTypeIndex => $lessons) {
                    // Получаем имя типа недели по индексу
                    if (isset($weekTypesMap[$weekTypeIndex])) {
                        $weekTypeName = $weekTypesMap[$weekTypeIndex];
                        $formattedSchedule[$dayName][$weekTypeName] = array_map(function ($lesson) {
                            unset($lesson['teacher']);
                            unset($lesson['room']);
                            return $lesson;
                        }, $lessons);
                    } else {
                        //Log::warning("Обнаружен неизвестный тип недели
                        // '{$weekTypeIndex}' для дня {$dayNumber}, группы {$groupName}");
                    }
                }
                if (empty($formattedSchedule[$dayName])) {
                    unset($formattedSchedule[$dayName]);
                }
            }
        }

        return response()->json($formattedSchedule);
    }

    // Рассчитывает и возвращает свободное время для группы в формате JSON.
    public function getFreeTime(string $groupName): JsonResponse
    {
        $scheduleData = $this->scheduleParserService->getWeekSchedule($groupName);
        $weekTypesMap = $this->scheduleParserService->getWeekTypesMap();

        if (empty($scheduleData)) {
            Log::warning("Свободное время: Расписание для группы {$groupName} не найдено.");
            return response()->json(
                ['message' => 'Расписание не найдено для группы ' .
                $groupName . ', невозможно рассчитать свободное время.'],
                404,
            );
        }

        $formattedFreeTime = [];
        $dayStartTime = '08:00';
        $dayEndTime = '20:00';

        // Итерируемся по дням недели в правильном порядке
        foreach ($this->dayOrder as $dayNumber => $dayName) {
            $formattedFreeTime[$dayName] = [];

            // Массив для хранения уже обработанных типов недель для данного дня
            $processedWeekTypes = [];

            // Сначала обрабатываем дни, где есть занятия
            if (isset($scheduleData[$dayNumber])) {
                // Сортируем типы недель по индексу для консистентности вывода
                ksort($scheduleData[$dayNumber]);
                foreach ($scheduleData[$dayNumber] as $weekTypeIndex => $lessons) {
                    if (isset($weekTypesMap[$weekTypeIndex])) {
                        $weekTypeName = $weekTypesMap[$weekTypeIndex];
                        $freeSlots = $this->scheduleParserService->
                        calculateFreeTimeSlots($lessons, $dayStartTime, $dayEndTime);
                        $formattedFreeTime[$dayName][$weekTypeName] = $freeSlots;
                        $processedWeekTypes[$weekTypeIndex] = true; // Отмечаем тип недели как обработанный
                    }
                }
            }

            foreach ($weekTypesMap as $weekTypeIndex => $weekTypeName) {
                if (!isset($processedWeekTypes[$weekTypeIndex])) {
                    $freeSlots = $this->scheduleParserService->calculateFreeTimeSlots([], $dayStartTime, $dayEndTime);
                    $formattedFreeTime[$dayName][$weekTypeName] = $freeSlots;
                }
            }
        }

        return response()->json($formattedFreeTime);
    }
}
