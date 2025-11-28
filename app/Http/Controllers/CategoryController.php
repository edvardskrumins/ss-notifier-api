<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartNotificationRequest;
use App\Http\Resources\AdNotificationResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\FilterResource;
use App\Models\Category;
use Illuminate\Support\Facades\Log;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::ofType(Category::TYPE_CATEGORY)->get();

        return CategoryResource::collection($categories);
    }

    public function subcategories(Category $category)
    {
        $subcategories = $category->relatedChildren()->get();

        return CategoryResource::collection($subcategories)->additional([
            'meta' => [
                'breadcrumbs' => $category->breadcrumbs,
                'category' => new CategoryResource($category),
            ],
        ]);
    }

    public function ads($categoryId)
    {
        $category = Category::withoutLocaleScope()->findOrFail($categoryId);
        $category->load(['filters.values']);

        return FilterResource::collection($category->filters)->additional([
            'meta' => [
                'breadcrumbs' => $category->breadcrumbs,
                'category' => new CategoryResource($category),
            ],
        ]);
    }

    public function startNotifications(StartNotificationRequest $request, $categoryId)
    {
        $category = Category::withoutLocaleScope()->findOrFail($categoryId);
        
        $validated = $request->validated();
        $user = $request->user();

        $adNotification = $category->createAdNotification(
            $user->id,
            $validated['name'],
            $validated['filters'] ?? []
        );

        return new AdNotificationResource($adNotification);
    }
}
