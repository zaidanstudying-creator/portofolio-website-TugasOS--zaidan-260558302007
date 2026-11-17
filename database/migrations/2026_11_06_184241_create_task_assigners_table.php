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
        Schema::table('task_assigners', function (Blueprint $table): void {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigner_id')->constrained()->cascadeOnDelete();
            $table->unique(['task_id', 'assigner_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_assigners', function (Blueprint $table): void {
            $table->dropUnique(['task_id', 'assigner_id']);
            $table->dropConstrainedForeignId('task_id');
            $table->dropConstrainedForeignId('assigner_id');
        });
    }
};
