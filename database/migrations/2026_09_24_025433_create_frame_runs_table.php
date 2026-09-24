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
        Schema::create('frame_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('scan_session_id')->constrained()->cascadeOnDelete();
            $table->string('frame_path')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('latency_ms');
            $table->unsignedInteger('item_count');
            $table->unsignedInteger('model_calls');
            $table->unsignedInteger('searches_performed');
            $table->string('model')->nullable();
            $table->longText('instructions')->nullable();
            $table->json('input_json')->nullable();
            $table->json('events_json')->nullable();
            $table->json('raw_responses_json')->nullable();
            $table->json('output_json')->nullable();
            $table->json('usage_json')->nullable();
            $table->string('status');
            $table->text('error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('frame_runs');
    }
};
