import { usePage } from '@inertiajs/vue3';
import type { App, DirectiveBinding } from 'vue';
import type { KinetixSharedProps } from '@/types/kinetix';

/**
 * `v-can` directive: hides an element (display:none) unless the user has the
 * given permission. Accepts a string or an array (any-of).
 *
 *   <button v-can="'posts.create'">New</button>
 *   <a v-can="['posts.update','posts.view']">Edit</a>
 *
 * For reactive show/hide with a fallback, prefer the <KinetixCan> component.
 * Register once: `app.use(KinetixPermissions)`.
 */
function currentPermissions(): string[] {
    // Inertia's usePage() is backed by a module-level reactive store, so it can be
    // read outside setup (here, inside the directive hooks).
    const page = usePage<KinetixSharedProps>();

    return page.props.kinetix_permissions?.permissions ?? [];
}

function apply(
    el: HTMLElement,
    binding: DirectiveBinding<string | string[]>,
): void {
    const wanted = Array.isArray(binding.value)
        ? binding.value
        : [binding.value];
    const granted = currentPermissions();
    const allowed = wanted.some((permission) => granted.includes(permission));

    el.style.display = allowed ? '' : 'none';
}

export const KinetixPermissions = {
    install(app: App): void {
        app.directive('can', { mounted: apply, updated: apply });
    },
};

export default KinetixPermissions;
