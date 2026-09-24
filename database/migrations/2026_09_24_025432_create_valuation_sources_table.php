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
        Schema::create('valuation_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('item_id')->index()->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->string('title');
            $table->text('url')->nullable();
            $table->unsignedInteger('price_cents')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->timestamp('captured_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('valuation_sources');
    }
};
