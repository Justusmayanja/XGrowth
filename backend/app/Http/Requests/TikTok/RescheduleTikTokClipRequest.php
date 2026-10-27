<?php

namespace App\Http\Requests\TikTok;

class RescheduleTikTokClipRequest extends TikTokFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'suggested_for' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'suggested_for.regex' => 'Send the new time as a date with a timezone offset.',
        ];
    }
}
