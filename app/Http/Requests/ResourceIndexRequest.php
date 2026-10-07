<?php

namespace App\Http\Requests;

use App\Support\Resources;
use Illuminate\Foundation\Http\FormRequest;

class ResourceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Resources::permission($this->route('resource'), 'view'));
    }

    public function rules(): array
    {
        return ['q' => 'nullable|string|max:150', 'active' => 'nullable|boolean', 'low_stock' => 'nullable|boolean', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'type' => 'nullable|in:MANUAL,AUTOMATIC'];
    }
}
