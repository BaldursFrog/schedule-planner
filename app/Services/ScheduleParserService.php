<?php

namespace App\Services;

use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class ScheduleParserService
{
    protected Client $httpClient;

    protected string $scheduleApiUrl = 'https://www.miet.ru/schedule/data';

    protected string $cacertPath = 'C:\Users\darin\Downloads\cacert.pem';

    protected const SEMESTER_START_DATE = '2025-02-03';

    protected array $weekTypesMap = [
         0 => '1 числитель',
         1 => '1 знаменатель',
         2 => '2 числитель',
         3 => '2 знаменатель',
    ];

    public function __construct()
    {
        $this->httpClient = new Client([
            'verify'      => $this->cacertPath,
            'timeout'     => 10.0,
            'http_errors' => false,
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

            $statusCode = $response->getStatusCode();
            Log::info('Статус HTTP ответа: ' . $statusCode);

            if ($statusCode !== 200) {
                Log::error("API вернул ошибку {$statusCode} для группы {$groupName}.");
                return [];
            }

            $responseBody = $response->getBody()->getContents();

            if (empty($responseBody) || !in_array($responseBody[0], ['{', '['])) {
                Log::error('Ответ API не является валидным JSON: ' . substr($responseBody, 0, 100) . '...');
                return [];
            }

            $data = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Ошибка декодирования JSON: ' . json_last_error_msg());
                Log::error('Тело ответа, вызвавшее ошибку: ' . substr($responseBody, 0, 200) . '...');
                return [];
            }

            if (!is_array($data) || !isset($data['Data']) || !is_array($data['Data'])) {
                Log::warning('В ответе API отсутствует ключ "Data" или он не является массивом.');
                if (isset($data['Error'])) {
                    Log::warning('API вернуло ошибку: ' . $data['Error']);
                }
                return [];
            }

            $lessonsByDayAndWeekType = [];

            foreach ($data['Data'] as $lessonData) {
                $day = $lessonData['Day'] ?? null;
                $weekTypeIndex = $lessonData['DayNumber'] ?? null;
                $timeFromRaw = $lessonData['Time']['TimeFrom'] ?? null;
                $timeToRaw = $lessonData['Time']['TimeTo'] ?? null;
                $subject = $lessonData['Class']['Name'] ?? 'Не указано';
                $room = $lessonData['Room']['Name'] ?? 'Не указано';
                $teacher = $lessonData['Class']['Teacher'] ?? 'Не указан';

                // Проверяем, что все необходимые данные есть
                if ($day === null || $weekTypeIndex === null || $timeFromRaw === null || $timeToRaw === null) {
                    Log::warning('Пропущена запись из-за отсутствия данных: ' . print_r($lessonData, true));
                    continue;
                }

                // Проверяем, что тип недели известен
                if (!array_key_exists($weekTypeIndex, $this->weekTypesMap)) {
                    Log::warning("Обнаружен неизвестный индекс типа недели 
                    '{$weekTypeIndex}' в данных API: " . print_r($lessonData, true));
                    continue;
                }

                $timeFrom = substr($timeFromRaw, 11, 5);
                $timeTo = substr($timeToRaw, 11, 5);

                if (!preg_match('/^\d{2}:\d{2}$/', $timeFrom) || !preg_match('/^\d{2}:\d{2}$/', $timeTo)) {
                    Log::warning('Некорректный формат времени в записи: ' . print_r($lessonData, true));
                    continue;
                }

                // Создание вложенных массивов, если их еще нет
                if (!isset($lessonsByDayAndWeekType[$day])) {
                    $lessonsByDayAndWeekType[$day] = [];
                }
                // Используем индекс типа недели (0, 1, 2, 3) как ключ
                if (!isset($lessonsByDayAndWeekType[$day][$weekTypeIndex])) {
                    $lessonsByDayAndWeekType[$day][$weekTypeIndex] = [];
                }

                $lessonsByDayAndWeekType[$day][$weekTypeIndex][] = [
                    'time_from' => $timeFrom,
                    'time_to'   => $timeTo,
                    'subject'   => $subject,
                    'room'      => $room,
                    'teacher'   => $teacher,
                ];
            }

            // Сортировка занятий по времени начала
            foreach ($lessonsByDayAndWeekType as $day => &$weekTypeLessons) {
                foreach ($weekTypeLessons as $weekTypeIndex => &$lessons) {
                    usort($lessons, function ($a, $b) {
                        return strcmp($a['time_from'], $b['time_from']);
                    });
                }
                unset($lessons);
            }
            unset($weekTypeLessons);

            return $lessonsByDayAndWeekType;
        } catch (Exception $e) {
            Log::error('Исключение при получении расписания для группы ' . $groupName . ': ' . $e->getMessage());
            return [];
        }
    }

    public function calculateFreeTimeSlots(array $lessons, string $dayStart = '08:00', string $dayEnd = '20:00'): array
    {
        $freeSlots = [];
        $lastBusyEndTime = $dayStart;

        foreach ($lessons as $lesson) {
            $currentLessonStart = $lesson['time_from'];
            $currentLessonEnd = $lesson['time_to'];

            if ($currentLessonStart > $lastBusyEndTime) {
                $freeSlots[] = ['from' => $lastBusyEndTime, 'to' => $currentLessonStart];
            }

            $lastBusyEndTime = max($lastBusyEndTime, $currentLessonEnd);
        }

        if ($dayEnd > $lastBusyEndTime) {
            $freeSlots[] = ['from' => $lastBusyEndTime, 'to' => $dayEnd];
        }

        return $freeSlots;
    }

    public function getCurrentWeekInfo(): ?array
    {
        try {
            $startDate = Carbon::createFromFormat('Y-m-d', self::SEMESTER_START_DATE)->startOfDay();
            $currentDate = Carbon::now()->startOfDay();

            // Проверяем, не наступил ли еще семестр
            if ($currentDate->isBefore($startDate)) {
                Log::warning('Попытка определить тип недели до начала семестра.', [
                    'current_date'   => $currentDate->toDateString(),
                    'semester_start' => $startDate->toDateString(),
                ]);
                return null;
            }

            // Считаем количество полных недель, прошедших с начала семестра
            $weeksPassed = $startDate->diffInWeeks($currentDate);

            // Рассчитываем номер недели в семестре (начиная с 1)
            $semesterWeekNumber = $weeksPassed + 1;

            // Определяем индекс типа недели (0, 1, 2, 3) на основе 4-недельного цикла
            $weekTypeIndex = $weeksPassed % 4;

            // Получаем имя типа недели из карты
            $weekTypeName = $this->weekTypesMap[$weekTypeIndex] ?? 'Неизвестный тип';

            Log::info('Определен тип текущей недели', [
                'semester_start'       => self::SEMESTER_START_DATE,
                'current_date'         => $currentDate->toDateString(),
                'weeks_passed'         => $weeksPassed,
                'semester_week_number' => $semesterWeekNumber,
                'week_type_index'      => $weekTypeIndex,
                'week_type_name'       => $weekTypeName,
            ]);

            return [
                //'type_index' => $weekTypeIndex,       // Индекс (0, 1, 2, 3)
                'type_name' => $weekTypeName,        // Название ("1 числитель", ...)
                //'semester_week_number' => $semesterWeekNumber // Номер недели с начала семестра (1, 2, 3...)
            ];
        } catch (Exception $e) {
            Log::error('Ошибка при расчете текущего типа недели: ' . $e->getMessage(), [
                'semester_start' => self::SEMESTER_START_DATE,
            ]);
            return null;
        }
    }

    // Метод для получения карты типов недель
    public function getWeekTypesMap(): array
    {
        return $this->weekTypesMap;
    }
}
