<?php

namespace App\Services\Content;

/**
 * A business poster from the account itself.
 * The name and the offer come from the description.
 * A phone, email, site, or handle is used only when it is already written there.
 */
class BusinessPoster
{
    /**
     * @return array{name: string, offer: string, contact: string}
     */
    public static function from(string $description, string $niche = ''): array
    {
        $raw = trim((string) preg_replace('/\s+/', ' ', $description));
        $contact = self::contact($raw);
        $body = $contact === '' ? $raw : trim(str_ireplace($contact, '', $raw));
        $body = trim((string) preg_replace('/\s+/', ' ', $body), " \t,;|-");
        $name = self::name($body, $niche);
        $offer = self::offer($body, $name);
        if ($offer === '') {
            $offer = $body;
        }

        return [
            'name' => $name,
            'offer' => mb_substr($offer, 0, 180),
            'contact' => $contact,
        ];
    }

    public static function contact(string $text): string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $match) === 1) {
            return $match[0];
        }
        if (preg_match('/(?:\+?\d[\d\s().-]{7,}\d)/', $text, $match) === 1) {
            return trim($match[0]);
        }
        if (preg_match('/(?:https?:\/\/|www\.)\S+/i', $text, $match) === 1) {
            return rtrim($match[0], '.,)');
        }
        if (preg_match('/(?<![\w])@[A-Za-z0-9_]{2,}/', $text, $match) === 1) {
            return $match[0];
        }

        return '';
    }

    /**
     * A contact from the model is kept only when the description already contains it.
     */
    public static function keptContact(string $candidate, string $description): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return '';
        }

        $found = self::contact($description);
        if ($found === '') {
            return '';
        }

        $needle = preg_replace('/\s+/', '', mb_strtolower($candidate)) ?? '';
        $haystack = preg_replace('/\s+/', '', mb_strtolower($description)) ?? '';
        if ($needle !== '' && str_contains($haystack, $needle)) {
            return $candidate;
        }

        return $found;
    }

    private static function name(string $body, string $niche): string
    {
        if (preg_match('/^(.{2,40}?)\s+(?:is|are|helps|offers|lends|provides)\b/iu', $body, $match) === 1) {
            return trim($match[1]);
        }

        $niche = trim($niche);
        if ($niche !== '' && mb_strtolower($niche) !== 'general') {
            return $niche;
        }

        $words = array_values(array_filter(preg_split('/\s+/', $body) ?: [], fn (string $word) => $word !== ''));

        return implode(' ', array_slice($words, 0, 2));
    }

    private static function offer(string $body, string $name): string
    {
        $offer = trim($body);
        if ($name !== '' && str_starts_with(mb_strtolower($offer), mb_strtolower($name))) {
            $offer = trim(mb_substr($offer, mb_strlen($name)));
            $offer = trim((string) preg_replace('/^(is|are|helps|offers|lends|provides)\b/iu', '', $offer));
        }

        $sentence = preg_split('/(?<=[.!])\s+/u', $offer) ?: [];

        return trim((string) ($sentence[0] ?? $offer));
    }
}
