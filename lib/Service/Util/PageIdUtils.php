<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service\Util;

/**
 * Small, pure utility functions extracted from PageService.
 *
 * - `sanitizeId` enforces the filesystem-safe character set for legacy
 *   path-based page IDs (the modern `page-{uuid}` IDs already match it).
 * - `generateUUID` produces an RFC 4122 v4 UUID without leaning on a
 *   third-party library — used for `page-{uuid}` and `template-{uuid}`.
 * - `parsePhpSize` reads `php.ini`-style size suffixes ("2M", "50K", …).
 * - `formatBytes` is the inverse: turn a byte count into a human label.
 *
 * Living here keeps PageService's surface area focused on page-domain
 * logic and lets these helpers gather independent tests + reuse without
 * dragging the constructor in.
 */
final class PageIdUtils {
    public function sanitizeId(string $id): string {
        // Transliterate accented/non-ASCII letters to their ASCII base BEFORE
        // stripping, so "Müller" → "Muller" (folder name) instead of "Mller"
        // and "Café" → "Cafe" instead of "Caf". Without this, non-ASCII letters
        // were silently dropped, mangling folder names for many languages.
        // page-{uuid} IDs are already pure ASCII and pass through unchanged.
        if (preg_match('/[^\x00-\x7F]/', $id)) {
            $transliterated = $this->transliterateToAscii($id);
            if ($transliterated !== '') {
                $id = $transliterated;
            }
        }
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        if ($id === '') {
            throw new \InvalidArgumentException('Invalid page ID');
        }
        return $id;
    }

    /**
     * Best-effort Latin transliteration of non-ASCII text (ü→ue via NFKD folding
     * to u, ß→ss, é→e, …). Uses the intl Transliterator when available and falls
     * back to iconv; returns '' if neither can help (caller keeps the original).
     */
    private function transliterateToAscii(string $text): string {
        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::create('Any-Latin; Latin-ASCII; [:Nonspacing Mark:] Remove');
            if ($tr !== null) {
                $out = $tr->transliterate($text);
                if (is_string($out)) {
                    return $out;
                }
            }
        }
        $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return is_string($out) ? $out : '';
    }

    /**
     * RFC 4122 v4 UUID (random-based). Output format: 8-4-4-4-12 hex digits.
     */
    public function generateUUID(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Parse PHP-ini size notation. Supported suffixes: K, M, G
     * (case-insensitive). Bare numbers are read as bytes.
     */
    public function parsePhpSize(string $size): int {
        $size = trim($size);
        if ($size === '') {
            return 0;
        }
        $unit = strtoupper(substr($size, -1));
        $value = (int) substr($size, 0, -1);
        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => (int) $size,
        };
    }

    /**
     * Human-readable byte count: "1.5 MB", "812 KB", etc. Two decimals,
     * 1024-based units (matches what Nextcloud's Files UI shows).
     */
    public function formatBytes(int $bytes): string {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        }
        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
}
