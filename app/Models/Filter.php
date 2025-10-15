<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Filter extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'label',
        'category_id',
        'type',
        'max_length',
        'form_param',
    ];

    /**
     * Filter type constants
     */
    public const TYPE_CUSTOM_RANGE = 'custom_range';
    public const TYPE_SELECT_RANGE = 'select_range';
    public const TYPE_CUSTOM_TEXT = 'custom_text';
    public const TYPE_SELECT = 'select';
    public const TYPE_FORM_SELECT = 'form_select';

    /**
     * Get all available filter types
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_CUSTOM_RANGE,
            self::TYPE_SELECT_RANGE,
            self::TYPE_CUSTOM_TEXT,
            self::TYPE_SELECT,
            self::TYPE_FORM_SELECT,
        ];
    }

    /**
     * Get the category that owns the filter.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }


    /**
     * Get the filter values for this filter.
     */
    public function values(): HasMany
    {
        return $this->hasMany(FilterValue::class);
    }

    /**
     * Scope a query to only include global filters.
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('category_id');
    }
}
