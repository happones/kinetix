/**
 * The value a write-only field (an encrypted setting, a secret) submits to ask
 * the server to remove its stored value. Mirrors `Form::CLEAR_SENTINEL`.
 *
 * The server honours it only for a field it flagged `isClearable` (write-only,
 * holding its own stored value, not required); anywhere else it is treated as
 * "unchanged" and never stored. A control must never display it.
 */
export const KINETIX_CLEAR_VALUE = '__kinetix_clear__';

/** Whether a field value is the "remove the saved value" request. */
export function isKinetixClearValue(value: unknown): boolean {
    return value === KINETIX_CLEAR_VALUE;
}
