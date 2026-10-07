<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('workouts');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ponytail: Prelaunch workout table had no rows; rollback needs no table restoration.
    }
};
