<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('submission_assessment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 20)->default('change');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->timestamps();
            $table->index(['task_submission_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        $snapshotAt = now();

        DB::table('task_submissions')
            ->select(['id', 'user_id', 'assessment_status', 'assessed_at'])
            ->orderBy('id')
            ->chunkById(500, function ($submissions) use ($snapshotAt): void {
                DB::table('submission_assessment_events')->insert($submissions->map(fn (object $submission): array => [
                    'task_submission_id' => $submission->id,
                    'user_id' => $submission->user_id,
                    'event_type' => 'snapshot',
                    'from_status' => null,
                    'to_status' => $submission->assessment_status,
                    'created_at' => $submission->assessed_at ?? $snapshotAt,
                    'updated_at' => $submission->assessed_at ?? $snapshotAt,
                ])->all());
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submission_assessment_events');
    }
};
