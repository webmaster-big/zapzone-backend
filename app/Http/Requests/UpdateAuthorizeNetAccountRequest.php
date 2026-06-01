<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAuthorizeNetAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->location_id;
    }

    public function rules(): array
    {
        return [
            'api_login_id' => 'sometimes|required|string|max:255',
            'transaction_key' => 'sometimes|required|string|max:255',
            'environment' => 'sometimes|required|in:sandbox,production',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'api_login_id.required' => 'API Login ID is required',
            'transaction_key.required' => 'Transaction Key is required',
            'environment.required' => 'Environment (sandbox or production) is required',
            'environment.in' => 'Environment must be either sandbox or production',
            'is_active.boolean' => 'Status must be true or false',
        ];
    }
}
