<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShowAdNotificationRequest;
use App\Http\Requests\ToggleAdNotificationRequest;
use App\Http\Requests\UpdateAdNotificationRequest;
use App\Http\Resources\AdNotificationResource;
use App\Models\AdNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdNotificationController extends Controller
{
    /**
     * Get all ad notifications for the authenticated user.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        $notifications = AdNotification::forUser($user->id)
            ->with(['category', 'filters.filter', 'filters.filterValue'])
            ->orderBy('created_at', 'desc')
            ->get();

        return AdNotificationResource::collection($notifications);
    }

    /**
     * Get a single ad notification with all its data.
     */
    public function show(ShowAdNotificationRequest $request, AdNotification $adNotification)
    {
        $adNotification->load(['category', 'filters.filter', 'filters.filterValue']);

        return new AdNotificationResource($adNotification);
    }

    /**
     * Toggle the active status of an ad notification.
     */
    public function toggleActive(ToggleAdNotificationRequest $request, AdNotification $adNotification)
    {
        Log::info('ToggleActive', ['adNotification' => $adNotification]);
        $adNotification->toggleActive();

        return new AdNotificationResource($adNotification->load(['category', 'filters.filter', 'filters.filterValue']));
    }

    /**
     * Update an ad notification.
     */
    public function update(UpdateAdNotificationRequest $request, AdNotification $adNotification)
    {
        $validated = $request->validated();

        // Update the notification name
        $adNotification->name = $validated['name'];
        $adNotification->save();

        // Sync filters
        $adNotification->syncFilters($validated['filters'] ?? []);

        return new AdNotificationResource($adNotification->load(['category', 'filters.filter', 'filters.filterValue']));
    }
}

