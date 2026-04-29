<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Enums\AlbumLocation;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'location' => ['nullable', 'string', Rule::in(AlbumLocation::values())],
            'avatar' => ['nullable', 'image', 'max:5120'], // 5MB max
        ];

        if ($this->user()?->role === 'admin') {
            $rules['role'] = ['required', 'string', Rule::in(['admin', 'manager', 'member'])];
        }

        return $rules;
    }
}
