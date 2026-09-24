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
        Schema::create('app_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('frames_processed')->default(0);
            $table->unsignedInteger('items_identified')->default(0);
            $table->unsignedInteger('searches_performed')->default(0);
            $table->unsignedInteger('model_calls')->default(0);
            $table->timestamp('last_updated')->nullable();
        });

        DB::table('app_stats')->insert(['id' => 1]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_stats');
    }
};
