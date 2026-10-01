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
        Schema::table('gallery_image_descriptions', function (Blueprint $table) {
            $table->float('obstruction')->nullable()->after('scene_score');
            $table->string('obstruction_kind', 8)->nullable()->after('obstruction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gallery_image_descriptions', function (Blueprint $table) {
            $table->dropColumn(['obstruction', 'obstruction_kind']);
        });
    }
};
