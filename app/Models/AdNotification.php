<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the filters for this notification.
     */
    public function filters(): HasMany
    {
        return $this->hasMany(AdNotificationFilter::class);
    }
}
