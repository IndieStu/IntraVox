/**
 * Media path -> URL-safe path segments.
 *
 * One implementation for every place that builds a media URL: the rendered
 * widget (Widget.vue), the editor preview (WidgetEditor.vue) and the picker
 * thumbnail (MediaPicker.vue). They used to interpolate the raw filename into
 * a template literal, so a name containing "#", "?" or "%" produced a URL that
 * meant something else entirely -- "foto #1.png" asked the server for "foto "
 * and rendered blank.
 *
 * encodeURIComponent() per segment rather than on the whole path, because
 * resources media may be one folder deep ("backgrounds/header.svg") and the
 * separator has to survive as a separator. encodeURI() is not an alternative:
 * it leaves "#" and "?" alone, which are exactly the characters that break.
 *
 * @param {string} path Raw path as stored in widget config or returned by the
 *                      media listing, e.g. "Über ons/Team foto.jpg"
 * @returns {string} The same path with each segment percent-encoded
 */
export function encodeMediaPath(path) {
  if (!path) return '';

  return String(path)
    .split('/')
    .map(encodeURIComponent)
    .join('/');
}
