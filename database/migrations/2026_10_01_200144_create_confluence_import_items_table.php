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
        Schema::create('confluence_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('confluence_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('attachment_id');
            $table->unsignedInteger('attachment_version');
            $table->string('original_filename');
            $table->unsignedBigInteger('size');
            $table->string('download_path', 2000);
            $table->dateTime('attachment_created_at');
            $table->string('stored_filename')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['attachment_id', 'attachment_version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('confluence_import_items');
    }
};
