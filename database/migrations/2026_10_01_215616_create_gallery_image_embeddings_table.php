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
        Schema::create('gallery_image_embeddings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('area_id');
            $table->unsignedInteger('ap_id');
            $table->string('visibility', 4);
            $table->string('filename');
            $table->string('model', 64);
            $table->binary('vector');
            $table->timestamps();

            $table->unique(['visibility', 'area_id', 'ap_id', 'filename']);
            $table->index(['area_id', 'ap_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gallery_image_embeddings');
    }
};
