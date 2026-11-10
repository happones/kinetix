/**
 * A part of a DOM id built from a notification type key. Every character an id
 * (or a selector) would choke on, and the underscore itself, becomes `_xx_`
 * (its hex code), so two different keys never produce the same id.
 */
export const safeIdPart = (key: string): string =>
    key.replace(
        /[^A-Za-z0-9-]/g,
        (char) => `_${char.charCodeAt(0).toString(16)}_`,
    );
