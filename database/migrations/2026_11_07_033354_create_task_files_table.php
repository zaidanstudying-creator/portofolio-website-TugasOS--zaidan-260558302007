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
        Schema::create('task_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('reference');
            $table->string('original_name');
            $table->string('disk')->default('local');
            $table->text('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('byte_size');
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_final')->default(false);
            $table->string('sha256', 64)->nullable();
            $table->timestamps();

            $table->index(['task_id', 'kind', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_files');
    }
};
