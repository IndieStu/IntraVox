<?php
declare(strict_types=1);

namespace OCA\IntraVox\Controller\Shared;

/**
 * Helpers used by both the authenticated API and the public share endpoints.
 *
 * These three were the only ones the F6 split could not simply move: they are
 * called from both sides. Copying them was rejected — sanitizePath() is
 * path-traversal protection, and two copies means a fix can miss one, which is
 * exactly the shape of the duplication ZIP-1 had to clean up.
 *
 * Bodies are verbatim from ApiController.
 */
trait SharePathTrait {

    /**
     * Sanitize file path - prevent directory traversal and other path attacks
     *
     * Security checks:
     * - Null byte injection
     * - Unicode normalization (NFD/NFC attacks)
     * - Directory traversal (..)
     * - Backslash conversion
     * - Control characters
     * - Hidden files (starting with .)
     * - Executable file extensions
     *
     * Accented letters, spaces and other printable unicode are legitimate
     * filenames and are passed through — see the note on step 7.
     *
     * @param string $path User-provided path
     * @return string Safe path
     * @throws \InvalidArgumentException if path is malicious
     */
    private function sanitizePath(string $path): string {
        if (empty($path)) {
            return '';
        }

        // 1. Check for null bytes FIRST (can bypass extension checks)
        if (strpos($path, "\0") !== false) {
            throw new \InvalidArgumentException('Invalid path: null bytes detected');
        }

        // 2. Look through any further URL-encoding for a smuggled traversal.
        //
        // Every caller receives $path from the router or from getParam(), both
        // of which have already decoded it once. Decoding again here is a
        // detection step only: it must NOT replace $path, because a filename
        // may legitimately contain the characters urldecode() consumes.
        // "foto+1.png" decoded to "foto 1.png" and we then looked up a file
        // that does not exist — the same shape as #101, a name the app stores
        // happily and cannot serve.
        $decoded = urldecode($path);
        if ($decoded !== $path) {
            $doubleDecoded = urldecode($decoded);
            if (strpos($decoded, '..') !== false ||
                strpos($decoded, '\\') !== false ||
                strpos($decoded, "\0") !== false ||
                strpos($doubleDecoded, '..') !== false ||
                strpos($doubleDecoded, '\\') !== false ||
                strpos($doubleDecoded, "\0") !== false) {
                throw new \InvalidArgumentException('Path traversal detected');
            }
        }

        // 3. Unicode normalization (prevent NFD/NFC attacks)
        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($path, \Normalizer::FORM_C);
            if ($normalized === false) {
                throw new \InvalidArgumentException('Invalid unicode in path');
            }
            $path = $normalized;
        }

        // 4. Convert backslashes to forward slashes
        $path = str_replace('\\', '/', $path);

        // 5. Remove leading/trailing slashes and dots
        $path = trim($path, '/.');

        // 6. Detect directory traversal patterns
        if (preg_match('#(\.\./|/\.\.|\.\.$|^\.\./)#', $path)) {
            throw new \InvalidArgumentException('Path traversal detected');
        }

        // 7. Refuse control characters.
        //
        // This used to be an allowlist, `^[a-zA-Z0-9/_\-\.]+$`, which refused
        // every accented letter and every space: "Übersicht.png" and
        // "Team foto.jpg" in the Shared library were unservable, and because
        // the picker thumbnail, the rendered widget and the News tile all
        // resolve through here, an affected image was blank in all three
        // (issue #101). The allowlist also contradicted the storage side —
        // PageShapeSanitizer::sanitizePath() accepts unicode on purpose, and
        // PathSanitizerSpecTest pins `afdeling/café.jpg` — so the app stored
        // a src it then refused to serve.
        //
        // The allowlist was never what made this safe. Null bytes (1),
        // traversal (6), empty/dot segments, dotfiles and executable
        // extensions (8) each have their own check, and the lookup itself is
        // a Folder::get() inside an already-scoped folder. Control characters
        // are the one class with no legitimate use in a filename, so they are
        // the one class refused here.
        if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new \InvalidArgumentException('Invalid characters in path');
        }

        // 8. Split into segments and validate each
        if (!empty($path)) {
            $segments = explode('/', $path);
            foreach ($segments as $segment) {
                // Empty segments (double slashes)
                if (empty($segment) || $segment === '.' || $segment === '..') {
                    throw new \InvalidArgumentException('Invalid path segment');
                }

                // Hidden files (starting with dot) - except _media, _resources
                if (substr($segment, 0, 1) === '.' && $segment !== '.') {
                    throw new \InvalidArgumentException('Hidden files not allowed');
                }

                // Block executable PHP extensions
                if (preg_match('/\.(php|phtml|php[345]|phar|phps|pht|cgi|pl|sh|bash)$/i', $segment)) {
                    throw new \InvalidArgumentException('Executable files not allowed');
                }
            }
        }

        return $path;
    }

    /**
     * Whether an admin has allowed People widgets on public share links.
     *
     * Defaults to false: the safe option has to be what happens when nobody
     * has thought about it, because the situation this guards against is
     * precisely someone not having thought about it.
     */
    private function peopleAllowedOnPublicShares(): bool {
        return $this->config->getAppValue('intravox', 'public_share_allow_people', 'no') === 'yes';
    }

    /** Collect all page fileIds in a (nested) tree for a single batch lookup. */
    private function collectTreeFileIds(array $tree): array {
        $ids = [];
        foreach ($tree as $item) {
            if (!empty($item['fileId'])) {
                $ids[] = $item['fileId'];
            }
            if (!empty($item['children'])) {
                $ids = array_merge($ids, $this->collectTreeFileIds($item['children']));
            }
        }
        return $ids;
    }
}
