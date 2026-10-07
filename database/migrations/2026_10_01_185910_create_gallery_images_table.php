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
        Schema::create('gallery_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('area_id');
            $table->unsignedInteger('ap_id');
            $table->string('visibility', 4);
            $table->string('filename');
            $table->dateTime('taken_at')->nullable();
            $table->dateTime('client_modified_at')->nullable();
            $table->dateTime('uploaded_at');
            $table->dateTime('sort_at');
            $table->char('sort_month', 7);
            $table->timestamps();

            $table->unique(['visibility', 'area_id', 'ap_id', 'filename']);
            $table->index(['visibility', 'area_id', 'ap_id', 'sort_at', 'id']);
            $table->index(['visibility', 'sort_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gallery_images');
    }
};
