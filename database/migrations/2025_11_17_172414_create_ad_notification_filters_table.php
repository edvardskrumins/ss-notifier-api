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
        Schema::create('ad_notification_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_notification_id')->constrained('ad_notifications')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('filter_id')->constrained('filters')->onDelete('cascade');
            $table->string('value'); // Hardcoded string value
            $table->foreignId('filter_value_id')->nullable()->constrained('filter_values')->onDelete('set null');
            $table->boolean('is_min')->nullable(); // true = min/from, false = max/to, null = single value
            $table->timestamps();

            $table->index('ad_notification_id');
            $table->index('user_id');
            $table->index('filter_id');
            $table->index('filter_value_id');
            $table->index(['ad_notification_id', 'filter_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_notification_filters');
    }
};
