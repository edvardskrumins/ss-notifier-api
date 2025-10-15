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
        Schema::create('filters', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->foreignId('category_id')->nullable()->constrained('categories');
            $table->enum('type', ['custom_range', 'select_range', 'custom_text', 'select', 'form_select']);
            $table->integer('max_length')->nullable();
            $table->string('form_param')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('filters');
    }
};
