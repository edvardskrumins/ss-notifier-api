<?php

namespace App\Policies;

use App\Models\AdNotification;
use App\Models\User;

class AdNotificationPolicy
{
    /**
     * Determine if the user can view the notification.
     */
    public function view(User $user, AdNotification $adNotification): bool
    {
        return $user->id === $adNotification->user_id;
    }

    /**
     * Determine if the user can update the notification.
     */
    public function update(User $user, AdNotification $adNotification): bool
    {
        return $user->id === $adNotification->user_id;
    }

    /**
     * Determine if the user can delete the notification.
     */
    public function delete(User $user, AdNotification $adNotification): bool
    {
        return $user->id === $adNotification->user_id;
    }

    /**
     * Determine if the user can toggle the active status of the notification.
     */
    public function toggleActive(User $user, AdNotification $adNotification): bool
    {
        return $user->id === $adNotification->user_id;
    }
}

