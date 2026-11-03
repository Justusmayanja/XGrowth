<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetup;
use App\Services\Storage\MediaStore;
use App\Services\TikTok\TikTokVideoPreferences;
use App\Support\TikTokVideoCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TikTokVideoSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $preferences = TikTokVideoPreferences::forUser($user);

        return response()->json([
            'data' => [
                'video' => $preferences->toArray(),
                'types' => TikTokVideoCatalog::types(),
                'music_available' => TikTokVideoPreferences::licensedMusic() !== [],
                'logo' => $this->hasLogo($user->id),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $setup = $this->setup($request);
        try {
            $preferences = TikTokVideoPreferences::validate($request->all());
        } catch (ValidationException $e) {
            return response()->json([
                'error' => [
                    'code' => 'validation',
                    'message' => collect($e->errors())->flatten()->first() ?: 'Check the video settings.',
                    'fields' => $e->errors(),
                ],
            ], 422);
        }

        $settings = is_array($setup->settings) ? $setup->settings : [];
        $settings['video'] = $preferences->toArray();
        $setup->settings = $settings;
        $setup->save();

        return response()->json(['data' => ['video' => $preferences->toArray()]]);
    }

    public function storeLogo(Request $request, MediaStore $media): JsonResponse
    {
        $this->setup($request);
        $file = $request->file('logo');
        if (! $file || ! $file->isValid()) {
            return $this->logoError('Choose a PNG, JPG, or WebP logo.');
        }
        if ($file->getSize() > TikTokVideoCatalog::LOGO_MAX_BYTES) {
            return $this->logoError('The logo needs to be under 2 MB.');
        }
        $info = @getimagesize($file->getPathname());
        if (! is_array($info) || ! in_array($info[2] ?? 0, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return $this->logoError('Choose a PNG, JPG, or WebP logo.');
        }
        if (($info[0] ?? 0) > TikTokVideoCatalog::LOGO_MAX_EDGE || ($info[1] ?? 0) > TikTokVideoCatalog::LOGO_MAX_EDGE) {
            return $this->logoError('The logo needs to be under 2000 pixels on each side.');
        }
        $image = match ($info[2]) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($file->getPathname()),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($file->getPathname()) : false,
            default => imagecreatefrompng($file->getPathname()),
        };
        if ($image === false) {
            return $this->logoError('Choose a PNG, JPG, or WebP logo.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'logo');
        imagepng($image, $tmp);
        imagedestroy($image);
        $stream = fopen($tmp, 'rb');
        try {
            $media->putStream($request->user()->id.'/brand/logo.png', $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($tmp);
        }

        return response()->json(['data' => ['logo' => true]]);
    }

    public function destroyLogo(Request $request, MediaStore $media): JsonResponse
    {
        $this->setup($request);
        $media->delete($request->user()->id.'/brand/logo.png');

        return response()->json(['data' => ['logo' => false]]);
    }

    private function setup(Request $request): PlatformSetup
    {
        $setup = PlatformSetup::query()
            ->where('user_id', $request->user()->id)
            ->where('platform', 'tiktok')
            ->whereNotNull('completed_at')
            ->first();
        if (! $setup) {
            abort(response()->json([
                'error' => [
                    'code' => 'setup_incomplete',
                    'message' => 'Finish TikTok setup first.',
                ],
            ], 422));
        }

        return $setup;
    }

    private function hasLogo(int $userId): bool
    {
        return app(MediaStore::class)->disk()->exists($userId.'/brand/logo.png');
    }

    private function logoError(string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'validation', 'message' => $message],
        ], 422);
    }
}
