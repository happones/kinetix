/**
 * The HTML attributes every rendered action carries: `data-action` with the
 * action's name — a stable hook for tours, tests and analytics that no
 * markup change moves — plus the server's `extraAttributes()`.
 *
 * Event-handler attributes are dropped here too (the server already filters
 * them): bound as a string, Vue writes `onclick` straight into the DOM.
 */
export function actionAttributes(action: {
    name?: string | null;
    extraAttributes?: Record<string, string> | null;
}): Record<string, string> {
    const attributes: Record<string, string> = {};

    for (const [name, value] of Object.entries(action.extraAttributes ?? {})) {
        if (!/^on/i.test(name)) {
            attributes[name] = value;
        }
    }

    if (action.name) {
        attributes['data-action'] = action.name;
    }

    return attributes;
}
