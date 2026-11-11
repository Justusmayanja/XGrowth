# Phase 10: TikTok video UI components

You are a senior front-end engineer with strong product-design taste, working in the Mackie17 repo (Next.js 15, React 19, TypeScript, Tailwind, shadcn-style UI in `apps/web`). The backend for generated motion-graphics video is done and verified (render states, preview endpoint, video settings API, logo upload, regenerate, upload-your-own). Your job is to make the **video experience look and feel like a modern, polished product**. This is a frontend task. Backend changes are allowed only where section 2 says so.

## 0. Prerequisites

1. Run the stack, log in, and capture **before** screenshots at 360, 768, 1280 of: `/dashboard/tiktok` in each render state (use `/dev/tiktok-states`), and Settings → TikTok Video card. Light and dark.
2. Read `components/dashboard/tiktok/*` (ClipCard, VideoPanel, QueuedPanel, upload dropzone), the Settings TikTok Video card, `lib/tiktok.ts`, `lib/nav.ts` and `components/shell/*` if the Phase 8 shell exists (if it does not, say so and work with the current shell).
3. Check installed primitives and packages. **Use what exists. No new dependencies.** If a primitive is missing and its Radix package is installed, build it shadcn-style. Otherwise build a minimal accessible version and tell me.
4. **Verify the preview player in a real browser.** A `<video>` element cannot send a bearer token. If the local-driver route `GET /api/tiktok/clips/{clip}/video/preview/file` requires bearer auth, the player gets a 401. Test it. If broken, fix it on the backend with a short-lived **signed route** (Laravel `URL::temporarySignedRoute`, 30 minutes, scoped to the clip and owner, Range support kept), returned as `preview_url` by the existing preview endpoint, so the frontend treats both drivers identically. Add tests. This is the only backend change allowed.

## 1. Hard boundaries

- No behavior changes. Confirm, regenerate, upload-your-own, polling, and all API contracts stay as they are.
- Keep every honesty-critical string already in the app, including "Nothing is sent to TikTok until you confirm." and "Made with Mackie17. Check it before you post." Never write "Posted", "Scheduled", "Uploaded", or "Live" for anything that is not. If a copy change is needed, tell me.
- No fake controls, no placeholder charts, no dead buttons. Cartoon, Real person, and Stock stay disabled with a "Coming soon" badge. Music stays disabled with the existing explanation.
- No new dependencies, fonts, or palette colours. Reuse design tokens.
- Do not put the preview URL, storage keys, or the raw package in any client-visible persistent state (no localStorage, no URL).
- Do not change admin screens, X, or YouTube desks.
- If the repo contradicts this brief, stop and ask.

## 2. Components to build or refine

Put them in `components/dashboard/tiktok/video/` (and `components/settings/tiktok/` for settings). Small, presentational, typed props, strings as constants at the top of each file.

### Desk

**`PhonePreview`**: 9:16 frame with rounded corners and a subtle device-style border. Holds the poster and player. `preload="none"`, native controls, `playsInline`, `aria-label` with the clip hook. Shows file size ("About 4 MB to watch") and duration as small chips. On error (expired URL, 403), refresh the URL once, then show a retry state. Optional **"Show safe area"** toggle that overlays the TikTok UI zones (sides 90px, top 200px, bottom 480px, scaled to the frame) so the user sees where TikTok's buttons will cover the video. Default off. Overlay is `aria-hidden`, and the toggle is a real switch with a label.

**`RenderStatusCard`**: one component for all render states, with icon and text, never colour alone.
- `queued` / `rendering`: 9:16 skeleton in the same footprint as the final player (no layout shift), a calm indeterminate progress, "Making your video…", elapsed time after 30s ("Usually under 2 minutes" only if the backend reports a typical time; otherwise omit), `aria-busy`, polite live region. Poll every 5s with backoff, only while the tab is visible, stop after 10 minutes with "This is taking longer than usual. We'll keep working on it."
- `ready`: renders `PhonePreview` plus the action row.
- `failed`: alert with the note, **Try again** (shows remaining regenerations) and the existing upload dropzone.
- `skipped`: a short reason, link to Video settings, and the dropzone.
- Regenerate limit reached: disabled button with the plain explanation and the upload fallback.

**`VideoActionRow`**: **Make it again** (secondary, shows "1 left"), **Use my own video instead** (ghost). Visually separated from the confirm button. The confirm bar is not changed.

**`ClipCard` refinements**: sections with small muted uppercase labels (Hook, Script, Caption, On screen, Shots, Sound). Script as numbered lines with "Show full script" collapse. Caption in an inset block with hashtags as chips. Copy icon buttons with tooltips and a "Copied" state. A small "AI-made" chip near the video (icon plus text, `ai_generated` from the resource) with the existing disclosure copy.

