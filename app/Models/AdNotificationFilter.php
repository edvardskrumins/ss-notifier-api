<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdNotificationFilter extends Model
{
    use HasFactory;

    protected $fillable = [
        'ad_notification_id',
        'user_id',
        'filter_id',
        'value',
        'filter_value_id',
        'is_min',
    ];

    /**
     * Get the ad notification this filter belongs to.
     */
    public function adNotification(): BelongsTo
    {
        return $this->belongsTo(AdNotification::class);
    }

    /**
     * Get the user that owns this filter.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the filter definition.
     */
    public function filter(): BelongsTo
    {
        return $this->belongsTo(Filter::class);
    }

    /**
     * Get the filter value (if this was selected from a dropdown).
     */
    public function filterValue(): BelongsTo
    {
        return $this->belongsTo(FilterValue::class);
    }
}
