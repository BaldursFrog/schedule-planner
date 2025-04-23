<!DOCTYPE html>
<html>
<head>
    <title>Расписание группы {{ $group_id }}</title>
</head>
<body>
    <h1>Расписание группы {{ $group_id }}</h1>
    @if ($schedule)
        <ul>
            @foreach ($schedule as $lesson)
                <li>{{ $lesson->startTime }} - {{ $lesson->endTime }}: {{ $lesson->title }} ({{ $lesson->classroom }})</li>
            @endforeach
        </ul>
    @else
        <p>Расписание на сегодня отсутствует.</p>
    @endif
</body>
</html>