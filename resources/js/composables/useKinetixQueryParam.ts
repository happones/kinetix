import { router } from '@inertiajs/vue3';

/**
 * Keep a piece of UI state (the open tab, the wizard step, the relation
 * manager) in the page's query string, so a reload or a shared link reopens
 * it. Writes are CLIENT-side history replaces: no server round-trip and no
 * history-stack spam, so Back still leaves the page instead of stepping
 * through tabs.
 */

/** The value of `?{param}=` on the current URL, or null (absent / SSR). */
export function readQueryParam(param: string): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    return new URLSearchParams(window.location.search).get(param);
}

/** Write `?{param}={value}` into the current URL without a visit. */
export function writeQueryParam(param: string, value: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);

    if (url.searchParams.get(param) === value) {
        return;
    }

    url.searchParams.set(param, value);
    const target = url.pathname + url.search + url.hash;

    try {
        router.replace({
            url: target,
            preserveScroll: true,
            preserveState: true,
        });
    } catch {
        window.history.replaceState(window.history.state, '', target);
    }
}

/** A tab/step node as the server serializes it (form field or infolist entry). */
interface QueryNode {
    queryParam?: string | null;
    urlKey?: string | null;
    schema?: QueryNode[] | null;
}

/** Whether a nested group persisted under `param` owns the key `key`. */
function containsKey(
    nodes: QueryNode[] | null | undefined,
    param: string,
    key: string,
): boolean {
    for (const node of nodes ?? []) {
        if (
            node.queryParam === param &&
            (node.schema ?? []).some((child) => child.urlKey === key)
        ) {
            return true;
        }

        if (containsKey(node.schema, param, key)) {
            return true;
        }
    }

    return false;
}

/**
 * Which of a group's children the URL asks for: the one whose own key is in
 * `?{param}=`, or the one holding a nested group (same param) that owns it —
 * so `?tab=address` opens the outer "Billing" tab AND its inner "Address" tab.
 * -1 when the param is off, absent, or names nothing here (a hidden tab, a
 * stale link): the caller keeps its default.
 */
export function requestedIndex(
    children: QueryNode[] | null | undefined,
    param: string | null | undefined,
): number {
    if (!param) {
        return -1;
    }

    const requested = readQueryParam(param);

    if (requested === null || requested === '') {
        return -1;
    }

    return (children ?? []).findIndex(
        (child) =>
            child.urlKey === requested ||
            containsKey(child.schema, param, requested),
    );
}
