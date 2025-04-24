@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Расписание группы {{ $group }}</h1>
    
    @foreach($schedule as $day => $classes)
    <div class="card mb-4">
        <div class="card-header">
            <h3>{{ $day }}</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Время</th>
                        <th>Предмет</th>
                        <th>Аудитория</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($classes as $class)
                    <tr>
                        <td>{{ $class['time'] }}</td>
                        <td>{{ $class['subject'] }}</td>
                        <td>{{ $class['room'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endforeach
    
    <a href="{{ route('schedule.form') }}" class="btn btn-secondary">Назад</a>
</div>
@endsection