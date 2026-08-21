<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service\Sanitize;

use enshrined\svgSanitize\Sanitizer;
use Psr\Log\LoggerInterface;

/**
 * Sanitize media uploads (filenames + SVG + image-header validation).
 *
 * Mirrors the security-critical helpers that used to live in PageService.
 * Keeping them in a dedicated service makes the rules auditable in
 * isolation — important for enterprise security reviews that scrutinize
 * upload paths separately from page-rendering logic.
 */
final class MediaSanitizer {
    public const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
        'mp4', 'webm', 'ogg',
    ];

    /**
     * Windows-reserved device names — refused on every platform to avoid
     * surprise when files are downloaded onto Windows hosts.
     *
     * @var array<int, string>
     */
    private const WINDOWS_RESERVED_BASENAMES = [
        'con', 'prn', 'aux', 'nul',
        'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
        'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
    ];

    /**
     * Patterns that should never survive svg-sanitize. We re-scan after the
     * library does its work because some bypasses live in obscure XML
     * constructs the upstream allowlist still permits.
     *
     * @var array<int, string>
     */
    private const DANGEROUS_SVG_PATTERNS = [
        '<!ENTITY',
        '<iframe',
        '<embed',
        '<object',
        '<script',
        'javascript:',
        'data:text/html',
        'SYSTEM',
        'PUBLIC',
    ];

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    /**
     * Produce a filesystem-safe filename. Strips path separators and control
     * characters, keeps letters and digits in any script, then re-applies the
     * original extension if it was on the allow list.
     *
     * Unicode letters used to be stripped along with everything else: the
     * rule was `[^a-zA-Z0-9_\-]`, so a German intranet uploading
     * "Übersicht.png" got "bersicht.png" back, "Öl.png" became "l.png", and a
     * name written in a non-latin script collapsed to nothing and was handed a
     * "file_<uniqid>" fallback. The file worked, but under a name its owner
     * did not choose and could not search for. Nextcloud itself stores those
     * names without complaint, and after issue #101 the serving path does too,
     * so the ASCII floor here was the last thing enforcing it.
     *
     * What still goes: anything that is not a letter, digit, underscore or
     * hyphen — which is what keeps "/" and "\" (path separators), control
     * characters and shell metacharacters out. \p{L} and \p{N} are matched
     * with the /u flag; invalid UTF-8 makes preg_replace return null, and that
     * is treated as a name we refuse rather than one we repair.
     *
     * @throws \InvalidArgumentException when validateExtension is true and
     *         the extension is not in self::ALLOWED_EXTENSIONS, or when the
     *         filename is not valid UTF-8
     */
    public function sanitizeFilename(string $filename, bool $validateExtension = true): string {
        $extension = '';
        if (($dotPos = strrpos($filename, '.')) !== false) {
            $ext = strtolower(substr($filename, $dotPos + 1));
            if ($validateExtension && !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                throw new \InvalidArgumentException(
                    'File extension not allowed: ' . $ext .
                    '. Allowed: ' . implode(', ', self::ALLOWED_EXTENSIONS)
                );
            }
            $extension = '.' . $ext;
            $filename = substr($filename, 0, $dotPos);
        }

        $filename = preg_replace('/[^\p{L}\p{N}_\-]/u', '_', $filename);
        if ($filename === null) {
            // Only reachable on invalid UTF-8, which no browser sends and no
            // filesystem should be asked to store.
            throw new \InvalidArgumentException('Filename is not valid UTF-8');
        }
        $filename = preg_replace('/_+/', '_', $filename);
        $filename = trim($filename, '_');

        if (in_array(mb_strtolower($filename), self::WINDOWS_RESERVED_BASENAMES, true)) {
            $filename = 'file_' . uniqid();
        }

        // Bytes, not characters: the 255 limit filesystems impose is a byte
        // limit, and a multi-byte name reaches it in fewer characters. Cutting
        // with mb_strcut rather than substr keeps the truncation from landing
        // mid-character and producing invalid UTF-8.
        $maxLength = 255 - strlen($extension);
        if (strlen($filename) > $maxLength) {
            $filename = mb_strcut($filename, 0, $maxLength);
        }

        if ($filename === '') {
            $filename = 'file_' . uniqid();
        }

        return $filename . $extension;
    }

    /**
     * Sanitize raw SVG content. Returns clean SVG markup or throws when the
     * file is malformed or contains content the dangerous-patterns scan
     * catches even after the svg-sanitize allowlist.
     *
     * Fails closed: any failure inside the sanitizer — including a missing
     * svg-sanitize dependency, which raises an \Error rather than an
     * \Exception — is reported as a rejected upload.
     *
     * @throws \Exception
     */
    public function sanitizeSVG(string $svgContent): string {
        try {
            $sanitizer = new Sanitizer();
            $sanitizer->removeRemoteReferences(true);

            $cleanSvg = $sanitizer->sanitize($svgContent);
            if ($cleanSvg === false || $cleanSvg === '') {
                throw new \Exception('SVG sanitization failed - file may contain malicious content');
            }

            if (stripos($cleanSvg, '<!DOCTYPE') !== false) {
                throw new \Exception('SVG contains DOCTYPE declaration (not allowed)');
            }

            foreach (self::DANGEROUS_SVG_PATTERNS as $pattern) {
                if (stripos($cleanSvg, $pattern) !== false) {
                    throw new \Exception('SVG contains prohibited content: ' . $pattern);
                }
            }

            return $cleanSvg;
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: when vendor/ is missing from the
            // package the Sanitizer class does not exist, and `new Sanitizer()`
            // raises an \Error. Catching only \Exception let that escape as a
            // fatal on every App Store install (see REL-1). Whatever the cause,
            // an SVG we could not sanitize must be refused, never passed through.
            $this->logger->error('SVG sanitization error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw new \Exception('Invalid SVG file');
        }
    }

    /**
     * Verify a file actually decodes as the image type its MIME claims.
     * Defends against polyglot uploads (e.g. an HTML file masquerading
     * with an image/jpeg extension+MIME).
     *
     * @throws \InvalidArgumentException when the file fails to decode or
     *         the decoded format does not match the declared MIME
     */
    public function validateImageFile(string $tmpFile, string $detectedMime): void {
        $imageInfo = @getimagesize($tmpFile);
        if ($imageInfo === false) {
            throw new \InvalidArgumentException('File appears to be an invalid or corrupted image');
        }

        $expectedMime = match ($imageInfo[2]) {
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_GIF => 'image/gif',
            IMAGETYPE_WEBP => 'image/webp',
            default => null,
        };

        if ($expectedMime !== null && $expectedMime !== $detectedMime) {
            $this->logger->warning('Image MIME type mismatch', [
                'detected' => $detectedMime,
                'actual' => $expectedMime,
            ]);
            throw new \InvalidArgumentException(
                'Image file appears to be corrupted or has incorrect extension'
            );
        }
    }
}
