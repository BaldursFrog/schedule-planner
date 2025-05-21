<?php

namespace Tests\Unit\Services;

use App\Services\ScheduleParserService;
use GuzzleHttp\Client as GuzzleClient; // Дадим алиас, чтобы не путать с нашим моком
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;
use Illuminate\Support\Facades\Log; // Для проверки логирования

class ScheduleParserServiceTest extends TestCase
{
    protected ScheduleParserService $scheduleParserService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduleParserService = new ScheduleParserService();
    }

    // /**  // <<<--- УДАЛИ ИЛИ ЗАКОММЕНТИРУЙ СТАРЫЙ DOC-БЛОК С @test
    //  * Тест для метода getWeekTypesMap.
    //  * @test
    //  */
    #[Test] // <<<--- ДОБАВЬ АТРИБУТ ЗДЕСЬ
    public function it_returns_correct_week_types_map(): void
    {
        $expectedMap = [
            0 => '1 числитель',
            1 => '1 знаменатель',
            2 => '2 числитель',
            3 => '2 знаменатель',
        ];

        $actualMap = $this->scheduleParserService->getWeekTypesMap();

        $this->assertEquals($expectedMap, $actualMap, "Метод getWeekTypesMap должен возвращать корректную карту типов недель.");
    }

    #[Test]
    public function calculateFreeTimeSlots_returns_full_day_when_no_lessons(): void
    {
        $lessons = [];
        $dayStart = '08:00';
        $dayEnd = '20:00';

        $expectedFreeSlots = [
            ['from' => '08:00', 'to' => '20:00'],
        ];

        $actualFreeSlots = $this->scheduleParserService->calculateFreeTimeSlots($lessons, $dayStart, $dayEnd);

        $this->assertEquals($expectedFreeSlots, $actualFreeSlots);
    }

    #[Test]
    public function calculateFreeTimeSlots_returns_correct_slots_with_one_lesson_in_middle(): void
    {
        $lessons = [
            ['time_from' => '10:00', 'time_to' => '11:30'],
        ];
        $dayStart = '09:00';
        $dayEnd = '18:00';

        $expectedFreeSlots = [
            ['from' => '09:00', 'to' => '10:00'],
            ['from' => '11:30', 'to' => '18:00'],
        ];

        $actualFreeSlots = $this->scheduleParserService->calculateFreeTimeSlots($lessons, $dayStart, $dayEnd);

        $this->assertEquals($expectedFreeSlots, $actualFreeSlots);
    }

    #[Test]
    public function calculateFreeTimeSlots_returns_correct_slots_with_one_lesson_at_start(): void
    {
        $lessons = [
            ['time_from' => '09:00', 'time_to' => '10:30'],
        ];
        $dayStart = '09:00';
        $dayEnd = '18:00';

        $expectedFreeSlots = [
            ['from' => '10:30', 'to' => '18:00'],
        ];

        $actualFreeSlots = $this->scheduleParserService->calculateFreeTimeSlots($lessons, $dayStart, $dayEnd);

        $this->assertEquals($expectedFreeSlots, $actualFreeSlots);
    }

    #[Test]
    public function calculateFreeTimeSlots_returns_correct_slots_with_one_lesson_at_end(): void
    {
        $lessons = [
            ['time_from' => '16:30', 'time_to' => '18:00'],
        ];
        $dayStart = '09:00';
        $dayEnd = '18:00';

        $expectedFreeSlots = [
            ['from' => '09:00', 'to' => '16:30'],
        ];

        $actualFreeSlots = $this->scheduleParserService->calculateFreeTimeSlots($lessons, $dayStart, $dayEnd);

        $this->assertEquals($expectedFreeSlots, $actualFreeSlots);
    }

    #[Test]
    public function calculateFreeTimeSlots_returns_correct_slots_with_multiple_lessons_and_gaps(): void
    {
        $lessons = [
            ['time_from' => '10:00', 'time_to' => '11:30'],
            ['time_from' => '13:00', 'time_to' => '14:30'],
        ];
        $dayStart = '09:00';
        $dayEnd = '18:00';

        $expectedFreeSlots = [
            ['from' => '09:00', 'to' => '10:00'],
            ['from' => '11:30', 'to' => '13:00'],
            ['from' => '14:30', 'to' => '18:00'],
        ];

        $actualFreeSlots = $this->scheduleParserService->calculateFreeTimeSlots($lessons, $dayStart, $dayEnd);

        $this->assertEquals($expectedFreeSlots, $actualFreeSlots);
    }

    #[Test]
    public function calculateFreeTimeSlots_returns_correct_slots_with_consecutive_lessons(): void
    {
        $lessons = [
            ['time_from' => '10:00', 'time_to' => '11:30'],
            ['time_from' => '11:30', 'time_to' => '13:00'], // Занятие сразу после предыдущего
        ];
        $dayStart = '09:00';
        $dayEnd = '18:00';

        $expectedFreeSlots = [
            ['from' => '09:00', 'to' => '10:00'],
            ['from' => '13:00', 'to' => '18:00'], // Нет перерыва между 11:30 и 11:30
        ];

        $actualFreeSlots = $this->scheduleParserService->calculateFreeTimeSlots($lessons, $dayStart, $dayEnd);

        $this->assertEquals($expectedFreeSlots, $actualFreeSlots);
    }

    #[Test]
    public function calculateFreeTimeSlots_handles_lessons_outside_day_boundaries(): void
    {
        $lessons = [
            ['time_from' => '07:00', 'time_to' => '08:30'], // Занятие до начала дня
            ['time_from' => '10:00', 'time_to' => '11:30'],
            ['time_from' => '17:30', 'time_to' => '19:00'], // Занятие после конца дня
        ];
        $dayStart = '09:00';
        $dayEnd = '18:00';

        // Ожидаем, что $lastBusyEndTime обновится до 08:30,
        // затем первый слот будет с 09:00 (max($dayStart, $lastBusyEndTime)) до 10:00.
        // Затем слот с 11:30 до 17:30.
        // Последнее занятие заканчивается в 19:00, что больше $dayEnd, поэтому последнего слота не будет.
        $expectedFreeSlots = [
            ['from' => '09:00', 'to' => '10:00'], // lastBusyEndTime было 08:30, но dayStart = 09:00
            ['from' => '11:30', 'to' => '17:30'],
        ];

        $actualFreeSlots = $this->scheduleParserService->calculateFreeTimeSlots($lessons, $dayStart, $dayEnd);
        $this->assertEquals($expectedFreeSlots, $actualFreeSlots);
    }

    #[Test]
    public function calculateFreeTimeSlots_returns_empty_array_if_day_is_fully_booked(): void
    {
        $lessons = [
            ['time_from' => '09:00', 'time_to' => '18:00'],
        ];
        $dayStart = '09:00';
        $dayEnd = '18:00';

        $expectedFreeSlots = []; // Ожидаем пустой массив, так как нет свободного времени

        $actualFreeSlots = $this->scheduleParserService->calculateFreeTimeSlots($lessons, $dayStart, $dayEnd);

        $this->assertEquals($expectedFreeSlots, $actualFreeSlots);
    }

      #[Test]
    public function getCurrentWeekInfo_returns_null_before_semester_starts(): void
    {
        // Получаем сервис. Если ты решила не менять конструктор ScheduleParserService
        // и оставила его создание в setUp(), то можно использовать $this->scheduleParserService
        // Если ты удалила создание из setUp(), то создай его здесь:
        $scheduleParserService = new \App\Services\ScheduleParserService(); // Или $this->scheduleParserService, если он инициализируется в setUp

        // Устанавливаем "фейковую" текущую дату ДО начала семестра
        // SEMESTER_START_DATE = '2025-02-03'
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2025-01-01'));

        $result = $scheduleParserService->getCurrentWeekInfo();

        $this->assertNull($result, "Должен возвращаться null, если семестр еще не начался.");

        \Carbon\Carbon::setTestNow(); // Сбрасываем фейковую дату, чтобы не влиять на другие тесты
    }

    #[Test]
    public function getCurrentWeekInfo_returns_correct_week_type_for_first_week_of_semester(): void
    {
        $scheduleParserService = new \App\Services\ScheduleParserService();

        // SEMESTER_START_DATE = '2025-02-03' (понедельник)
        // Это будет первая неделя, индекс типа недели 0 => "1 числитель"
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2025-02-05')); // Среда первой недели

        $result = $scheduleParserService->getCurrentWeekInfo();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type_name', $result);
        $this->assertEquals('1 числитель', $result['type_name']);

        \Carbon\Carbon::setTestNow();
    }

    #[Test]
    public function getCurrentWeekInfo_returns_correct_week_type_for_fifth_week_of_semester(): void
    {
        $scheduleParserService = new \App\Services\ScheduleParserService();

        // SEMESTER_START_DATE = '2025-02-03'
        // 5-я неделя (weeksPassed = 4), индекс типа недели 4 % 4 = 0 => "1 числитель"
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2025-02-03')->addWeeks(4)->addDays(2)); // Среда пятой недели

        $result = $scheduleParserService->getCurrentWeekInfo();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type_name', $result);
        $this->assertEquals('1 числитель', $result['type_name']); // Ожидаем снова "1 числитель" для 5-й недели (4-недельный цикл)

        \Carbon\Carbon::setTestNow();
    }

    #[Test]
    public function getCurrentWeekInfo_returns_correct_week_type_for_second_week_type(): void
    {
        $scheduleParserService = new \App\Services\ScheduleParserService();

        // SEMESTER_START_DATE = '2025-02-03'
        // 2-я неделя (weeksPassed = 1), индекс типа недели 1 % 4 = 1 => "1 знаменатель"
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2025-02-03')->addWeeks(1)->addDays(2)); // Среда второй недели

        $result = $scheduleParserService->getCurrentWeekInfo();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type_name', $result);
        $this->assertEquals('1 знаменатель', $result['type_name']);

        \Carbon\Carbon::setTestNow();
    }

     #[Test]
    public function getWeekSchedule_returns_parsed_schedule_on_successful_api_response(): void
    {
        $groupName = 'ЭКТ-11';
        $fakeApiResponseJson = '{
            "Data": [
                {
                    "Day": 1,
                    "DayNumber": 0,
                    "Time": {"TimeFrom": "2022-09-01T09:00:00", "TimeTo": "2022-09-01T10:30:00"},
                    "Class": {"Name": "Математика", "Teacher": "Иванов И.И."},
                    "Room": {"Name": "А-101"}
                },
                {
                    "Day": 1,
                    "DayNumber": 0,
                    "Time": {"TimeFrom": "2022-09-01T10:40:00", "TimeTo": "2022-09-01T12:10:00"},
                    "Class": {"Name": "Физика", "Teacher": "Петров П.П."},
                    "Room": {"Name": "Б-202"}
                }
            ]
        }';

        // Создаем моки для Response и Stream
        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('getContents')->willReturn($fakeApiResponseJson);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getBody')->willReturn($mockStream);

        // Создаем мок для GuzzleHttp\Client
        $mockHttpClient = $this->createMock(GuzzleClient::class);
        $mockHttpClient->expects($this->once()) // Ожидаем, что post будет вызван один раз
            ->method('post')
            // Можно добавить ->with(...) для проверки URL и параметров, если нужно точность
            // Например: ->with('https://www.miet.ru/schedule/data', ['form_params' => ['group' => $groupName]])
            ->willReturn($mockResponse);

        // Создаем экземпляр сервиса, передавая наш мок HttpClient
        $scheduleParserService = new ScheduleParserService($mockHttpClient);

        // Вызываем тестируемый метод
        $result = $scheduleParserService->getWeekSchedule($groupName);

        // Утверждения
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey(1, $result); // День 1
        $this->assertArrayHasKey(0, $result[1]); // Тип недели 0
        $this->assertCount(2, $result[1][0]); // 2 занятия

        $firstLesson = $result[1][0][0];
        $this->assertEquals('09:00', $firstLesson['time_from']);
        $this->assertEquals('Математика', $firstLesson['subject']);
        $this->assertEquals('Иванов И.И.', $firstLesson['teacher']);
        $this->assertEquals('А-101', $firstLesson['room']);
    }

      #[Test]
    public function getWeekSchedule_returns_empty_array_when_api_returns_error_status_code(): void
    {
        $groupName = 'НЕ_СУЩЕСТВУЮЩАЯ_ГРУППА';

        // Создаем мок Response, который вернет статус 404
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(404); // API вернул ошибку
        // getBody() в этом случае может не вызываться, или вернуть пустой стрим,
        // но для надежности можно его тоже замокировать, если твой код пытается его читать
        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('getContents')->willReturn('{"Error": "Group not found"}'); // Пример тела ошибки от API
        $mockResponse->method('getBody')->willReturn($mockStream);


        $mockHttpClient = $this->createMock(GuzzleClient::class);
        $mockHttpClient->expects($this->once())
            ->method('post')
            ->willReturn($mockResponse);

        // Используем фасад Log для проверки, что ошибка была залогирована
        // Facade должен быть "шпионом" (spy) или моком, чтобы мы могли проверить вызовы.
        // В Laravel тестах фасады по умолчанию "реальные", но мы можем их подменить.
        // Для простоты, мы пока не будем проверять сам Log::error,
        // а сфокусируемся на результате метода.
        // Если захочешь проверять логи, нужно будет использовать Log::shouldReceive(...).

        $scheduleParserService = new ScheduleParserService($mockHttpClient);

        $result = $scheduleParserService->getWeekSchedule($groupName);

        $this->assertIsArray($result);
        $this->assertEmpty($result, "Метод должен вернуть пустой массив при ошибке API.");
    }
}