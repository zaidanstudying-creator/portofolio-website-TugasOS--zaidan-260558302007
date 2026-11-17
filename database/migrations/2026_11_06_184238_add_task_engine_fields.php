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
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->default('flexible');
            $table->string('status')->default('todo');
            $table->string('priority')->default('medium');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->unsignedSmallInteger('weight')->default(1);
            $table->text('notes')->nullable();
            $table->index(['user_id', 'status', 'due_at']);
            $table->index(['user_id', 'parent_task_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'status', 'due_at']);
            $table->dropIndex(['user_id', 'parent_task_id']);
            $table->dropConstrainedForeignId('parent_task_id');
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('course_id');
            $table->dropConstrainedForeignId('semester_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['title', 'description', 'type', 'status', 'priority', 'starts_at', 'due_at', 'estimated_minutes', 'weight', 'notes']);
        });
    }
};
