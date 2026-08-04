/**
 * Section-anchor helpers for IntraVox pages.
 *
 * Headings (both stand-alone heading widgets and H1–H4 inside text blocks) get a
 * stable DOM id of the form `h-<slug>` so visitors can deep-link to a section via
 * the URL fragment (e.g. `…?page=<uniqueId>#h-creating-a-new-form`).
 *
 * The `h-` prefix is deliberate: page navigation uses `page-<uniqueId>` (in the
 * hash for the logged-in view, in the `?page=` query for the public share view),
 * so the two fragment kinds can never be mistaken for one another.
 */

const ANCHOR_PREFIX = 'h-';

/**
 * Deterministic, collision-free-ish slug from a heading's visible text.
 * Strips HTML tags and diacritics, lowercases, and collapses non-alphanumerics.
 *
 * @param {string} text
 * @return {string} slug WITHOUT the `h-` prefix (e.g. "creating-a-new-form")
 */
export function slugifyHeading(text) {
	return String(text ?? '')
		.replace(/<[^>]*>/g, ' ') // strip any HTML tags first
		.normalize('NFKD').replace(/[̀-ͯ]/g, '') // strip diacritics
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '-') // non-alphanumeric → '-'
		.replace(/^-+|-+$/g, '') // trim leading/trailing '-'
		.slice(0, 80) || 'section';
}

/**
 * Build the full anchor id (with prefix) from heading text.
 *
 * @param {string} text
 * @return {string} e.g. "h-creating-a-new-form"
 */
export function anchorIdFor(text) {
	return ANCHOR_PREFIX + slugifyHeading(text);
}

/**
 * Factory for a per-page slugger that makes anchor ids unique within one page:
 * the first "Title" → `h-title`, the next → `h-title-2`, etc.
 *
 * @return {(text: string) => string} returns the full `h-…` id
 */
export function makeUniqueAnchorId() {
	const seen = new Map();
	return (text) => {
		const base = slugifyHeading(text);
		const n = seen.get(base) || 0;
		seen.set(base, n + 1);
		const slug = n === 0 ? base : `${base}-${n + 1}`;
		return ANCHOR_PREFIX + slug;
	};
}

/**
 * True if a URL fragment (with or without the leading '#') is a section anchor
 * rather than a page identifier.
 *
 * @param {string} fragment
 * @return {boolean}
 */
export function isSectionAnchor(fragment) {
	if (!fragment) return false;
	const f = fragment.startsWith('#') ? fragment.slice(1) : fragment;
	return f.startsWith(ANCHOR_PREFIX);
}

/**
 * If the current URL hash is a section anchor, scroll to it. Safe to call after
 * every page render — it no-ops when there is no `#h-…` fragment or no matching
 * element (yet).
 *
 * @return {boolean} whether it scrolled
 */
export function scrollToHashAnchor() {
	const hash = window.location.hash;
	if (!isSectionAnchor(hash)) return false;
	const el = document.getElementById(hash.slice(1));
	if (!el) return false;
	el.scrollIntoView({ behavior: 'smooth', block: 'start' });
	return true;
}

export { ANCHOR_PREFIX };
