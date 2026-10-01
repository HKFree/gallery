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
        Schema::create('confluence_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('area_id');
            $table->unsignedInteger('ap_id');
            $table->string('visibility', 4);
            $table->unsignedBigInteger('page_id');
            $table->string('page_title');
            $table->unsignedInteger('page_version');
            $table->string('page_url', 2000);
            $table->string('status', 16)->default('queued');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['visibility', 'area_id', 'ap_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('confluence_imports');
    }
};
