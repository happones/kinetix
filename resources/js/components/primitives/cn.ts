/**
 * Minimal class-name joiner for Kinetix's internal shadcn-parity primitives.
 *
 * Kinetix avoids adding `clsx`/`tailwind-merge` as dependencies, so this only
 * filters falsy values and joins. Pass overrides last; for hard conflicts the
 * later utility must win in your CSS source order (Tailwind's normal rule).
 */
export function cn(
    ...classes: Array<string | false | null | undefined>
): string {
    return classes.filter(Boolean).join(' ');
}