**`ClipTimeline`**: a compact, non-interactive stepper showing the clip's real journey: Written → Video ready → Confirmed → In your TikTok drafts → Posted → Results in. Current step emphasised with icon plus text, future steps muted, skipped steps (for example Video ready when the user uploaded their own) shown as "Your video". `role="list"`, scrollable with snap at 360px. It replaces nothing. It sits inside the clip card header.

**`UploadDropzone`** polish: dashed drop area, clear accepted-format line ("MP4 or MOV, up to 100 MB and 10 minutes"), per-chunk progress bar with percent and bytes, cancel, success summary (name, length, size, ratio warning as a calm notice), and inline errors that say what to do. Keyboard-operable file picker.

### Settings (TikTok Video card)

Split the long card into tabs or accordion sections on one page (match the existing Settings pattern): **Style**, **Voice and captions**, **Brand**, **Business details**.

- **`VideoTypePicker`**: radio-card group. Motion graphics selected by default with a tiny looping-free thumbnail illustration drawn with CSS/SVG (no images fetched). Cartoon, Real person, Stock disabled with "Coming soon". Proper `radiogroup` semantics, arrow-key navigation.
- **`VoiceCaptionControls`**: voice style segmented control (Female/Male), language fixed to English with the note "More languages are coming", captions switch plus style choice (Bold/Clean) with a **live caption sample** rendered in HTML/CSS that mimics the chosen style on the brand background. Length segmented control (About 20s / About 35s). Generate timing radio cards ("Make it ahead" / "Make it when I confirm") with one-line explanations.
- **`BrandKit`**: logo uploader with preview on light and dark tiles, replace and remove, file rules in helper text. Colour inputs (hex plus native colour picker) with a **live contrast badge** (pass/fail text, ratio, icon) for the computed caption colour. Font choice as two sample cards. Outro text with a character counter. A small **live scene preview** (9:16 mini card in CSS) showing a statement scene with the chosen colours, font and logo placement and an outro scene. It is an approximation, so label it "Preview of the look".
- **`BusinessDetails`**: each item is a switch row, all off by default, with the field revealing under it. Helper text states exactly what appears: "Only what you type here appears in the video." Location, business name, website, phone, each with validation messages from the API.
- Autosave or explicit Save to match the existing pattern. Show a quiet "Saved" status with `aria-live`, a sticky Save bar on mobile if explicit save, unsaved-changes warning on navigation, and inline field errors from the API.

## 3. Visual standards

- One spacing scale. Cards: 1px border, same radius and surface as the rest of the app. Labels small and muted above larger values.
- Type scale consistent. Numbers use `tabular-nums`. No orphan single-word wraps in headings (`text-balance`).
- Loading uses skeletons that match final sizes. Subtle motion only (150 to 200 ms), all disabled under `prefers-reduced-motion`.
- Contrast 4.5:1 body and 3:1 UI, in light and dark. Visible `focus-visible` rings. 44px touch targets on coarse pointers. State is never colour alone.
- Works at 360px with no horizontal scroll. The phone preview is capped (about 280 to 320px wide) and centred on mobile, placed beside the script on `lg+`.
- Empty, loading, error, and success states for every component.

## 4. Fixtures and verification

- Update `/dev/tiktok-states` so each component state is reviewable: rendering (short and long), ready (with and without safe-area overlay), failed, skipped, regenerate limit reached, user-uploaded video, upload in progress, upload error, settings sections with validation errors, brand kit with a failing contrast pair, business details on and off.
- Run `tsc --noEmit`, the linter if one exists, and `next build` (report the known Google Fonts timeout if it recurs).
- Keyboard-only pass: picker radios, switches, upload picker, preview controls, regenerate, safe-area toggle.
- Screen-reader basics: live regions announce render state changes once, not every poll.
- Test the player against the signed preview route.
- Throttle to "Fast 3G" and report time to poster and time to first frame.

## 5. Acceptance criteria

- [ ] The preview plays in a real browser on the local driver (signed route) with no 401, and Range seeking works.
- [ ] Every render state has a distinct, polished, accessible UI with no layout shift.
- [ ] Settings Video card is organised into clear sections with live caption sample, brand preview, and contrast feedback.
- [ ] All honesty-critical copy unchanged. No fake controls. Coming-soon types disabled.
- [ ] No new dependencies. No behavior or contract changes beyond the signed preview route if needed.
- [ ] 360 / 768 / 1280, light and dark, keyboard and reduced-motion verified, with before and after screenshots.

## 6. Report back with

1. Files created or changed.
2. Whether the preview 401 was real, and how it was fixed.
3. Before and after screenshots for every state listed in section 4.
4. Primitives you had to build.
5. Differences between this brief and the repo, and what you chose.
6. Type-check, build, and keyboard-test results, and anything out of scope that looks wrong.

If you hit ambiguity at any step, stop and ask instead of inventing a convention.