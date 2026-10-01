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
        Schema::create('gallery_image_descriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('area_id');
            $table->unsignedInteger('ap_id');
            $table->string('visibility', 4);
            $table->string('filename');
            $table->decimal('origin_lat', 9, 6)->nullable();
            $table->decimal('origin_lon', 9, 6)->nullable();
            $table->unsignedSmallInteger('heading')->nullable();
            $table->string('heading_source', 16)->nullable();
            $table->string('scene', 16)->nullable();
            $table->float('scene_score')->nullable();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['visibility', 'area_id', 'ap_id', 'filename']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gallery_image_descriptions');
    }
};
