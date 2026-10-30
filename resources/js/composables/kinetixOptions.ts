import type { KinetixOption, KinetixSelectOption } from '@/types/kinetix';

/** value → label, or a list of `{ value, label }` when the order matters. */
export type KinetixOptions =
    | Record<string, string>
    | KinetixSelectOption[]
    | null
    | undefined;

/**
 * Options as an ordered list with string values.
 *
 * The server sends a list whenever an object would lose the order (integer-like
 * keys always enumerate first, ascending), so iterate this, never the object.
 */
export function kinetixOptionList(options: KinetixOptions): KinetixOption[] {
    if (Array.isArray(options)) {
        return options.map((option) => ({
            value: String(option.value),
            label: option.label,
        }));
    }

    return Object.entries(options ?? {}).map(([value, label]) => ({
        value,
        label,
    }));
}
