<?php

namespace App\Support;

/**
 * Compares images by what they look like, not by their bytes.
 * Needs PHP's GD extension; every method returns null when it can't read the image.
 */
final class ImageFingerprint
{
    /** Images bigger than this many pixels are skipped, to protect memory. */
    private const MAX_PIXELS = 24_000_000;

    /** Width of the small copy both images are compared on. */
    private const SAMPLE_WIDTH = 160;

    /** A pixel counts as changed when any colour channel moved by more than this (out of 255). */
    private const NOTICEABLE = 28;

    /** @return array{width: int, height: int}|null */
    public static function dimensions(string $bytes): ?array
    {
        if (!function_exists('getimagesizefromstring')) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);

        return $info ? ['width' => (int) $info[0], 'height' => (int) $info[1]] : null;
    }

    /**
     * Roughly what share of the picture changed, as a percentage (0 to 100), measured on
     * a small copy of both images. Returns null when the two can't be compared fairly:
     * unreadable, too large, or a different shape (aspect ratio), e.g. after a re-crop.
     * Re-compression, metadata changes and plain resizing score about 0.
     */
    public static function changedArea(string $old, string $new): ?float
    {
        $a = self::dimensions($old);
        $b = self::dimensions($new);

        if (!$a || !$b || !function_exists('imagecreatefromstring')) {
            return null;
        }

        if ($a['width'] * $a['height'] > self::MAX_PIXELS || $b['width'] * $b['height'] > self::MAX_PIXELS) {
            return null;
        }

        $ratioA = $a['width'] / $a['height'];
        $ratioB = $b['width'] / $b['height'];

        if (abs($ratioA - $ratioB) > 0.02 * $ratioA) {
            return null;
        }

        $w = self::SAMPLE_WIDTH;
        $h = max(1, (int) round($w * $a['height'] / $a['width']));

        $x = self::sample($old, $a, $w, $h);
        $y = self::sample($new, $b, $w, $h);

        if (!$x || !$y) {
            return null;
        }

        $changed = 0;

        for ($py = 0; $py < $h; $py++) {
            for ($px = 0; $px < $w; $px++) {
                $p = imagecolorat($x, $px, $py);
                $q = imagecolorat($y, $px, $py);

                $delta = max(
                    abs((($p >> 16) & 0xFF) - (($q >> 16) & 0xFF)),
                    abs((($p >> 8) & 0xFF) - (($q >> 8) & 0xFF)),
                    abs(($p & 0xFF) - ($q & 0xFF)),
                );

                if ($delta > self::NOTICEABLE) {
                    $changed++;
                }
            }
        }

        return round($changed / ($w * $h) * 100, 2);
    }

    private static function sample(string $bytes, array $dims, int $w, int $h): ?\GdImage
    {
        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return null;
        }

        $small = imagecreatetruecolor($w, $h);
        imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255)); // transparency becomes white
        imagecopyresampled($small, $source, 0, 0, 0, 0, $w, $h, $dims['width'], $dims['height']);

        return $small;
    }
}