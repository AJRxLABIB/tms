<?php
/**
 * MapImages — validation, safe storage and bookkeeping for administrator-uploaded place photographs.
 *
 * Rules: only JPEG, PNG or WebP up to 5 MB; the real MIME type is read from the file bytes (never from the browser);
 * every image is decoded and re-encoded with GD, which drops EXIF/GPS data and anything that is not pixels; names are
 * random; alt text, a credit and a licence are required, so the site never shows an image it has no right to show.
 * Files live in uploads/places/ (an .htaccess there denies script execution). The pure validators have no GD or DB
 * dependency so they can be unit-tested.
 */
class MapImages
{
    public const MAX_BYTES  = 5242880;      // 5 MB
    public const MIN_SIDE   = 200;
    public const MAX_SIDE   = 8000;
    public const MAX_PIXELS = 40000000;     // decompression-bomb guard
    public const LARGE_SIDE = 1280;
    public const THUMB_SIDE = 480;
    public const DIR        = 'uploads/places';

    /** @var array<string,string> real MIME => extension used for detection only */
    public const MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const LICENSES = [
        'own'        => 'Own photograph (the organisation owns it)',
        'cc-by'      => 'Creative Commons Attribution (CC BY)',
        'cc0'        => 'Public domain / CC0',
        'permission' => 'Used with the owner\'s written permission',
        'other'      => 'Other licence (explain in credit)',
    ];

    // ───────────────────────── pure validators ─────────────────────────

    /** @return array{ok:bool,error?:string} */
    public static function validateFileInfo(int $errorCode, int $size, ?string $mime, ?array $imageSize): array
    {
        if ($errorCode !== 0) {
            $m = [1 => 'The file is larger than the server allows.', 2 => 'The file is too large.', 3 => 'The upload was interrupted. Try again.',
                  4 => 'Choose an image file.', 6 => 'The server has no temporary folder.', 7 => 'The server could not save the file.'];
            return ['ok' => false, 'error' => $m[$errorCode] ?? 'The upload failed.'];
        }
        if ($size <= 0) return ['ok' => false, 'error' => 'The file is empty.'];
        if ($size > self::MAX_BYTES) return ['ok' => false, 'error' => 'The image is larger than 5 MB.'];
        if ($mime === null || !isset(self::MIME[$mime])) return ['ok' => false, 'error' => 'Only JPEG, PNG or WebP images are accepted.'];
        if (!$imageSize || count($imageSize) < 2) return ['ok' => false, 'error' => 'The file is not a readable image.'];
        [$w, $h] = [(int)$imageSize[0], (int)$imageSize[1]];
        if ($w < self::MIN_SIDE || $h < self::MIN_SIDE) return ['ok' => false, 'error' => 'The image is too small (minimum ' . self::MIN_SIDE . ' px on each side).'];
        if ($w > self::MAX_SIDE || $h > self::MAX_SIDE || $w * $h > self::MAX_PIXELS) return ['ok' => false, 'error' => 'The image dimensions are too large.'];
        return ['ok' => true];
    }

