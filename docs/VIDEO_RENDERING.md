# TikTok motion video

Mackie17 can make a vertical motion video for a waiting or queued clip. Confirm is still the only consent. The file is not sent to TikTok until the user confirms, the file is ready, and `video.upload` is granted. There is no Direct Post and no new TikTok scope.

## Store

One private disk, `media`, on this server at `storage/app/private/media`. The disk name `tiktok_videos` is the same store with the prefix `tiktok`, so upload and draft code keep their current disk name.

Object keys inside the TikTok namespace:

- `{user_id}/{clip_id}/source.mp4`
- `{user_id}/{clip_id}/preview.mp4`
- `{user_id}/{clip_id}/poster.jpg`
- `{user_id}/brand/logo.png`

Intermediates stay in `storage/app/tiktok-work/{clip}-{random}/` and `storage/app/tiktok-staging/{clip}/`. A sweep removes work directories older than 6 hours. Listing is per user prefix. Nothing scans the whole bucket.

`php artisan tiktok:storage-check` writes and deletes a canary. `php artisan tiktok:migrate-videos` copies legacy local files onto that disk and leaves the source in place.

## When a render starts

`generate` defaults to `ahead`. After `run()` commits, the engine queues a render. That covers the hourly engine, `tiktok:run`, setup completion, and the next clip after results. `on_confirm` queues after `confirm()` commits. A failure to queue does not fail `run()` or `confirm()`, and it does not change the clip status.

The render is skipped, with a plain note, when the user is blocked, the type is not motion, the language is not English, or the speech budget cannot be reserved. A user-uploaded file is left alone. `POST /api/tiktok/clips/{clip}/render` can make a clip again at most twice.

`GET /api/dashboard/platform/tiktok` does not start a render. The preview URL is a separate request: a 30-minute signed route for the file on this server.

## What the renderer does

`SceneBuilder` turns the script into 3 to 7 scenes, about 20 or 35 seconds, never over 60. `AssBuilder` writes one caption file per scene. Timing follows character weight. It is not aligned to spoken words. Safe margins are 90 left and right, 200 at the top, and 480 at the bottom.

`TIKTOK_VIDEO_PROVIDER` chooses the picture. `motion` (the default) draws the frames on this server and then speaks with `TIKTOK_TTS_DRIVER`. `gemini` asks Gemini Omni (`GEMINI_VIDEO_MODEL`, default `gemini-omni-1.2-flash`) for one vertical clip with its own soundtrack. The request is a background Interactions API call, then the render polls that interaction until the file is ready. Laravel reads `GEMINI_API_KEY` from `backend/.env`. ffmpeg then scales that file to 9:16, burns the caption file, and writes the preview and poster. A missing key or a failed call fails the render with "We couldn't make the video. Try again, or add your own." When Gemini reports that its video quota is used up, the desk says "The video service is out of capacity right now. Try again later, or add your own video." There is no silent switch back to motion. An unknown provider fails the same way. Omni clips are requested at 15 seconds, the longest clip that model accepts. The in-app reserve is a placeholder of 80 mills. The Gemini invoice is the source of truth. The prompt tells the model to keep letters out of the picture. Captions are burned afterwards.

Speech uses `TIKTOK_TTS_DRIVER`. `fake` writes a sine tone with ffmpeg and does not record model usage. `openai` calls the model in `TIKTOK_TTS_MODEL` (default `gpt-4o-mini-tts`; confirm the id if OpenAI renames it). `elevenlabs` calls ElevenLabs with `ELEVENLABS_MODEL` (default `eleven_multilingual_v2`) and the female or male voice id. Spend is reserved before the call. ElevenLabs reserves its own per-character rate; the in-app number is an estimate and the ElevenLabs invoice is the source of truth. A failed call releases the reserve and fails the render with "Voice isn't available right now. Try again later, or add your own video." There is no silent switch to another voice. An unknown driver fails the same way.

`TIKTOK_RENDER_SYNC=true` renders inside the request that creates the clip (`tiktok:run`, `POST /api/tiktok/run`, and setup completion). That request stays open for the whole encode, often a couple of minutes. Opening the dashboard still starts nothing. The flag is ignored when `APP_ENV=production`, and a warning is logged. Production keeps the Redis worker in `docs/QUEUE_WORKER.md`. Set `TIKTOK_FFMPEG` and `TIKTOK_FFPROBE` to the full path when those binaries are not on `PATH`. `/dev/tiktok-states` shows layout only and does not play a file this switch makes.

`FfmpegRunner` is the only process caller. Arguments are an array, with `-nostdin` and `-threads 2`. Account text is written into the sanitized caption file. It is not an ffmpeg argument. The finished file must pass the same probe as an upload: MP4, H.264, AAC, under 100 MB, under 10 minutes, 9:16. If our own file fails that probe, the render fails and `ready` stays false.

Fonts are Inter and Source Sans 3, SIL Open Font License, under `backend/resources/video/fonts`. They are not downloaded at runtime. `resources/video/music` is empty, so music stays off.

## AI label on send

`package.render.ai_generated` and `package.publish.ai_generated` are set when a generated file succeeds. The inbox init request this app sends is only `source_info` (`source`, `video_size`, `chunk_size`, `total_chunk_count`). That body has no `post_info` and no `is_aigc` field. Direct Post is a different endpoint and is not used. The send payload is unchanged.

## Desk

While a clip is waiting, the desk shows the phone, the timeline, and the confirm button. The script stays behind Show full script. Shots, sound, on-screen notes, the count strip, goals, and sample numbers stay off that view. While a video is being made, the TikTok desk shows a 9:16 placeholder and polls the render route every 5 seconds while the tab is visible, for at most 10 minutes. A ready video says nothing is sent until confirm, and that it was made with Mackie17. A failure offers another try or the user's own file. A skip explains why and links to Settings.

Settings has a Video card for TikTok. Cartoon, Real person, and Stock are shown as coming soon and are rejected if sent as the selected type's availability on the server: only motion is available. Saving the card is explicit.

## Operations

Admin TikTok operations show counts by render status and warn when a render has been queued for more than 10 minutes. Account lookup includes the render status, the note, and a side-effect-free explanation from the same guards the renderer uses.
