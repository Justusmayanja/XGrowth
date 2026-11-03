# Wasabi storage

Mackie17 keeps private media on one disk. TikTok files are the `tiktok/` prefix. Another feature adds its own prefix on the same disk. It does not get its own bucket.

## Bucket

1. In the Wasabi console, create a bucket in `eu-central-1`.
2. Turn versioning off.
3. Leave the bucket private. Do not add a public ACL or a public bucket policy.
4. Create a sub-user whose policy allows `s3:GetObject`, `s3:PutObject`, `s3:DeleteObject`, and `s3:ListBucket` on this bucket only.
5. Put the keys in the API `.env`:

```
MEDIA_DRIVER=s3
WASABI_KEY=
WASABI_SECRET=
WASABI_BUCKET=
WASABI_REGION=eu-central-1
WASABI_ENDPOINT=https://s3.eu-central-1.wasabisys.com
```

The endpoint `https://s3.eu-central-1.wasabisys.com` matches Wasabi's published eu-central-1 service URL (verified against Wasabi's region list, 2026). `use_path_style_endpoint` is on.

## CORS

Allow `GET` and `HEAD` from the web origin, and allow the `Range` header, so the desk can play a signed preview.

## Local files

`MEDIA_DRIVER=local` stores files under `storage/app/private/media`. Tests and a machine without Wasabi keys stay on this driver. `tiktok:migrate-videos --from=local --to=s3` copies the legacy `TIKTOK_VIDEO_ROOT` tree after the checksum matches. It does not delete the source.

## Check

`php artisan tiktok:storage-check` writes a canary, reads it, requests a signed URL when the driver is S3, deletes the canary, and prints the time for each step. A failed step exits non-zero.

## Terms to confirm before production

- Minimum storage duration. Verify the current Wasabi policy.
- Egress fair-use. Verify the current Wasabi policy.
- Render workers are fastest in the EU, close to this bucket.
