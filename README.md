# GigaChat Planner & Reminders Service

Микросервис на Laravel, предназначенный для генерации учебных планов с использованием GigaChat API и управления напоминаниями. Является частью более крупного проекта "Умный менеджер задач в Telegram-боте".

## Описание

Этот сервис выполняет следующие основные функции:
- Принимает запросы на генерацию учебного плана (цель, ID пользователя, ID группы).
- Взаимодействует с "MIET Schedule Service" для получения информации о свободном времени пользователя и типе текущей учебной недели.
- Отправляет сформированный запрос (цель + свободное время) в GigaChat API для генерации пошагового учебного плана.
- Возвращает сгенерированный план в формате JSON.
- Использует Redis для асинхронной обработки задач генерации планов.
- (В будущем) Будет отвечать за создание и отправку напоминаний и ежедневных планов.

## Технологический стек

- **PHP 8.2+**
- **Laravel Framework 12.x**
- **GigaChat API** (для генерации планов)
- **Redis** (для очередей задач)
- **MySQL** (или SQLite для локальной разработки) - для хранения статусов задач генерации планов.
- **Docker & Docker Compose** (для контейнеризации и локального развертывания)

## Установка и Запуск (с использованием Docker)

### Предварительные требования

- **Docker Desktop** должен быть установлен и запущен.
- **Composer** должен быть установлен локально (для некоторых команд, если не используется Docker для всего).
- **Git**

### Шаги установки

1.  **Клонировать репозиторий:**
    ```bash
    git clone <URL_вашего_репозитория>
    cd <имя_папки_проекта> 
    ```
    (Для тебя это, вероятно, `cd deepseek-planner-git` или `cd deepseek-planner`, если ты его сделал Git-репозиторием)

2.  **Создать файл `.env`:**
    Скопируй `.env.example` в `.env` и заполни необходимые переменные окружения:
    ```bash
    cp .env.example .env
    ```
    Ключевые переменные для заполнения в `.env`:
    ```dotenv
    APP_NAME=GigaChatPlanner
    APP_ENV=local
    APP_KEY= # Будет сгенерирован на следующем шаге
    APP_DEBUG=true
    APP_URL=http://localhost:8000 # Или порт, который ты используешь в APP_PORT

    # Настройки для Docker Compose (порты на хост-машине)
    APP_PORT=8000
    DB_FORWARD_PORT=33061
    REDIS_FORWARD_PORT=63791

    # Настройки базы данных (будут использоваться внутри Docker)
    DB_CONNECTION=mysql
    DB_HOST=planner_mysql_db # Имя сервиса из docker-compose.yml
    DB_PORT=3306
    DB_DATABASE=laravel_planner # Имя БД
    DB_USERNAME=sail # Пользователь БД
    DB_PASSWORD=password # Пароль для пользователя БД (измени!)
    DB_ROOT_PASSWORD=supersecretrootpassword # Пароль для root MySQL (измени!)

    # Настройки Redis (будут использоваться внутри Docker)
    REDIS_HOST=planner_redis_cache # Имя сервиса из docker-compose.yml
    REDIS_PORT=6379
    REDIS_PASSWORD=null

    # Учетные данные для GigaChat API
    GIGACHAT_CLIENT_ID=ВАШ_CLIENT_ID
    GIGACHAT_CLIENT_SECRET=ВАШ_КЛЮЧ_АВТОРИЗАЦИИ

    # URL для MIET Schedule Service (должен быть предоставлен сервисом Дарины, возможно, через Ngrok/Tunnel для тестов)
    MIET_SCHEDULE_SERVICE_URL=http://<URL_СЕРВИСА_ДАРИНЫ> 
    ```

3.  **Запустить Docker контейнеры:**
    Находясь в корневой папке проекта, выполни:
    ```bash
    docker-compose up -d --build
    ```
    Эта команда соберет образы (если это первый запуск или были изменения в `Dockerfile`) и запустит все необходимые сервисы (приложение, базу данных, Redis, воркер) в фоновом режиме.

4.  **Сгенерировать ключ приложения Laravel (если не был в `.env`):**
    ```bash
    docker-compose exec app php artisan key:generate
    ```

5.  **Выполнить миграции базы данных:**
    ```bash
    docker-compose exec app php artisan migrate
    ```
    Опционально, можно запустить сидеры: `docker-compose exec app php artisan db:seed`

6.  **Проверка доступности:**
    *   Приложение должно быть доступно в браузере по адресу: `http://localhost:<APP_PORT>` (например, `http://localhost:8000`).
    *   Тестовый API эндпоинт: `http://localhost:<APP_PORT>/api/ping`.
