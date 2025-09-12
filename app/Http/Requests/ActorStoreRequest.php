<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ActorStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'unique:actors,email'],
            'description' => ['required', 'string', 'unique:actors,description'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already used.',
            'description.unique' => 'This description has been submitted already.',
        ];
    }
}
