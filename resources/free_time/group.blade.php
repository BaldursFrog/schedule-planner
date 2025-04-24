<!DOCTYPE html>
<html>
<head>
    <title>Свободное время группы {{ $group_id }}</title>
</head>
<body>
    <h1>Свободное время группы {{ $group_id }}</h1>
    @if ($free_time)
        <ul>
            @foreach ($free_time as $slot)
                <li>{{ $slot[0] }} - {{ $slot[1] }}</li>
            @endforeach
        </ul>
    @else
        <p>Свободного времени не найдено.</p>
    @endif
</body>
</html>