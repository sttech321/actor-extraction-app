<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PromptValidationRequest extends FormRequest
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
            'description' => ['required', 'string', 'max:20000'],
        ];
    }

    public function messages()
    {
        return [
            'description.required' => 'Please provide a description to validate.',
            'description.string' => 'Invalid description format.',
        ];
    }
}
