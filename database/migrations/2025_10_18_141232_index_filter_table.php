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
        Schema::table('filters', function (Blueprint $table) {
            $table->index(['label', 'category_id'], 'idx_filters_label_category');
        });

        Schema::table('filter_values', function (Blueprint $table) {
            $table->index(['filter_id', 'value'], 'idx_filter_values_filter_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('filters', function (Blueprint $table) {
            $table->dropIndex('idx_filters_label_category');
        });

        Schema::table('filter_values', function (Blueprint $table) {
            $table->dropIndex('idx_filter_values_filter_value');
        });
    }
};
