import { usePage } from '@inertiajs/vue3';
import type { App, DirectiveBinding } from 'vue';
import { kinetixPermissionChecker } from '@/composables/useKinetixCan';
import type { KinetixSharedProps } from '@/types/kinetix';

/**
 * `v-can` directive: hides an element (display:none) unless the user has the
 * given permission. Accepts a string or an array (any-of). A super-admin
 * (`kinetix_permissions.isSuperAdmin`) sees everything, the same as
 * `useKinetixCan().can()` — both run `kinetixPermissionChecker`.
 *
 *   <button v-can="'posts.create'">New</button>
 *   <a v-can="['posts.update','posts.view']">Edit</a>
 *
 * Presentation only: the server still enforces every ability. For reactive
 * show/hide with a fallback, prefer the <KinetixCan> component.
 * Register once: `app.use(KinetixPermissions)`.
 */
function currentChecker(): (permission: string) => boolean {
    // Inertia's usePage() is backed by a module-level reactive store, so it can be
    // read outside setup (here, inside the directive hooks). `page?.props?.` —
    // outside a mounted Inertia app there is no page; everything then denies.
    const page = usePage<KinetixSharedProps>();

    return kinetixPermissionChecker(page?.props?.kinetix_permissions);
}

function apply(
    el: HTMLElement,
    binding: DirectiveBinding<string | string[]>,
): void {
    const wanted = Array.isArray(binding.value)
        ? binding.value
        : [binding.value];
    const allowed = wanted.some(currentChecker());

    el.style.display = allowed ? '' : 'none';
}

export const KinetixPermissions = {
    install(app: App): void {
        app.directive('can', { mounted: apply, updated: apply });
    },
};

export default KinetixPermissions;
