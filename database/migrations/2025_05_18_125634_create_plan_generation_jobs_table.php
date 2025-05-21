<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_generation_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary(); // Уникальный ID задачи (job_id), используем UUID
            $table->foreignId('user_id')->nullable()->index(); // ID пользователя (может быть не связан с таблицей users)
            $table->text('goal');
            $table->string('group_id');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->longText('result')->nullable(); // Для хранения JSON плана или сообщения об ошибке
            $table->timestamps(); // created_at и updated_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_generation_jobs');
    }
};
