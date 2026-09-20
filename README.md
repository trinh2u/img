# img

A small WordPress plugin that keeps uploaded images light.

**Img Formats** does four things on upload:

1. Content images are written as **AVIF** (main file and every sub-size).
2. When an image becomes a post's featured image it is rebuilt as **WebP**. Some social and messaging crawlers
   cannot read AVIF in `og:image`, WebP works everywhere.
3. Every generated AVIF/WebP file is re-encoded until it fits under a **size limit** (default 80 KB): a binary
   search on quality down to a floor, then 15% downscale steps down to a minimum width. Width, height and file size
   in the attachment metadata are updated.
4. Optionally (`IMGF_MAX_WIDTH`, `IMGF_ONLY_SIZES`) every image is reduced to two files: a main file and one
   thumbnail. Uploads become several times faster because WordPress no longer renders every registered size.

It also cooperates with the [Advanced Media Offloader](https://wordpress.org/plugins/advanced-media-offloader/)
plugin (S3-compatible storage such as Cloudflare R2): the large `original_image` copy is not uploaded, AVIF objects that
were replaced by WebP are deleted from the bucket, and a source file that no longer exists locally is fetched back
from the bucket's public domain when needed.

## Requirements

- WordPress 6.5+, PHP 8.0+
- Imagick that can **encode AVIF and WebP** (`Imagick::queryFormats()` must list `AVIF` and `WEBP`; with
  ImageMagick 6.9 this needs libheif 1.7+). If a format cannot be encoded the file is left untouched.

## Install

Copy the `img-formats` folder to `wp-content/plugins/` and activate it.

## Configuration

Everything is optional; add constants to `wp-config.php` above the line `/* That's all, stop editing! */`.

| Constant | Default | Meaning |
|---|---|---|
| `IMGF_MAX_KB` | `80` | Size limit per file in KB, `0` disables the cap |
| `IMGF_AVIF_QUALITY` / `IMGF_WEBP_QUALITY` | `60` / `80` | Starting quality |
| `IMGF_MIN_AVIF_QUALITY` / `IMGF_MIN_WEBP_QUALITY` | `35` / `55` | Quality floor before downscaling |
| `IMGF_MIN_WIDTH` | `480` | Never downscale below this width (px) |
| `IMGF_MAX_WIDTH` | `0` (off) | Downscale uploads wider than this (px) before anything else |
| `IMGF_ONLY_SIZES` | empty (all) | Comma list of sub-sizes to generate, e.g. `medium`. Missing sizes fall back to the closest existing one |
| `IMGF_KEEP_ORIGINAL` | off | Keep the `original_image` copy WordPress creates |
| `IMGF_DISABLED` | off | Switch the plugin off without removing it |

Example for a "main file + one thumbnail" setup:

```php
define( 'IMGF_MAX_WIDTH', 1200 );
define( 'IMGF_ONLY_SIZES', 'medium' );
```

## WP-CLI

```
wp imgf shrink [--id=<attachment id>] [--dry-run]
```

Applies the size cap to existing AVIF/WebP attachments whose files are stored locally.

## Notes

- Only new uploads (and images newly set as featured image) are processed.
- Re-encoding an already lossy file loses some quality; the floors above limit the damage. Raise `IMGF_MAX_KB`
  if 80 KB is too aggressive for large photos.

## License

GPL-2.0-or-later.
