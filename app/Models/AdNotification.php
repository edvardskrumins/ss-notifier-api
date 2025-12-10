<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\AdNotificationFilter;

class AdNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'name',
        'active',
        'last_ad_id',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Get the user that owns this notification.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the category being monitored.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withoutGlobalScope(\App\Models\Scopes\LocaleScope::class);
    }

    /**
     * Get the filters for this notification.
     */
    public function filters(): HasMany
    {
        return $this->hasMany(AdNotificationFilter::class);
    }

    /**
     * Scope a query to only include notifications for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Toggle the active status of the notification.
     */
    public function toggleActive(): bool
    {
        $this->active = !$this->active;
        return $this->save();
    }

    /**
     * Sync filters for this notification.
     * Deletes existing filters and creates new ones based on the provided filter data.
     *
     * @param array $filters Array of filter data arrays
     * @return void
     */
    public function syncFilters(array $filters): void
    {
        // Delete existing filters
        $this->filters()->delete();

        // Create new filters
        foreach ($filters as $filterData) {
            $this->createFilterFromData($filterData);
        }
    }

    /**
     * Create a filter from filter data.
     * Handles both single value and range filters.
     *
     * @param array $filterData Filter data array
     * @return void
     */
    protected function createFilterFromData(array $filterData): void
    {
        $filterId = $filterData['filter_id'];
        $value = $filterData['value'] ?? null;

        if (is_array($value) && (isset($value['from']) || isset($value['to']))) {
            if (isset($value['from']['value']) && $value['from']['value'] !== null && $value['from']['value'] !== '') {
                AdNotificationFilter::create([
                    'ad_notification_id' => $this->id,
                    'user_id' => $this->user_id,
                    'filter_id' => $filterId,
                    'value' => (string) $value['from']['value'],
                    'filter_value_id' => $value['from']['filter_value_id'] ?? null,
                    'is_min' => true,
                ]);
            }

            if (isset($value['to']['value']) && $value['to']['value'] !== null && $value['to']['value'] !== '') {
                AdNotificationFilter::create([
                    'ad_notification_id' => $this->id,
                    'user_id' => $this->user_id,
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
                    'ad_notification_id' => $this->id,
                    'user_id' => $this->user_id,
                    'filter_id' => $filterId,
                    'value' => $stringValue,
                    'filter_value_id' => $filterData['filter_value_id'] ?? null,
                    'is_min' => null,
                ]);
            }
        }
    }
}
