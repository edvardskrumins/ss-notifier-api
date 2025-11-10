<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'url',
        'type',
    ];

    public const TYPE_CATEGORY = 'category';
    public const TYPE_SUBCATEGORY = 'subcategory';
    public const TYPE_ADS = 'ads';

    /**
     * Get the filters for this category.
     */
    public function filters(): HasMany
    {
        return $this->hasMany(Filter::class);
    }

    /**
     * Get all categories that are related to this one as children (via relationships table).
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
     * Get all direct and related children (primary + cross-references).
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
}
