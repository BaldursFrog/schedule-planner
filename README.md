# Telegram Bot

Этот проект представляет собой Telegram-бот, разработанный на фреймворке Laravel. Бот позволяет пользователям задавать учебные группы и цели, генерировать учебные планы через внешний API и управлять процессом через команды. Данные пользователей хранятся в JSON-файлах (`user_data.json`, `user_states.json`, `user_jobs.json`).

## Требования

- PHP >= 8.1
- Composer
- Laravel 10.x
- GuzzleHTTP для отправки запросов к внешнему API
- WSL Ubuntu (для разработки и запуска)
- (Опционально) Docker и Docker Compose для контейнеризации
- (Опционально) `ngrok` для настройки вебхука в Telegram

## Установка (WSL Ubuntu)

1. **Клонируйте репозиторий**:
   ```bash
   git clone <repository-url>
   cd <project-folder>
   ```

2. **Установите зависимости**:
   ```bash
   composer install
   ```

3. **Настройте окружение**:
   - Скопируйте файл `.env.example` в `.env`:
     ```bash
     cp .env.example .env
     ```
   - Укажите токен Telegram в `.env`:
     ```env
     TELEGRAM_BOT_TOKEN=your-telegram-bot-token
     ```
   - Сгенерируйте ключ приложения:
     ```bash
     php artisan key:generate
     ```

4. **Настройте вебхук для Telegram**:
   - Запустите локальный сервер:
     ```bash
     php artisan serve
     ```
   - Используйте `ngrok` для создания публичного URL:
     ```bash
     ngrok http 8000
     ```
   - Настройте вебхук, заменив `<ngrok-url>` и `<your-token>`:
     ```bash
     curl -F "url=https://<ngrok-url>/api/v1/webhook" https://api.telegram.org/bot<your-token>/setWebhook
     ```

## Команды бота

Бот поддерживает следующие команды:

- `/start` - Начать работу с ботом
- `/help` - Показать список доступных команд
- `/plan` - Показать текущую учебную цель из `user_data.json`
- `/EnterGroup` - Указать учебную группу (например, ПИН-36)
- `/EnterGoal` - Указать учебную цель
- `/GeneratePlan` - Запустить генерацию учебного плана
- `/Cancel` - Отменить текущую операцию

## Инструменты разработки

- **Линтер**: PHPStan для статического анализа кода:
  ```bash
  composer phpstan
  ```
- **Форматирование кода**: PHP-CS-Fixer для соблюдения стандартов PSR-12:
  ```bash
  composer format
  ```
- **Тесты**: PHPUnit для unit-тестирования:
  ```bash
  php artisan test
  ```
- **Git hooks**: Pre-commit хук для автоматического запуска линтеров и тестов (настройте в `.git/hooks/pre-commit`).

### Настройка инструментов

1. **PHPStan**:
   - Установите:
     ```bash
     composer require --dev phpstan/phpstan
     ```
   - Создайте `phpstan.neon` в корне проекта:
     ```neon
     parameters:
         level: 7
         paths:
             - app/
     ```
   - Запустите:
     ```bash
     composer phpstan
     ```

2. **PHP-CS-Fixer**:
   - Установите:
     ```bash
     composer require --dev friendsofphp/php-cs-fixer
     ```
   - Создайте `.php-cs-fixer.php`:
     ```php
     <?php
     use PhpCsFixer\Config;
     use PhpCsFixer\Finder;

     $rules = [
         '@PSR12' => true,
         'array_syntax' => ['syntax' => 'short'],
         'ordered_imports' => true,
         'no_unused_imports' => true,
     ];

     $finder = Finder::create()
         ->in(__DIR__ . '/app')
         ->name('*.php')
         ->ignoreDotFiles(true)
         ->ignoreVCS(true);

     return (new Config())
         ->setRules($rules)
         ->setFinder($finder);
     ```
   - Запустите:
     ```bash
     composer format
     ```

3. **Git hooks**:
   - Создайте `.git/hooks/pre-commit`:
     ```bash
     touch .git/hooks/pre-commit
     chmod +x .git/hooks/pre-commit
     ```
   - Добавьте в `.git/hooks/pre-commit`:
     ```bash
     #!/bin/sh
     echo "Running PHPStan..."
     composer phpstan || exit 1
     echo "Running PHP-CS-Fixer..."
     composer format || exit 1
     echo "Running Tests..."
     php artisan test || exit 1
     ```

## Структура проекта

- `app/Http/Controllers/TelegramBotController.php` - Основная логика бота
- `tests/Feature/TelegramBotControllerTest.php` - Unit-тесты
- `user_data.json` - Хранит данные пользователей (группа, цель)
- `user_states.json` - Хранит временные состояния пользователей
- `user_jobs.json` - Хранит идентификаторы задач генерации плана
- `phpstan.neon` - Конфигурация PHPStan
- `.php-cs-fixer.php` - Конфигурация PHP-CS-Fixer

## Контейнеризация (Docker)

1. **Установите Docker и Docker Compose**:
   ```bash
   sudo apt update
   sudo apt install docker.io docker-compose
   sudo usermod -aG docker $USER
   ```

2. **Создайте `Dockerfile`** в корне проекта:
   ```dockerfile
   FROM php:8.1-fpm

   RUN apt-get update && apt-get install -y \
       git \
       curl \
       libpng-dev \
       libonig-dev \
       libxml2-dev \
       zip \
       unzip

   RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

   WORKDIR /var/www
   COPY . .
   RUN composer install --no-dev --optimize-autoloader

   RUN chown -R www-data:www-data /var/www
   CMD ["php-fpm"]
   ```

3. **Создайте `docker-compose.yml`**:
   ```yaml
   version: '3.8'
   services:
     app:
       build: .
       ports:
         - "8000:8000"
       volumes:
         - .:/var/www
       environment:
         - APP_ENV=production
         - TELEGRAM_BOT_TOKEN=${TELEGRAM_BOT_TOKEN}
       depends_on:
         - redis
     redis:
       image: redis:6
       ports:
         - "6379:6379"
   ```

4. **Запустите контейнеры**:
   ```bash
   docker-compose up -d
   ```

5. **Настройте вебхук**:
   - Запустите `ngrok`:
     ```bash
     ngrok http 8000
     ```
   - Настройте вебхук:
     ```bash
     curl -F "url=https://<ngrok-url>/api/v1/webhook" https://api.telegram.org/bot<your-token>/setWebhook
     ```

## API Design Guide

API следует REST-принципам:

- **Эндпоинт**: `POST /api/v1/webhook`
  - Принимает JSON от Telegram
  - Возвращает JSON: `{"status": "success"}` или `{"status": "error", "message": "..."}`
- **Формат ответа**: Все ответы в формате JSON с полями `status` и `message` (при ошибке).
- **Документация**: Используйте `laravel-apidoc-generator` для генерации документации:
  ```bash
  composer require --dev mpociot/laravel-apidoc-generator
  php artisan apidoc:generate
  ```

## Пример данных

Файл `user_data.json`:
```json
{
    "1014034805": {
        "group": "ПИН-36",
        "goal": "Изучить Laravel"
    }
}
```

Команда `/plan` для пользователя с ID `1014034805` выведет: `📋 Цель: Изучить Laravel`.