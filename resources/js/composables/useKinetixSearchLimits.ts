import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type { KinetixLookupMeta, KinetixSharedProps } from '@/types/kinetix';

export interface KinetixSearchLimits {
    /** `maxlength` for a search input; undefined = no cap configured. */
    maxLength: ComputedRef<number | undefined>;
    /** Shortest non-empty term the server answers (1 when not configured). */
    minChars: ComputedRef<number>;
    /** True for a typed term the server would refuse as too short. */
    isTooShort: (term: string) => boolean;
    /** The `min_chars` to show in the hint: the server's, else the shared one. */
    hintCount: (meta?: KinetixLookupMeta | null) => number;
}

/**
 * The search limits the server shares with the page
 * (`kinetix_config.search`). Both are optional: an older server just means no
 * `maxlength` and a minimum of 1.
 */
export function useKinetixSearchLimits(): KinetixSearchLimits {
    const page = usePage<KinetixSharedProps>();

    const config = computed(() => page?.props?.kinetix_config?.search);

    const maxLength = computed<number | undefined>(() => {
        const max = config.value?.max_length;

        return typeof max === 'number' && max > 0 ? max : undefined;
    });

    const minChars = computed<number>(() => {
        const min = config.value?.min_chars;

        return typeof min === 'number' && min > 1 ? min : 1;
    });

    // An empty term loads the initial list, so it is never "too short".
    const isTooShort = (term: string): boolean => {
        const length = term.trim().length;

        return length > 0 && length < minChars.value;
    };

    const hintCount = (meta?: KinetixLookupMeta | null): number =>
        meta?.min_chars ?? minChars.value;

    return { maxLength, minChars, isTooShort, hintCount };
}
