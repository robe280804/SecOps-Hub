<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $targetUser = $this->route('user');

        return $targetUser instanceof User
            && ($this->user()?->can('update', $targetUser) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $targetUser = $this->route('user');
        $canChangeRole = $targetUser instanceof User
            && ($this->user()?->hasRole(UserRole::Admin) ?? false)
            && ! $this->user()->is($targetUser);

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($targetUser),
            ],
            'password' => [
                'sometimes',
                'required',
                'string',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'role' => $canChangeRole
                ? ['sometimes', Rule::enum(UserRole::class)]
                : ['prohibited'],
        ];
    }
}
