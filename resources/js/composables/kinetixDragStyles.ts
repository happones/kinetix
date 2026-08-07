/**
 * Shared visual vocabulary for every drag-and-drop surface (kanban, calendar,
 * table reorder, media library), so in-flight items and drop previews look the
 * same across the suite.
 */

/** Dims the dragged item at its origin while the drag is in flight. */
export const KINETIX_DRAG_SOURCE_CLASS = 'opacity-40';

/**
 * Marks the live-preview position of an item being reordered within its own
 * list (table rows, media tiles): the item follows the pointer through the
 * list, rendered as a translucent "shadow" of itself until dropped.
 * Background + opacity only, so it renders identically on `<tr>` and block
 * elements.
 */
export const KINETIX_DROP_PREVIEW_CLASS = 'opacity-60 bg-primary/10';
