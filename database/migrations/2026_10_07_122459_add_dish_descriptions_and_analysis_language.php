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
        Schema::table('foods', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('description', 200)->nullable();
            $table->decimal('serving_grams', 8, 2)->nullable();
        });

        Schema::table('meal_items', function (Blueprint $table) {
            $table->string('description', 200)->nullable();
        });

        Schema::table('meal_analyses', function (Blueprint $table) {
            $table->string('language', 2)->default('en');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meal_analyses', function (Blueprint $table) {
            $table->dropColumn('language');
        });

        Schema::table('meal_items', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('foods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['description', 'serving_grams']);
        });
    }
};
