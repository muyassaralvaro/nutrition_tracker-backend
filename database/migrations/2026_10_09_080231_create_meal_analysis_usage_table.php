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
        Schema::create('meal_analysis_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('root_id');
            $table->char('fingerprint', 64);
            $table->boolean('is_regeneration');
            $table->date('usage_date');
            $table->timestamp('created_at');
            $table->index(['user_id', 'usage_date', 'is_regeneration']);
            $table->index(['user_id', 'root_id', 'is_regeneration']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meal_analysis_usage');
    }
};
