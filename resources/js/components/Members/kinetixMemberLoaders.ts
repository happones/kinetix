import {
    defineKinetixLazy,
    KinetixLazyBlockSkeleton,
} from '@/composables/kinetixLazy';

/**
 * The parts of the members screen that carry the forms engine: the renderer
 * of an app's custom member fields, and the Edit member modal that hosts it.
 * An app with no member fields never downloads either, so the members chunks
 * stay free of the form schema and its field types.
 *
 * This module imports no component statically, on purpose.
 */
export const KINETIX_LAZY_MEMBER_FIELDS = defineKinetixLazy(
    () => import('./KinetixMemberFields.vue'),
    { loading: KinetixLazyBlockSkeleton },
);

export const KINETIX_LAZY_MEMBER_EDIT = defineKinetixLazy(
    () => import('./KinetixMemberEditModal.vue'),
    { loading: false },
);
