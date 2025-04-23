<!DOCTYPE html>
<html>
<head>
<h1>Расписание группы {{ $groupName }}</h1>

@foreach ($dayNames as $dayNumber => $dayName)
    <h2>{{ $dayName }}</h2>
    @if (isset($scheduleData[$dayNumber]))
        @foreach ($weekTypes as $weekTypeCode => $weekTypeName)
            @if (isset($scheduleData[$dayNumber][$weekTypeCode]) && count($scheduleData[$dayNumber][$weekTypeCode]) > 0)
                <h3>{{ $weekTypeName }}</h3>
                <ul>
                    @foreach ($scheduleData[$dayNumber][$weekTypeCode] as $lesson)
                        <li>
                            {{ $lesson['time_from'] }} - {{ $lesson['time_to'] }}:
                            {{ $lesson['subject'] }}
                            ({{ $lesson['room'] }})
                        </li>
                    @endforeach
                </ul>
            @else
                @if (count($weekTypes) > 1)
                    <p>{{ $weekTypeName }}: Занятий нет</p>
                @endif
            @endif
        @endforeach
    @else
        <p>Занятий нет</p>
    @endif
@endforeach
</html>