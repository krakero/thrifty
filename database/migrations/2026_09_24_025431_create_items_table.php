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
        Schema::create('items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('scan_session_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignUlid('frame_run_id')->nullable()->index();
            $table->string('fingerprint')->unique();
            $table->string('name');
            $table->string('category');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->text('description');
            $table->string('condition');
            $table->float('confidence');
            $table->unsignedInteger('observed_price_cents')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->unsignedInteger('estimated_low_cents')->nullable();
            $table->unsignedInteger('estimated_high_cents')->nullable();
            $table->unsignedInteger('retail_price_cents')->nullable();
            $table->unsignedInteger('active_price_cents')->nullable();
            $table->unsignedInteger('sold_price_cents')->nullable();
            $table->text('value_summary');
            $table->string('thumbnail_path');
            $table->unsignedSmallInteger('box_x_min')->nullable();
            $table->unsignedSmallInteger('box_y_min')->nullable();
            $table->unsignedSmallInteger('box_x_max')->nullable();
            $table->unsignedSmallInteger('box_y_max')->nullable();
            $table->json('raw_json');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->unsignedInteger('seen_count')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
