<?php

namespace App\Http\Requests;

use App\Models\AdNotification;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdNotificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $adNotification = $this->route('adNotification');
        
        return $adNotification instanceof AdNotification 
            && $this->user()->id === $adNotification->user_id;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'filters' => ['sometimes', 'array'],
            'filters.*.filter_id' => ['required', 'integer'],
            'filters.*.label' => ['required', 'string'],
            'filters.*.filter_value_id' => ['nullable', 'integer'],
            'filters.*.value' => ['required'],
            'filters.*.value.from.value' => ['nullable', 'string'],
            'filters.*.value.from.filter_value_id' => ['nullable', 'integer'],
            'filters.*.value.to.value' => ['nullable', 'string'],
            'filters.*.value.to.filter_value_id' => ['nullable', 'integer'],
        ];
    }
}

