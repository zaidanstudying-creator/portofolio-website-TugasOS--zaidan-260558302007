<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recurrence_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('frequency');
            $table->unsignedTinyInteger('interval')->default(1);
            $table->json('weekdays')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('next_run_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'template_task_id']);
            $table->index(['is_active', 'next_run_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurrence_rules');
    }
};
