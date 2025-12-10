<?php

namespace App\Http\Requests;

use App\Models\AdNotification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ToggleAdNotificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $adNotification = $this->route('adNotification');
        return $adNotification instanceof AdNotification 
            && Gate::allows('toggleActive', $adNotification);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [];
    }
}

