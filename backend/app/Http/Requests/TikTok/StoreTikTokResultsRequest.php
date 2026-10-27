<?php

namespace App\Http\Requests\TikTok;

class StoreTikTokResultsRequest extends TikTokFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'views' => ['required', 'integer', 'min:0'],
            'completion_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'follows' => ['required', 'integer', 'min:0'],
        ];
    }
}
