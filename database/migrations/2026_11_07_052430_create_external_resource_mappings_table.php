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
        Schema::create('external_resource_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_connection_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type');
            $table->string('internal_id');
            $table->string('external_id');
            $table->string('external_container_id')->nullable();
            $table->string('sync_hash', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('status')->default('synced');
            $table->timestamps();

            $table->unique(['integration_connection_id', 'resource_type', 'internal_id'], 'external_mapping_internal_unique');
            $table->index(['integration_connection_id', 'resource_type', 'external_id'], 'external_mapping_remote_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_resource_mappings');
    }
};
