<?php

namespace Tests\Unit\Services;

use App\Services\ScheduleParserService;
use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

class ScheduleParserServiceTest extends TestCase
{
    protected ScheduleParserService $scheduleParserService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduleParserService = new ScheduleParserService();
    }

    #[Test]
    public function it_returns_correct_week_types_map(): void
    {
        $expectedMap = [
            0 => '1 числитель',
            1 => '1 знаменатель',
            2 => '2 числитель',
            3 => '2 знаменатель',
        ];

        $actualMap = $this->scheduleParserService->getWeekTypesMap();

        $this->assertEquals($expectedMap, $actualMap, 'Метод getWeekTypesMap должен возвращать корректную карту типов недель.');
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
            ['time_from' => '11:30', 'time_to' => '13:00'],
        ];
        $dayStart = '09:00';
        $dayEnd = '18:00';

        $expectedFreeSlots = [
            ['from' => '09:00', 'to' => '10:00'],
            ['from' => '13:00', 'to' => '18:00'],
        ];

        $actualFreeSlots = $this->scheduleParserService->calculateFreeTimeSlots($lessons, $dayStart, $dayEnd);

        $this->assertEquals($expectedFreeSlots, $actualFreeSlots);
    }

    #[Test]
    public function calculateFreeTimeSlots_handles_lessons_outside_day_boundaries(): void
    {
        $lessons = [
            ['time_from' => '07:00', 'time_to' => '08:30'],
            ['time_from' => '10:00', 'time_to' => '11:30'],
            ['time_from' => '17:30', 'time_to' => '19:00'],
        ];
        $dayStart = '09:00';
        $dayEnd = '18:00';

        $expectedFreeSlots = [
            ['from' => '09:00', 'to' => '10:00'],
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

        $expectedFreeSlots = [];

        $actualFreeSlots = $this->scheduleParserService->calculateFreeTimeSlots($lessons, $dayStart, $dayEnd);

        $this->assertEquals($expectedFreeSlots, $actualFreeSlots);
    }

    #[Test]
    public function getCurrentWeekInfo_returns_null_before_semester_starts(): void
    {
        $scheduleParserService = new \App\Services\ScheduleParserService();

        // Устанавливаем "фейковую" текущую дату ДО начала семестра
        // SEMESTER_START_DATE = '2025-02-03'
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2025-01-01'));

        $result = $scheduleParserService->getCurrentWeekInfo();

        $this->assertNull($result, 'Должен возвращаться null, если семестр еще не начался.');

        \Carbon\Carbon::setTestNow();
    }

    #[Test]
    public function getCurrentWeekInfo_returns_correct_week_type_for_first_week_of_semester(): void
    {
        $scheduleParserService = new \App\Services\ScheduleParserService();

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2025-02-05'));

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

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2025-02-03')->addWeeks(4)->addDays(2)); // Среда пятой недели

        $result = $scheduleParserService->getCurrentWeekInfo();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type_name', $result);
        $this->assertEquals('1 числитель', $result['type_name']);

        \Carbon\Carbon::setTestNow();
    }

    #[Test]
    public function getCurrentWeekInfo_returns_correct_week_type_for_second_week_type(): void
    {
        $scheduleParserService = new \App\Services\ScheduleParserService();

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
                    "Room": {"Name": "1201"}
                },
                {
                    "Day": 1,
                    "DayNumber": 0,
                    "Time": {"TimeFrom": "2022-09-01T10:40:00", "TimeTo": "2022-09-01T12:10:00"},
                    "Class": {"Name": "Физика", "Teacher": "Петров П.П."},
                    "Room": {"Name": "1202"}
                }
            ]
        }';

        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('getContents')->willReturn($fakeApiResponseJson);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getBody')->willReturn($mockStream);

        $mockHttpClient = $this->createMock(GuzzleClient::class);
        $mockHttpClient->expects($this->once())
            ->method('post')
            ->willReturn($mockResponse);

        $scheduleParserService = new ScheduleParserService($mockHttpClient);

        $result = $scheduleParserService->getWeekSchedule($groupName);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(0, $result[1]);
        $this->assertCount(2, $result[1][0]);

        $firstLesson = $result[1][0][0];
        $this->assertEquals('09:00', $firstLesson['time_from']);
        $this->assertEquals('Математика', $firstLesson['subject']);
        $this->assertEquals('Иванов И.И.', $firstLesson['teacher']);
        $this->assertEquals('1201', $firstLesson['room']);
    }

    #[Test]
    public function getWeekSchedule_returns_empty_array_when_api_returns_error_status_code(): void
    {
        $groupName = 'НЕ_СУЩЕСТВУЮЩАЯ_ГРУППА';

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(404);
        $mockStream = $this->createMock(StreamInterface::class);
        $mockStream->method('getContents')->willReturn('{"Error": "Group not found"}');
        $mockResponse->method('getBody')->willReturn($mockStream);

        $mockHttpClient = $this->createMock(GuzzleClient::class);
        $mockHttpClient->expects($this->once())
            ->method('post')
            ->willReturn($mockResponse);

        $scheduleParserService = new ScheduleParserService($mockHttpClient);

        $result = $scheduleParserService->getWeekSchedule($groupName);

        $this->assertIsArray($result);
        $this->assertEmpty($result, 'Метод должен вернуть пустой массив при ошибке API.');
    }
}
