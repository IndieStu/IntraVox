<?php
declare(strict_types=1);

namespace OCA\IntraVox\Share;

/**
 * What part of the tree a public share actually covers. (F6)
 *
 * resolveShareScopePath() returns a path on the groupfolder storage, like
 * "files/nl/afdeling". Four endpoints each turned that into a language and a
 * relative path with the same six lines: strip the "files/" prefix, split on
 * "/", take the first segment, sanity-check its length. Four copies of a parse
 * is four places for the rules to drift apart — and the rules are what decides
 * how much of the tree an anonymous visitor sees.
 *
 * This is deliberately a pure value object with no dependencies: it can be
 * tested directly, and it is the first step of F6, which the plan wants to be a
 * pure move with no behaviour change.
 */
final class ShareScope {

	private function __construct(
		public readonly string $language,
		/** Path relative to the language folder: "" for a language-root share. */
		public readonly string $relativePath,
		/** The full path minus the "files/" prefix: "nl/afdeling". */
		public readonly string $scopePath,
	) {
	}

	/**
	 * Parse a storage path into a scope, or null when it does not name a usable
	 * language folder.
	 *
	 * Returns null rather than throwing: every caller answers an anonymous
	 * request and already has a "show nothing" branch for this case. A language
	 * code is 2 or 3 characters, matching the folder names the app creates.
	 */
	public static function fromScopePath(?string $scopePath): ?self {
		if ($scopePath === null || $scopePath === '') {
			return null;
		}

		$relative = $scopePath;
		if (str_starts_with($relative, 'files/')) {
			$relative = substr($relative, 6);
		}

		$relative = trim($relative, '/');
		if ($relative === '') {
			return null;
		}

		$segments = explode('/', $relative);
		$language = $segments[0];

		$length = strlen($language);
		if ($length < 2 || $length > 3) {
			return null;
		}

		return new self(
			$language,
			implode('/', array_slice($segments, 1)),
			$relative,
		);
	}

	/** Is the whole language shared, rather than one section of it? */
	public function isLanguageRoot(): bool {
		return $this->relativePath === '';
	}
}
