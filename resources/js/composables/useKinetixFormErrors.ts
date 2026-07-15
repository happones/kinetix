/**
 * Shared helpers for mapping a flat Laravel error bag onto the (arbitrarily
 * nested) Kinetix form schema, so containers like Tabs and Wizard can tell
 * which of their children hold an error, and the form can focus the first one.
 *
 * The serialized schema is uniform: every layout/container nests its children
 * under `.schema` (tabs, wizard steps, sections, grids, repeaters, …), and
 * leaf fields carry a `.name`. A single recursive walk therefore covers every
 * nesting depth and combination (a wizard inside a tab inside a section, etc.).
 */

type SchemaNode = Record<string, any>;

/**
 * Collect every field `name` reachable from a schema subtree, in declaration
 * order (which is also DOM order), recursing through all `.schema` children.
 */
export function collectFieldNames(schema: SchemaNode[] | undefined): string[] {
    const names: string[] = [];

    const walk = (nodes: SchemaNode[] | undefined) => {
        for (const node of nodes ?? []) {
            if (node?.name) {
                names.push(node.name);
            }

            if (Array.isArray(node?.schema)) {
                walk(node.schema);
            }
        }
    };

    walk(schema);

    return names;
}

/**
 * True when an error key targets a given field — either exactly, or as the
 * prefix of a nested/array key (`address` matches `address.city`,
 * `line_items` matches `line_items.0.qty`).
 */
export function errorTargetsField(
    errorKey: string,
    fieldName: string,
): boolean {
    return errorKey === fieldName || errorKey.startsWith(`${fieldName}.`);
}

/**
 * Whether any error in `errorKeys` falls on a field inside this schema subtree.
 */
export function schemaHasError(
    schema: SchemaNode[] | undefined,
    errorKeys: string[],
): boolean {
    if (errorKeys.length === 0) {
        return false;
    }

    const names = collectFieldNames(schema);

    return errorKeys.some((key) =>
        names.some((name) => errorTargetsField(key, name)),
    );
}

/**
 * The first field (in declaration/DOM order) within `schema` that has an error,
 * or `null`. Used to decide which field to focus and which tab/step to reveal.
 */
export function firstErroredField(
    schema: SchemaNode[] | undefined,
    errorKeys: string[],
): string | null {
    if (errorKeys.length === 0) {
        return null;
    }

    for (const name of collectFieldNames(schema)) {
        if (errorKeys.some((key) => errorTargetsField(key, name))) {
            return name;
        }
    }

    return null;
}

/**
 * Focus (and scroll into view) the input for `name`. Because a field can live
 * in a not-yet-mounted tab/step panel, we retry across a few animation frames
 * to give parent containers time to switch to the panel that reveals it.
 *
 * Fields render their control with `id="{name}"`; container components resolve
 * the panel synchronously on the same error change, so the element is usually
 * present within one or two frames.
 */
export function focusField(name: string | null, maxFrames = 8): void {
    if (!name || typeof document === 'undefined') {
        return;
    }

    let frames = 0;

    const attempt = () => {
        const el = document.getElementById(name);

        if (el) {
            (el as HTMLElement).focus({ preventScroll: true });
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });

            return;
        }

        if (frames++ < maxFrames) {
            requestAnimationFrame(attempt);
        }
    };

    requestAnimationFrame(attempt);
}
