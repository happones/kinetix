import { inject } from 'vue';

/** Injection key of the id scope a group of form fields renders under. */
export const KINETIX_FIELD_ID_PREFIX = 'kinetixFieldIdPrefix';

/**
 * The DOM id of a form field's control (or of an element tied to it: its
 * label target, helper, error, hints). Fields render with `id="<name>"`, so two
 * forms on one page that share a field name would share an id; a form that
 * provides a prefix under `KINETIX_FIELD_ID_PREFIX` scopes every id to
 * `<prefix>-<name>`. Without a prefix the id is exactly the name.
 */
export function useKinetixFieldId(): (name: string) => string {
    const prefix = inject<string>(KINETIX_FIELD_ID_PREFIX, '');

    return (name) => (prefix ? `${prefix}-${name}` : name);
}
