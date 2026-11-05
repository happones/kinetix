import {
    getCurrentScope,
    onScopeDispose,
    readonly,
    ref,
    shallowRef,
    watch,
} from 'vue';
import type { Component, Ref, ShallowRef } from 'vue';
import { preloadKinetixLazy } from '@/composables/kinetixLazy';
import type { KinetixLazyComponent } from '@/composables/kinetixLazy';

/**
 * The two ways a light layout host (the component a host mounts once in its
 * layout) brings in its heavy body.
 */

export interface KinetixLazyOnDemand {
    /** The loaded body; null until the first request has downloaded it. */
    readonly body: Readonly<ShallowRef<Component | null>>;
    /** The body's `v-model:open`; set by `request()` before the code arrives. */
    readonly open: Ref<boolean>;
    /** Record the intent to open, download the body if needed, then show it. */
    request(): void;
}

/**
 * A body that only exists once the user asks for it (a shortcut, an event, a
 * click). The trigger stays in the host and is handled synchronously; the
 * host renders `<component :is="body" v-if="body" v-model:open="open" />`.
 */
export function useKinetixLazyOnDemand(
    entry: KinetixLazyComponent,
    name: string,
): KinetixLazyOnDemand {
    const body: ShallowRef<Component | null> = shallowRef(null);
    const open = ref(false);
    let disposed = false;

    if (getCurrentScope()) {
        onScopeDispose(() => {
            disposed = true;
        });
    }

    function request(): void {
        open.value = true;

        if (body.value !== null) {
            return;
        }

        entry.preload().then(
            (component) => {
                if (!disposed) {
                    body.value = component;
                }
            },
            (error: unknown) => {
                open.value = false;
                console.warn(`[kinetix] ${name} failed to load.`, error);
            },
        );
    }

    return {
        body: readonly(body) as Readonly<ShallowRef<Component | null>>,
        open,
        request,
    };
}

export interface KinetixLazyWhenEnabled {
    /** True from the first moment the feature had something to show; stays true. */
    readonly active: Readonly<Ref<boolean>>;
    /** Render this while `active`: the body, through its async wrapper until loaded. */
    readonly component: Component;
}

/**
 * A body that shows as soon as the page enables its feature: the download
 * starts in setup, and once active the body stays mounted, exactly as it did
 * when it was always mounted (it handles a later empty state itself).
 */
export function useKinetixLazyWhenEnabled(
    entry: KinetixLazyComponent,
    enabled: () => boolean,
): KinetixLazyWhenEnabled {
    const active = ref(false);
    // Read once: the async wrapper keeps rendering the real body after load.
    const component = entry.component;

    const activate = (): void => {
        active.value = true;
        void preloadKinetixLazy([entry]);
    };

    if (enabled()) {
        activate();
    } else {
        const stop = watch(enabled, (now) => {
            if (now) {
                stop();
                activate();
            }
        });
    }

    return { active: readonly(active), component };
}
