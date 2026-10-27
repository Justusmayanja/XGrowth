<?php

namespace App\Http\Requests\TikTok;

use App\Support\TikTokCatalog;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class TikTokFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => TikTokCatalog::ERROR_VALIDATION,
                'message' => $validator->errors()->first(),
            ],
        ], 422));
    }
}
