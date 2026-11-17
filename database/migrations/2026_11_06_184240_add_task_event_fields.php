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
        Schema::table('task_status_events', function (Blueprint $table): void {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('note')->nullable();
            $table->index(['task_id', 'created_at']);
        });

        Schema::table('task_progress_events', function (Blueprint $table): void {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('from_progress')->default(0);
            $table->unsignedTinyInteger('to_progress');
            $table->index(['task_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_progress_events', function (Blueprint $table): void {
            $table->dropIndex(['task_id', 'created_at']);
            $table->dropConstrainedForeignId('task_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['from_progress', 'to_progress']);
        });

        Schema::table('task_status_events', function (Blueprint $table): void {
            $table->dropIndex(['task_id', 'created_at']);
            $table->dropConstrainedForeignId('task_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['from_status', 'to_status', 'note']);
        });
    }
};