    /**
     * Required metadata: alt text, credit and licence. Source URL is optional but must be http(s) when given.
     * @return array{ok:bool,errors:array<string,string>,data:array<string,?string>}
     */
    public static function validateMeta(array $in): array
    {
        $err = []; $d = [];
        $alt = trim((string)($in['alt'] ?? ''));
        $alt = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $alt) ?? '';
        if (mb_strlen($alt, 'UTF-8') < 3) $err['alt'] = 'Describe the photo for screen-reader users (at least 3 characters).';
        elseif (mb_strlen($alt, 'UTF-8') > 200) $err['alt'] = 'Alt text is too long (max 200).';
        $d['alt'] = $alt;
        $credit = trim((string)($in['credit'] ?? ''));
        if (mb_strlen($credit, 'UTF-8') < 2) $err['credit'] = 'Photographer or owner credit is required.';
        elseif (mb_strlen($credit, 'UTF-8') > 200) $err['credit'] = 'Credit is too long (max 200).';
        $d['credit'] = $credit;
        $lic = strtolower(trim((string)($in['license'] ?? '')));
        if (!isset(self::LICENSES[$lic])) $err['license'] = 'Choose the licence under which this photo may be shown.';
        $d['license'] = $lic;
        $src = trim((string)($in['source_url'] ?? ''));
        if ($src === '') $d['source_url'] = null;
        elseif (!preg_match('#^https?://[^\s<>"]{3,240}$#i', $src)) $err['source_url'] = 'Source link must start with http:// or https://.';
        else $d['source_url'] = $src;
        return ['ok' => !$err, 'errors' => $err, 'data' => $d];
    }

    public static function randomBase(): string { return bin2hex(random_bytes(12)); }

    /** Scale (w,h) so the longest side is at most $max. Never enlarges. @return array{0:int,1:int} */
    public static function fitSize(int $w, int $h, int $max): array
    {
        if ($w <= 0 || $h <= 0) return [1, 1];
        $scale = min(1.0, $max / max($w, $h));
        return [max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale))];
    }

    /** Is $relPath a file this class created (inside uploads/places, no traversal)? */
    public static function isOwnPath(string $relPath): bool
    {
        return (bool)preg_match('#^' . preg_quote(self::DIR, '#') . '/[a-f0-9]{24}-(l|t)\.(jpg|webp)$#', $relPath);
    }

    // ───────────────────────── processing (needs GD) ─────────────────────────

    public static function gdAvailable(): bool { return function_exists('imagecreatefromstring') && function_exists('imagejpeg'); }

    public static function detectMime(string $path): ?string
    {
        if (!function_exists('finfo_open')) return null;
        $f = finfo_open(FILEINFO_MIME_TYPE);
        if (!$f) return null;
        $m = finfo_file($f, $path);
        finfo_close($f);
        return is_string($m) ? $m : null;
    }

    private static function root(): string { return dirname(__DIR__, 2); }

    /**
     * Validate and store an uploaded file. $file is a $_FILES entry.
     * @return array{ok:bool,error?:string,file?:string,thumb?:string}
     */
    public static function storeUpload(array $file): array
    {
        if (!self::gdAvailable()) return ['ok' => false, 'error' => 'Image processing (PHP GD extension) is not enabled on this server.'];
        $tmp = (string)($file['tmp_name'] ?? '');
        $err = (int)($file['error'] ?? 4);
        if ($err === 0 && !is_uploaded_file($tmp)) return ['ok' => false, 'error' => 'The upload could not be verified.'];
        $size = $err === 0 ? (int)filesize($tmp) : 0;
        $mime = $err === 0 ? self::detectMime($tmp) : null;
        $info = $err === 0 ? @getimagesize($tmp) : null;
        $v = self::validateFileInfo($err, $size, $mime, $info ?: null);
        if (!$v['ok']) return $v;
        $bytes = @file_get_contents($tmp);
        $src = $bytes === false ? false : @imagecreatefromstring($bytes);
        unset($bytes);
        if (!$src) return ['ok' => false, 'error' => 'The image could not be decoded.'];
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $ex = @exif_read_data($tmp);
            $o = (int)($ex['Orientation'] ?? 1);
            $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($rot !== 0 && function_exists('imagerotate')) { $r = imagerotate($src, $rot, 0); if ($r) { imagedestroy($src); $src = $r; } }
        }
        $dir = self::root() . '/' . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { imagedestroy($src); return ['ok' => false, 'error' => 'The uploads/places folder cannot be created.']; }
        $base = self::randomBase();
        $webp = function_exists('imagewebp');
        $ext = $webp ? 'webp' : 'jpg';
        $out = [];
        foreach (['l' => self::LARGE_SIDE, 't' => self::THUMB_SIDE] as $suffix => $max) {
            [$w, $h] = self::fitSize(imagesx($src), imagesy($src), $max);
            $dst = imagecreatetruecolor($w, $h);
            imagealphablending($dst, true);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // flatten transparency on white
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
            $path = $dir . '/' . $base . '-' . $suffix . '.' . $ext;
            $ok = $webp ? @imagewebp($dst, $path, 82) : @imagejpeg($dst, $path, 85);
            imagedestroy($dst);
            if (!$ok) { imagedestroy($src); foreach ($out as $p) @unlink(self::root() . '/' . $p); return ['ok' => false, 'error' => 'The processed image could not be saved.']; }
            @chmod($path, 0644);
            $out[$suffix] = self::DIR . '/' . $base . '-' . $suffix . '.' . $ext;
        }
        imagedestroy($src);
        return ['ok' => true, 'file' => $out['l'], 'thumb' => $out['t']];
    }

    // ───────────────────────── bookkeeping ─────────────────────────

    /** @return list<array<string,mixed>> */
    public static function listFor(PDO $db, int $placeId): array
    {
        $st = $db->prepare('SELECT ImageId, File, Thumb, Alt, Credit, License, SourceUrl, Sort, IsPrimary, Status FROM tbl_map_place_images WHERE PlaceId = ? ORDER BY IsPrimary DESC, Sort ASC, ImageId ASC');
        $st->execute([$placeId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function setPrimary(PDO $db, int $placeId, int $imageId): bool
    {
        $own = !$db->inTransaction();
        try {
            if ($own) $db->beginTransaction();
            $chk = $db->prepare('SELECT ImageId FROM tbl_map_place_images WHERE ImageId = ? AND PlaceId = ?');
            $chk->execute([$imageId, $placeId]);
            if (!$chk->fetchColumn()) { if ($own) $db->rollBack(); return false; }
            $db->prepare('UPDATE tbl_map_place_images SET IsPrimary = 0 WHERE PlaceId = ?')->execute([$placeId]);
            $db->prepare('UPDATE tbl_map_place_images SET IsPrimary = 1 WHERE ImageId = ?')->execute([$imageId]);
            if ($own) $db->commit();
            return true;
        } catch (Throwable $e) { if ($own && $db->inTransaction()) $db->rollBack(); error_log('[MapImages] setPrimary: ' . $e->getMessage()); return false; }
    }

    /** @param list<int> $orderedIds image ids in the desired order (ids not belonging to the place are ignored) */
    public static function reorder(PDO $db, int $placeId, array $orderedIds): bool
    {
        $up = $db->prepare('UPDATE tbl_map_place_images SET Sort = ? WHERE ImageId = ? AND PlaceId = ?');
        $i = 1;
        foreach ($orderedIds as $id) $up->execute([$i++, (int)$id, $placeId]);
        return true;
    }

    /** Delete the row and the files (only files this class created). Promotes the next image to primary if needed. */
    public static function delete(PDO $db, int $placeId, int $imageId): bool
    {
        $st = $db->prepare('SELECT File, Thumb, IsPrimary FROM tbl_map_place_images WHERE ImageId = ? AND PlaceId = ?');
        $st->execute([$imageId, $placeId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return false;
        $db->prepare('DELETE FROM tbl_map_place_images WHERE ImageId = ? AND PlaceId = ?')->execute([$imageId, $placeId]);
        foreach ([$r['File'], $r['Thumb']] as $rel) {
            if (is_string($rel) && self::isOwnPath($rel)) { $abs = self::root() . '/' . $rel; if (is_file($abs)) @unlink($abs); }
        }
        if ((int)$r['IsPrimary'] === 1) {
            $n = $db->prepare('SELECT ImageId FROM tbl_map_place_images WHERE PlaceId = ? ORDER BY Sort ASC, ImageId ASC LIMIT 1');
            $n->execute([$placeId]);
            $next = $n->fetchColumn();
            if ($next) $db->prepare('UPDATE tbl_map_place_images SET IsPrimary = 1 WHERE ImageId = ?')->execute([(int)$next]);
        }
        return true;
    }
}
