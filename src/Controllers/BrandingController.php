<?php

namespace BBS\Controllers;

use BBS\Core\Controller;

/**
 * Serves dynamically-resized branding icons.
 *
 * The user uploads a single high-resolution app icon (recommended 512×512
 * transparent PNG) on the Branding settings page; it's stored as base64 in
 * the `branding_app_icon` setting. This controller serves resized variants
 * for every favicon/touch-icon size on demand from that one source, so the
 * user doesn't have to upload N versions.
 *
 * If the user hasn't set a custom icon, we serve the corresponding bundled
 * default from /public/images/favicon-{N}x{N}.png — same artwork BBS ships
 * with — so the response surface is consistent regardless of branding.
 */
class BrandingController extends Controller
{
    /** Sizes we'll generate for. Matches every <link rel="icon"> in the layouts. */
    private const ALLOWED_SIZES = [16, 32, 48, 96, 180, 192, 512];

    public function icon(int $size): void
    {
        if (!in_array($size, self::ALLOWED_SIZES, true)) {
            http_response_code(404);
            return;
        }

        $row = $this->db->fetchOne(
            "SELECT `value` FROM settings WHERE `key` = 'branding_app_icon'"
        );

        // No custom branding → serve the bundled default at this size. The
        // bundled files cover every size we list above.
        if (empty($row['value'])) {
            $this->serveBundled($size);
            return;
        }

        $source = base64_decode($row['value'], true);
        if ($source === false || !str_starts_with($source, "\x89PNG")) {
            $this->serveBundled($size);
            return;
        }

        // Cache the resized output on disk so we don't re-decode + resize on
        // every request. Key includes the source hash so the cache busts
        // automatically when the user uploads a new icon.
        $cacheDir = '/var/bbs/cache/branding';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $hash = substr(sha1($row['value']), 0, 12);
        $cacheFile = "{$cacheDir}/icon-{$hash}-{$size}.png";

        if (!is_file($cacheFile) || !is_writable(dirname($cacheFile))) {
            $resized = $this->resizePng($source, $size);
            if ($resized === null) {
                // GD failure — fall back to bundled rather than 500
                $this->serveBundled($size);
                return;
            }
            // Best-effort cache write; ignore failures and serve the bytes
            // anyway. A read-only filesystem just means we resize each
            // time, which is still fine for these tiny images.
            @file_put_contents($cacheFile, $resized);
            $this->emitPng($resized);
            return;
        }

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        readfile($cacheFile);
    }

    private function serveBundled(int $size): void
    {
        $path = dirname(__DIR__, 2) . "/public/images/favicon-{$size}x{$size}.png";
        if (!is_file($path)) {
            http_response_code(404);
            return;
        }
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        readfile($path);
    }

    private function emitPng(string $bytes): void
    {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
    }

    /**
     * Resize a PNG to a square at the target side length, preserving alpha.
     * Returns the encoded PNG bytes, or null on GD failure.
     */
    private function resizePng(string $sourceBytes, int $size): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring($sourceBytes);
        if ($src === false) {
            return null;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);

        $dst = imagecreatetruecolor($size, $size);
        // Preserve transparency through the resize
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $size, $size, $transparent);

        // Letterbox if the source isn't square so we never distort the
        // artwork — fit longest edge into the target.
        $scale = $size / max($sw, $sh);
        $tw = (int) round($sw * $scale);
        $th = (int) round($sh * $scale);
        $tx = (int) round(($size - $tw) / 2);
        $ty = (int) round(($size - $th) / 2);

        imagecopyresampled($dst, $src, $tx, $ty, 0, 0, $tw, $th, $sw, $sh);

        ob_start();
        imagepng($dst, null, 9);
        $bytes = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $bytes !== false ? $bytes : null;
    }
}
