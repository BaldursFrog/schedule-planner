<?php

namespace App\Http\Controllers;

use App\Services\ScheduleParserService;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    protected ScheduleParserService $scheduleParser;

    public function __construct(ScheduleParserService $scheduleParser)
    {
        $this->scheduleParser = $scheduleParser;
    }

    public function showScheduleForm()
    {
        return view('schedule.form'); // Страница с формой выбора группы
    }

    public function getScheduleForGroup(Request $request)
    {
        $groupName = $request->input('group'); // 
        $schedule = $this->scheduleParser->getTodaySchedule($groupName);

        return view('schedule.display', ['schedule' => $schedule, 'groupName' => $groupName]);
    }
}