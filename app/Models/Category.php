<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\Scopes\LocaleScope;

class Category extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new LocaleScope);
    }


    protected $fillable = [
        'title',
        'url',
        'type',
        'locale',
    ];

    public const TYPE_CATEGORY = 'category';
    public const TYPE_SUBCATEGORY = 'subcategory';
    public const TYPE_ADS = 'ads';

    public const LOCALE_LV = 'lv';
    public const LOCALE_EN = 'en';

    /**
     * Get the filters for this category.
     */
    public function filters(): HasMany
    {
        return $this->hasMany(Filter::class);
    }

    /**
     * Get the ad notifications for this category.
     */
    public function adNotifications(): HasMany
    {
        return $this->hasMany(AdNotification::class);
    }

    /**
     * Get all categories that are related to this one as children (via relationships table).
     * The global scope automatically filters by locale.
     */
    public function relatedChildren(): BelongsToMany
    {
        return $this->belongsToMany(
            Category::class,
            'category_relationships',
            'parent_id',
            'child_id'
        )->withTimestamps();
    }

    /**
     * Get all categories that are related to this one as parents (via relationships table).
     * The global scope automatically filters by locale.
     */
    public function relatedParents(): BelongsToMany
    {
        return $this->belongsToMany(
            Category::class,
            'category_relationships',
            'child_id',
            'parent_id'
        )->withTimestamps();
    }

    public function primaryParent(): ?Category
    {
        return $this->relatedParents()->first();
    }

    /**
     * Get all direct and related children 
     */
    public function allChildren()
    {
        $directChildren = $this->children;
        $relatedChildren = $this->relatedChildren;
        
        return $directChildren->merge($relatedChildren)->unique('id');
    }

    /**
     * Get all descendants recursively.
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get all direct children
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }


    /**
     * Scope a query to only include categories of a given type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to only include leaf categories (no children).
     */
    public function scopeLeaf($query)
    {
        return $query->whereDoesntHave('children');
    }

    /**
     * Get a query builder without the global scope.
     */
    public static function withoutLocaleScope()
    {
        return static::withoutGlobalScope(LocaleScope::class);
    }


    /**
     * Check if this category is a leaf category (has no children).
     */
    public function isLeaf(): bool
    {
        return $this->children()->count() === 0;
    }

    public function getFullPathAttribute(): string
    {
        $path = collect([$this->title]);
        $parent = $this->primaryParent();

        while ($parent) {
            $path->prepend($parent->title);
            $parent = $parent->primaryParent();
        }

        return $path->filter()->implode(' > ');
    }

    public function getBreadcrumbsAttribute(): array
    {
        $breadcrumbs = [];
        $current = $this;
        $seen = [];

        while ($current) {
            if (in_array($current->id, $seen, true)) {
                break;
            }

            $seen[] = $current->id;

            array_unshift($breadcrumbs, [
                'id' => $current->id,
                'title' => $current->title,
            ]);

            $current = $current->primaryParent();
        }

        return $breadcrumbs;
    }

    /**
     * Create a new ad notification with filters for this category.
     *
     * @param int $userId The user creating the notification
     * @param string $name The name of the notification
     * @param array $filters Array of filter selections with structure:
     *   - filter_id: int
     *   - label: string
     *   - value: string|array (string for single values, array with 'from'/'to' for ranges)
     *   - filter_value_id: int|null
     * @return AdNotification
     */
    public function createAdNotification(int $userId, string $name, array $filters = []): AdNotification
    {
        $adNotification = AdNotification::create([
            'user_id' => $userId,
            'category_id' => $this->id,
            'name' => $name,
        ]);

        foreach ($filters as $filterData) {
            $filterId = $filterData['filter_id'] ?? null;
            $value = $filterData['value'] ?? null;

            if (is_array($value) && (isset($value['from']) || isset($value['to']))) {
                if (isset($value['from']['value']) && $value['from']['value'] !== null && $value['from']['value'] !== '') {
                    AdNotificationFilter::create([
                        'ad_notification_id' => $adNotification->id,
                        'user_id' => $userId,
                        'filter_id' => $filterId,
                        'value' => (string) $value['from']['value'],
                        'filter_value_id' => $value['from']['filter_value_id'] ?? null,
                        'is_min' => true,
                    ]);
                }

                if (isset($value['to']['value']) && $value['to']['value'] !== null && $value['to']['value'] !== '') {
                    AdNotificationFilter::create([
                        'ad_notification_id' => $adNotification->id,
                        'user_id' => $userId,
                        'filter_id' => $filterId,
                        'value' => (string) $value['to']['value'],
                        'filter_value_id' => $value['to']['filter_value_id'] ?? null,
                        'is_min' => false,
                    ]);
                }
            } else {
                $stringValue = is_string($value) ? $value : (string) $value;
                
                if ($stringValue !== null && $stringValue !== '') {
                    AdNotificationFilter::create([
                        'ad_notification_id' => $adNotification->id,
                        'user_id' => $userId,
                        'filter_id' => $filterId, 
                        'value' => $stringValue,
                        'filter_value_id' => $filterData['filter_value_id'] ?? null,
                        'is_min' => null,
                    ]);
                }
            }
        }

        return $adNotification->load(['category', 'filters']);
    }
}
