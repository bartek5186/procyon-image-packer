<p align="center">
  <img src="./proycon-image-packer.png" alt="Procyon Image Packer" width="360" />
</p>

# Procyon Image Packer

Batch image optimizer for WordPress attachments using an external shell runner.

The plugin does not try to rebuild a custom responsive image system from scratch. It uses WordPress attachment metadata, existing sub-sizes and optional frontend URL rewriting for generated `webp` / `avif` files.

## What This Plugin Does

- scans Media Library attachments for supported source formats: `image/jpeg` and `image/png`,
- optionally repairs missing WordPress sub-sizes before processing,
- builds a manifest of physical files to process from attachment originals and generated sub-sizes,
- launches a shell batch outside WordPress for heavy work,
- optimizes originals with `jpegoptim` and `pngquant`,
- generates sibling `webp` files with `cwebp`,
- optionally generates sibling `avif` files with `avifenc`,
- tracks progress, processed count, failures and current file,
- exposes job control through admin UI, REST API and WP-CLI,
- optionally rewrites attachment URLs and `srcset` candidates to `avif` / `webp` on the frontend.

## Requirements

- WordPress with Media Library attachments,
- PHP 7.4+,
- server allows background shell execution from PHP via `exec`, `shell_exec` or `popen`,
- writable uploads directory,
- Linux tooling depending on enabled features:
  - `jpegoptim` for JPEG original optimization,
  - `pngquant` for PNG original optimization,
  - `cwebp` for WebP generation,
  - `avifenc` for AVIF generation.

Suggested install commands used by the plugin UI:

```bash
sudo apt update && sudo apt install jpegoptim
sudo apt update && sudo apt install pngquant
sudo apt update && sudo apt install webp
sudo apt update && sudo apt install libavif-bin
```

## Quick Start

1. Activate the plugin.
2. Open **Media -> Procyon Image Packer**.
3. Check detected binaries and install anything missing for the options you want to enable.
4. Configure:
   - original optimization,
   - WebP generation,
   - AVIF generation,
   - frontend rewriting,
   - missing sub-size repair,
   - automatic dirty queue for new uploads.
5. Start a full batch.
6. Monitor progress in the admin page or via REST / WP-CLI.

## Admin Page

`Media -> Procyon Image Packer`

The admin view shows:

- current job status,
- progress percent,
- processed files count,
- success / failure counts,
- current file path,
- scanned attachment count,
- queued attachment count,
- missing server tools with install commands,
- controls for `Start`, `Pause`, `Resume` and manual refresh.

## Processing Flow

### New uploads

The plugin hooks into `wp_generate_attachment_metadata`.

For supported input types it:

- marks the attachment as dirty,
- optionally schedules a delayed dirty-run batch,
- keeps WordPress metadata and sub-size logic as the source of truth.

### Full or dirty batch

When a batch starts, the plugin:

1. reads plugin settings,
2. validates required binaries for enabled features,
3. queries supported attachments from WordPress,
4. optionally repairs missing image sub-sizes with core APIs,
5. builds `manifest.tsv` for files that are not current yet,
6. launches `bin/process-images.sh` in the background,
7. reads runtime files to report live progress,
8. syncs completed results back into plugin registry and attachment meta.

### Shell runner

The shell runner processes physical files one by one and writes state files during execution.

Per file it can:

- optimize the original,
- create sibling `.webp`,
- create sibling `.avif`,
- append success or failure records,
- stop cleanly when pause is requested,
- continue from the remaining manifest entries after resume.

## Output Files

The plugin creates sidecar files next to the original image files, for example:

```text
photo.jpg
photo-300x200.jpg
photo.webp
photo-300x200.webp
photo.avif
photo-300x200.avif
```

## State Files

Batch state is stored in:

```text
wp-content/uploads/procyon-image-packer/
```

Important files:

- `job.json` current job metadata,
- `job.env` exported shell environment for the runner,
- `manifest.tsv` queue of files to process,
- `runtime.status` live runtime counters,
- `done.tsv` successful file records,
- `failed.tsv` failed file records,
- `registry.tsv` known processed file signatures,
- `job.log` shell output log,
- `pause.flag` pause request marker,
- `runner.lock` active runner lock.

## Frontend Behavior

The plugin does not build a custom `srcset` database.

Instead it relies on WordPress attachment rendering:

- `wp_get_attachment_url()`,
- `wp_get_attachment_image_src()`,
- `wp_calculate_image_srcset()`.

If frontend rewriting is enabled, the plugin:

- prefers `avif` when browser `Accept` supports it and a sibling `.avif` exists,
- otherwise prefers `webp` when browser `Accept` supports it and a sibling `.webp` exists,
- otherwise falls back to the original file URL.

## REST API

Namespace: `procyon-image-packer/v1`

All endpoints require a user with `manage_options`.

### Status

- `GET /wp-json/procyon-image-packer/v1/status`

Returns:

- job metadata,
- progress counters,
- current settings,
- detected binaries,
- environment errors and warnings,
- UI capability flags such as `can_start`, `can_pause`, `can_resume`.

### Start

- `POST /wp-json/procyon-image-packer/v1/start`

Body params:

- `mode` = `full` or `dirty`

### Pause

- `POST /wp-json/procyon-image-packer/v1/pause`

### Resume

- `POST /wp-json/procyon-image-packer/v1/resume`

## WP-CLI

```bash
wp procyon image-packer status
wp procyon image-packer start --mode=full
wp procyon image-packer start --mode=dirty
wp procyon image-packer pause
wp procyon image-packer resume
```

## Notes

- Input support is intentionally limited to JPEG and PNG for now.
- The batch will not start if a required binary for enabled features is missing.
- The plugin prefers WordPress core image metadata and sub-size APIs over custom size logic.
- The shell runner processes files outside WordPress, but the queue is attachment-driven, not a blind raw filesystem crawl.
- When an attachment is deleted from Media Library, the plugin also removes sibling `.webp` / `.avif` files and its registry entries.

## License

This project is open source and licensed under **GNU GPL v2 or later** (`GPL-2.0-or-later`).
