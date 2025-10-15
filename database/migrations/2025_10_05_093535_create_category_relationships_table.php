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
        Schema::create('category_relationships', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('parent_id');
            $table->bigInteger('child_id');
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('categories')->onDelete('cascade');
            $table->foreign('child_id')->references('id')->on('categories')->onDelete('cascade');

            $table->unique(['parent_id', 'child_id']);

            $table->index('parent_id');
            $table->index('child_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_relationships');
    }
};
