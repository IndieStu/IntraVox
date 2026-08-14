/**
 * Title -> URL/folder-friendly slug.
 *
 * One implementation for the create flow (App.vue) and the rename dialog's
 * folder preview -- the preview must predict exactly the name the backend
 * will be asked to use.
 */
export function generateSlug(title) {
  return title.toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '') // Remove diacritics
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}
