<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Category;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->enum('locale', [Category::LOCALE_LV, Category::LOCALE_EN])
                ->default(Category::LOCALE_LV);
            $table->index('locale');
            $table->index(['locale', 'url']);
        });

        // Drop the old unique constraint on url and create a new one with locale
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['url']);
            $table->unique(['locale', 'url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['locale', 'url']);
            $table->unique('url');
            $table->dropIndex(['locale']);
            $table->dropIndex(['locale', 'url']);
            $table->dropColumn('locale');
        });
    }
};
