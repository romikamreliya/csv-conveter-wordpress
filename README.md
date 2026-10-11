# WebP Converter & Compressor

A WordPress + WooCommerce plugin that converts JPG/PNG images to **WebP**, compresses them while keeping them sharp, and **replaces the image URLs across the whole site**, so pages load the new `.webp` files right away.

You can convert the whole Media Library, or only the images of **one WooCommerce product**.

![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b) ![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4) ![WooCommerce](https://img.shields.io/badge/WooCommerce-optional-96588a)

---

## Features

- **JPG / PNG → WebP**: converts the main image and every thumbnail size WordPress generated.
- **Smart compression**: quality 80 by default, best compression method, and Sharp-YUV for crisp colour edges. Large camera photos (2 MB+, 4000px+) are scaled down to 2560px. Typical savings are **50–96%** with no visible quality loss.
- **Keeps colour profiles (ICC)**: iPhone and Adobe RGB photos keep their correct colours (cwebp and Imagick engines).
- **Live URL replacement**: old `.jpg` / `.png` URLs are replaced in:
  - post and product content and excerpts
  - post meta (Elementor JSON, ACF, Rank Math, page builders…)
  - term meta
  - options (theme mods, widgets, Customizer, WooCommerce email header…)
  - Yoast SEO indexables (og:image, twitter:image)

  Serialized data is unserialized, updated and re-serialized safely.
- **Single product mode**: converts only one product's images (featured, gallery, variation images, and images inside the description).
- **Bulk mode**: converts the whole library, or only WooCommerce product images, one image per request with a progress bar and pause/resume. It never hits PHP time limits.
- **One-click restore**: originals are kept in `uploads/webpc-backup/`, and restore puts back the files, the database records and every URL.
- **Auto-convert on upload** (optional).
- **Safety net for old URLs**: requests for an old `.jpg`/`.png` that no longer exists are redirected to the `.webp`. This covers page caches, CDNs, Google Images, social shares and hard-coded theme CSS.
- **Cache aware**: purges WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, SiteGround Optimizer, Autoptimize and Breeze, plus the Elementor CSS cache.
- **Multilingual safe**: WPML / Polylang media translations that share the same file are updated (and restored) together.
- **Works with WooCommerce HPOS**.

---

## Requirements

| | |
|---|---|
| WordPress | 5.8 or newer |
| PHP | 7.4 or newer |
| WooCommerce | Optional. Needed only for the product features |
| WebP engine | **At least one** of the engines below |

### WebP engines

In **Auto** mode the plugin uses the first available engine, in this order. If an engine fails on an image, the next one is tried automatically.

| Engine | Quality | Notes |
|---|---|---|
| **cwebp** (Google libwebp binary) | ★★★ Best | Needs the PHP `exec()` function and the `cwebp` binary on the server |
| **Imagick** | ★★★ Excellent | PHP Imagick extension with WebP support. Available on most hosts |
| **GD** | ★★ Good | PHP GD compiled with WebP. Drops ICC colour profiles and uses a slightly simpler resize |

**Settings → Engine** shows which engines your server has.

---

## Installation

### Option 1: upload the ZIP

1. Download this repository as a ZIP. The folder inside must be named `webp-conveter`.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the ZIP, then **Install** and **Activate**.

### Option 2: git clone

```bash
cd wp-content/plugins
git clone https://github.com/<your-username>/webp-conveter.git webp-conveter
```

Then activate **WebP Converter & Compressor** under **Plugins**.

> **Before the first bulk run on a live site, take a full backup (files + database).** Test on one product first.

---

## Usage

Everything is under the **WebP Converter** menu in the admin sidebar.

### 1. Convert one product's images

Choose any of these:

- **WebP Converter → Single Product**: search for a product and click **Convert this product's images**.
- **Products list**: hover a product and click **Convert images to WebP**.
- **Products list, bulk action**: tick several products, then choose **Convert images to WebP → Apply**.
- **Edit Product screen**: use the **WebP Images** box in the sidebar.

### 2. Convert the whole library

**WebP Converter → Bulk Convert** → choose *All media images* or *Only WooCommerce product images* → **Start Conversion**. You can pause and resume at any time.

### 3. Convert or restore a single image

- **Media → Library (list view)**: use the **WebP** column.
- **Grid view / media modal**: use the **WebP** field in the attachment details.

### Reading the results

```
✔ #5073 IMG_3748 — Main image: 698.0 KB → 345.0 KB (−51%) · All 13 sizes: 4.3 MB → 1.4 MB (−68%)
```

| Part | Meaning |
|---|---|
| `#5073 IMG_3748` | Attachment ID and title |
| **Main image** | The file WordPress shows as "File size", which is the one used on the site |
| **All N sizes** | Main image + original upload + every thumbnail: the total disk space saved |

JPGs that were already compressed save less (around 25–60%). PNG photos usually save 90% or more.

---

## Settings

| Setting | Default | Description |
|---|---|---|
| WebP quality | `80` | 30–100. **75–82** is the recommended range |
| Max dimensions | `2560 × 2560` | Larger main/original images are scaled down to fit. `0` = never resize. Thumbnails are never resized |
| PNG mode | Lossy | *Lossy* gives the smallest files and keeps transparency. *Lossless* is pixel-perfect, for logos and text |
| Engine | Auto | Auto, cwebp, Imagick or GD |
| Custom cwebp path | – | Full path to the `cwebp` binary. Only a file named `cwebp` / `cwebp.exe` is accepted |
| Replace live image paths | On | Rewrites old URLs in the database |
| Keep original backup | On | Moves originals to `uploads/webpc-backup/`. **Required for Restore** |
| Auto-convert new uploads | Off | Converts every new JPG/PNG upload |
| Redirect old URLs | On | Redirects missing `.jpg`/`.png` upload URLs to the `.webp` (302) |

---

## How it works

For each image, the plugin:

1. Creates a `.webp` next to every file (main, original, thumbnails). The originals are not touched yet.
2. Updates the attachment: file path, metadata, MIME type (`image/webp`) and GUID. Media translations that share the file are updated too.
3. Replaces the old URLs in the database (see *Features*) and purges the related caches.
4. **Only then** moves the originals to `uploads/webpc-backup/<year>/<month>/`, or deletes them if backups are off.

Because of this order, a crash or timeout in the middle never leaves a broken image.

If an image is busy (for example two admins clicking at the same time), a per-image lock prevents double conversion.

### MIME type for `.webp`

Some older Apache builds don't send `Content-Type: image/webp`. If the site also sends `X-Content-Type-Options: nosniff`, browsers then refuse to show the image.

On Apache and LiteSpeed the plugin adds this rule to `uploads/.htaccess`:

```apache
# BEGIN WebP Converter
<IfModule mod_mime.c>
AddType image/webp .webp
</IfModule>
# END WebP Converter
```

The rule is only written when WordPress' own rewrite rules already work in `.htaccess`, so it can never cause a 500 error.

---

## Installing cwebp (optional)

Imagick is usually enough. Use cwebp only if you want Google's reference encoder.

**VPS / dedicated server (root access):**

```bash
sudo apt install webp            # Ubuntu / Debian
sudo dnf install libwebp-tools   # AlmaLinux / Rocky / CentOS
```

The plugin finds `/usr/bin/cwebp` and `/usr/local/bin/cwebp` automatically.

**Shared hosting (Hostinger, cPanel…), via SSH:**

```bash
mkdir -p ~/bin && cd ~/bin
wget https://storage.googleapis.com/downloads.webmproject.org/releases/webp/libwebp-1.5.0-linux-x86-64.tar.gz
tar xzf libwebp-1.5.0-linux-x86-64.tar.gz
cp libwebp-1.5.0-linux-x86-64/bin/cwebp ~/bin/cwebp
chmod 755 ~/bin/cwebp
~/bin/cwebp -version
```

Then put the full path (for example `/home/u123456789/bin/cwebp`) in **Settings → Custom cwebp path**.

- If the download link returns 404, a newer version has been released. Pick the latest `linux-x86-64` file from the [libwebp releases page](https://storage.googleapis.com/downloads.webmproject.org/releases/webp/index.html).
- If cwebp still shows as *not available*, your host has disabled PHP `exec()`. Ask them to enable it, or use Imagick.

---

## After converting

- Purge your **CDN cache** (Cloudflare, Hostinger CDN…). WordPress cache plugins are purged automatically.
- If you use **LiteSpeed Cache**, keep its *Image Optimization → WebP* feature **off**, so two plugins don't do the same job.

---

## Limitations

- **Offloaded media** (WP Offload Media / S3): files that are not on the local disk are skipped with an error message. Nothing breaks.
- **Nginx**: the old-URL redirect only works when missing upload files are routed to WordPress. That is the default on Apache and LiteSpeed, but Nginx usually serves a plain 404 instead. URLs stored in the database are still replaced.
- **Hard-coded URLs in theme PHP or CSS files** are not edited. The old-URL redirect covers them on Apache/LiteSpeed.
- **GIF, SVG and AVIF** are not converted. Only JPG and PNG are.
- Very old browsers without WebP support (Safari 13 and older, IE) are not served a fallback.

---

## For developers

### Filters

```php
// Capability for the plugin page, bulk/product conversion and settings.
// Default: manage_woocommerce (when WooCommerce is active), otherwise manage_options.
add_filter( 'webpc_capability', fn() => 'manage_options' );

// Capability for converting/restoring a single image from the Media Library
// (an edit_post check per image is always applied too). Default: upload_files.
add_filter( 'webpc_media_capability', fn() => 'upload_files' );

// HTTP status for the old-URL redirect. Default: 302.
add_filter( 'webpc_redirect_status', fn() => 301 );
```

### Actions

```php
// After an image has been converted.
add_action( 'webpc_converted', function ( $attachment_id, $stats ) {
	// $stats: main_orig, main_new, orig, new, files, engine, replaced, time
}, 10, 2 );

// After an image has been restored.
add_action( 'webpc_restored', function ( $attachment_id ) {} );

// Hook your own cache purge.
add_action( 'webpc_purge_post', function ( $post_id ) {} ); // one post changed
add_action( 'webpc_purge_all', function () {} );            // end of a bulk/product run
```

### Data stored

| Key | Where | Purpose |
|---|---|---|
| `webpc_settings` | option | Plugin settings |
| `_webpc_stats` | attachment meta | Sizes before/after, engine, time |
| `_webpc_backup` | attachment meta | Everything needed to restore (old file, metadata, MIME, GUID, file map) |
| `_webpc_lock` | attachment meta | Temporary per-image lock |
| `uploads/webpc-backup/` | disk | Original files |

### File structure

```
webp-conveter/
├── webp-conveter.php                  # Bootstrap, activation, .htaccess MIME rule
├── includes/
│   ├── class-webpc-settings.php       # Defaults + sanitising
│   ├── class-webpc-converter.php      # cwebp / Imagick / GD engines
│   ├── class-webpc-attachment.php     # Convert, restore, upload hook, old-URL redirect
│   ├── class-webpc-replacer.php       # Database URL replacement + cache purging
│   └── class-webpc-admin.php          # Admin page, AJAX, media column, product screens
└── assets/
    ├── admin.js
    └── admin.css
```

---

## Uninstall / deactivate

- **Deactivating** keeps converted images as WebP. They are normal files and keep working.
- To go back to the originals, **Restore** the images (individually) **before** removing the plugin.
- The backup folder `uploads/webpc-backup/` is not deleted automatically. Remove it yourself once you are happy with the results.

---

## License

GPL-2.0-or-later, the same license as WordPress.

## Author

**AppWorld**
