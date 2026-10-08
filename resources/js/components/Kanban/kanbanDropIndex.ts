/**
 * Where a card dropped at `clientY` lands in a kanban column: before the first
 * rendered card whose middle is below the pointer, else after the last one.
 * Reads each card's absolute `data-index`, so a virtualized column (which only
 * renders a window of its cards) still yields the right index. The index
 * counts the dragged card too when it comes from the same column.
 */
export function kanbanDropIndex(columnEl: Element, clientY: number): number {
    let after = 0;

    for (const card of columnEl.querySelectorAll<HTMLElement>(
        '[data-kanban-card]',
    )) {
        const index = Number(card.dataset.index);
        const rect = card.getBoundingClientRect();

        if (clientY < rect.top + rect.height / 2) {
            return index;
        }

        after = index + 1;
    }

    return after;
}

/**
 * The column's cards after moving `card` to `index` (an insertion point
 * counted before the card leaves its place). Null when the card would land
 * where it already is.
 */
export function placeCard<T extends { id: string | number }>(
    cards: T[],
    card: T,
    index: number,
): T[] | null {
    const from = cards.findIndex((c) => c.id === card.id);
    const next = cards.filter((c) => c.id !== card.id);
    // Clamped before comparing, so a slot past the end of the column the card
    // already ends is recognised as "where it is".
    const target = Math.max(
        0,
        Math.min(from !== -1 && from < index ? index - 1 : index, next.length),
    );

    if (from === target) {
        return null;
    }

    next.splice(target, 0, card);

    return next;
}
