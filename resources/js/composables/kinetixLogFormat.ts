/**
 * Pretty-print a log value: JSON strings are re-indented, objects stringified,
 * and empty/nullish values render as an em dash. Shared by the log tables and
 * the detail modal.
 */
export function pretty(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'string') {
        try {
            return JSON.stringify(JSON.parse(value), null, 2);
        } catch {
            return value;
        }
    }

    return JSON.stringify(value, null, 2);
}

/** Success/failure badge classes for a boolean-ok status. */
export function statusClass(ok: boolean): string {
    return ok
        ? 'bg-success/10 text-success border border-success/20'
        : 'bg-destructive/10 text-destructive border border-destructive/20';
}

/** Localised timestamp, or an em dash when absent. */
export function formatTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}
