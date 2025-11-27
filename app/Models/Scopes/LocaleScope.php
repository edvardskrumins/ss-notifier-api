<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use App\Models\Category;

class LocaleScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     * Automatically filters categories by locale from the request.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $locale = request()->attributes->get('locale', Category::LOCALE_LV);
        
        $builder->where('locale', $locale);
    }
}
