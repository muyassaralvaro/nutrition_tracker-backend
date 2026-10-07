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
        Schema::create('profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->date('birth_date');
            $table->decimal('height_cm', 6, 2);
            $table->string('sex', 10);
            $table->string('body_build', 20);
            $table->decimal('body_fat_percent', 4, 1)->nullable();
            $table->string('goal', 10);
            $table->decimal('goal_weight_kg', 6, 2)->nullable();
            $table->unsignedSmallInteger('activity_minutes');
            $table->string('activity_type', 20);
            $table->string('timezone', 64);
            $table->string('unit_system', 10)->default('metric');
            $table->string('avatar_path')->nullable();
            $table->timestamps();
        });

        Schema::create('weight_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->decimal('kg', 6, 2);
            $table->timestamps();
            $table->unique(['user_id', 'entry_date']);
        });

        Schema::create('nutrient_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('effective_on');
            $table->string('source', 10);
            $table->string('formula_version', 20)->nullable();
            $table->decimal('calories', 8, 2);
            $table->decimal('protein', 8, 2);
            $table->decimal('carbs', 8, 2);
            $table->decimal('fat', 8, 2);
            $table->decimal('fiber', 8, 2);
            $table->decimal('sodium', 10, 2);
            $table->decimal('potassium', 10, 2);
            $table->decimal('calcium', 10, 2);
            $table->decimal('iron', 8, 2);
            $table->timestamps();
            $table->unique(['user_id', 'effective_on']);
        });

        Schema::create('foods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('name_id', 160)->nullable();
            $table->string('source', 50);
            $table->string('source_id', 100);
            $table->string('source_url', 2048)->nullable();
            $table->decimal('calories', 8, 2);
            $table->decimal('protein', 8, 2);
            $table->decimal('carbs', 8, 2);
            $table->decimal('fat', 8, 2);
            $table->decimal('fiber', 8, 2)->nullable();
            $table->decimal('sodium', 10, 2)->nullable();
            $table->decimal('potassium', 10, 2)->nullable();
            $table->decimal('calcium', 10, 2)->nullable();
            $table->decimal('iron', 8, 2)->nullable();
            $table->timestamps();
            $table->unique(['source', 'source_id']);
            $table->index('name');
            $table->index('name_id');
        });

        Schema::create('meal_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12);
            $table->string('image_path')->nullable();
            $table->json('draft')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index('expires_at');
        });

        Schema::create('meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_request_id');
            $table->char('create_payload_sha256', 64);
            $table->date('meal_date');
            $table->time('meal_time');
            $table->string('title', 80);
            $table->string('source', 10);
            $table->timestamps();
            $table->unique(['user_id', 'client_request_id']);
            $table->index(['user_id', 'meal_date', 'meal_time']);
        });

        Schema::create('meal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->nullable()->constrained('foods')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 160);
            $table->decimal('grams', 8, 2)->nullable();
            $table->decimal('calories', 8, 2);
            $table->decimal('protein', 8, 2);
            $table->decimal('carbs', 8, 2);
            $table->decimal('fat', 8, 2);
            $table->decimal('fiber', 8, 2)->nullable();
            $table->decimal('sodium', 10, 2)->nullable();
            $table->decimal('potassium', 10, 2)->nullable();
            $table->decimal('calcium', 10, 2)->nullable();
            $table->decimal('iron', 8, 2)->nullable();
            $table->timestamps();
            $table->index(['meal_id', 'position']);
        });

        Schema::create('ai_usage_days', function (Blueprint $table) {
            $table->date('usage_date')->primary();
            $table->unsignedInteger('attempts')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage_days');
        Schema::dropIfExists('meal_items');
        Schema::dropIfExists('meals');
        Schema::dropIfExists('meal_analyses');
        Schema::dropIfExists('foods');
        Schema::dropIfExists('nutrient_targets');
        Schema::dropIfExists('weight_entries');
        Schema::dropIfExists('profiles');
    }
};
