<?php

namespace App\Policies;

use App\Models\AdNotification;
use App\Models\User;

class AdNotificationPolicy
{
    public function view(User $user, AdNotification $adNotification): bool
    {
        return $user->id === $adNotification->user_id;
    }

    public function update(User $user, AdNotification $adNotification): bool
    {
        return $user->id === $adNotification->user_id;
    }

    public function delete(User $user, AdNotification $adNotification): bool
    {
        return $user->id === $adNotification->user_id;
    }

    public function toggleActive(User $user, AdNotification $adNotification): bool
    {
        return $user->id === $adNotification->user_id;
    }
}

