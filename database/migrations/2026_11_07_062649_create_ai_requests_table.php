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
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 60);
            $table->string('provider', 40);
            $table->string('model', 120)->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('input_hash', 64);
            $table->json('result')->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
