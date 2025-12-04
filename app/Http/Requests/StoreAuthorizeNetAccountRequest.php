<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAuthorizeNetAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // User must be authenticated and have a location assigned
        return $this->user() && $this->user()->location_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'api_login_id' => 'required|string|max:255',
            'transaction_key' => 'required|string|max:255',
            'environment' => 'required|in:sandbox,production',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_login_id.required' => 'API Login ID is required',
            'transaction_key.required' => 'Transaction Key is required',
            'environment.required' => 'Environment (sandbox or production) is required',
            'environment.in' => 'Environment must be either sandbox or production',
        ];
    }
}
