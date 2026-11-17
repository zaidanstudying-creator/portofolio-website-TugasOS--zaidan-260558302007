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
            $table->foreignId('recurrence_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->date('recurrence_occurrence_date')->nullable();
            $table->unique(['recurrence_rule_id', 'recurrence_occurrence_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropUnique(['recurrence_rule_id', 'recurrence_occurrence_date']);
            $table->dropConstrainedForeignId('recurrence_rule_id');
            $table->dropColumn('recurrence_occurrence_date');
        });
    }
};
