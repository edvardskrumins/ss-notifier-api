<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FilterValue extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'filter_id',
        'label',
        'value',
    ];

    /**
     * Get the filter that owns the filter value.
     */
    public function filter(): BelongsTo
    {
        return $this->belongsTo(Filter::class);
    }

  
}
