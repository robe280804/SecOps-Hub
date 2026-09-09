<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'type' => ['required', Rule::enum(ProjectType::class)],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)->only([ProjectStatus::Inactive, ProjectStatus::Active])],
            'user_id' => ['missing'],
        ];
    }
}
