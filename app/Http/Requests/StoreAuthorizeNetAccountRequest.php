<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAuthorizeNetAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->location_id;
    }

    public function rules(): array
    {
        return [
            'api_login_id' => 'required|string|max:255',
            'transaction_key' => 'required|string|max:255',
            'environment' => 'required|in:sandbox,production',
        ];
    }

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
