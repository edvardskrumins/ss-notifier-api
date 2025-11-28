<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'filters' => ['sometimes', 'array'],
            'filters.*.filter_id' => ['nullable', 'integer'],
            'filters.*.label' => ['required', 'string'],
            'filters.*.form_param' => ['nullable', 'string'],
            'filters.*.filter_value_id' => ['nullable', 'integer'],
            'filters.*.value' => ['required'],
            'filters.*.value.from.value' => ['nullable', 'string'],
            'filters.*.value.from.filter_value_id' => ['nullable', 'integer'],
            'filters.*.value.to.value' => ['nullable', 'string'],
            'filters.*.value.to.filter_value_id' => ['nullable', 'integer'],
        ];
    }
}

