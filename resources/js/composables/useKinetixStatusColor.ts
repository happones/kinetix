/**
 * Maps a Kinetix status color (`success` · `danger` · `warning` · `info` ·
 * `primary` · `gray`) to shadcn-token utility classes.
 *
 * `danger` resolves to the built-in `destructive` token; `success`/`warning`/
 * `info` resolve to the Kinetix status tokens (shipped in `kinetix.css`, and
 * overridable in any app's theme). Because the tokens shift between light and
 * dark mode, no `dark:` variants are needed.
 *
 * Class strings are static (never interpolated) so Tailwind's JIT keeps them.
 */

export type KinetixStatusColor =
    | 'success'
    | 'danger'
    | 'warning'
    | 'info'
    | 'primary'
    | 'gray'
    | string
    | null
    | undefined;

const BADGE: Record<string, string> = {
    success: 'text-success bg-success/10 border border-success/20',
    danger: 'text-destructive bg-destructive/10 border border-destructive/20',
    warning: 'text-warning bg-warning/10 border border-warning/20',
    info: 'text-info bg-info/10 border border-info/20',
    primary: 'text-primary bg-primary/10 border border-primary/20',
};

const TEXT: Record<string, string> = {
    success: 'text-success',
    danger: 'text-destructive',
    warning: 'text-warning',
    info: 'text-info',
    primary: 'text-primary',
};

const SOFT: Record<string, string> = {
    success: 'text-success bg-success/10',
    danger: 'text-destructive bg-destructive/10',
    warning: 'text-warning bg-warning/10',
    info: 'text-info bg-info/10',
    primary: 'text-primary bg-primary/10',
};

const INTERACTIVE_TEXT: Record<string, string> = {
    success: 'text-success focus:text-success',
    danger: 'text-destructive focus:text-destructive',
    warning: 'text-warning focus:text-warning',
    info: 'text-info focus:text-info',
    primary: 'text-primary focus:text-primary',
};

const SOLID_BUTTON: Record<string, string> = {
    success:
        'bg-success text-success-foreground hover:bg-success/90 focus-visible:ring-success/20',
    // White on red, dimmed in dark mode — the shadcn destructive recipe, which
    // holds whatever red the host's theme defines.
    danger: 'bg-destructive text-white hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:bg-destructive/60',
    warning:
        'bg-warning text-warning-foreground hover:bg-warning/90 focus-visible:ring-warning/20',
    info: 'bg-info text-info-foreground hover:bg-info/90 focus-visible:ring-info/20',
};

const FILL: Record<string, string> = {
    success: 'bg-success',
    danger: 'bg-destructive',
    warning: 'bg-warning',
    info: 'bg-info',
    primary: 'bg-primary',
    gray: 'bg-muted-foreground',
};

/**
 * Alert surfaces. On `soft` / `outline` / `accent` only the surface carries
 * the status color — title and body stay on the foreground tokens, and the
 * icon takes the status color (`statusTextClass`). `solid` fills the surface
 * and sets its own text color; content on it inherits (`text-current`).
 */
export type KinetixAlertVariant = 'soft' | 'outline' | 'accent' | 'solid';

const ALERT_SOFT: Record<string, string> = {
    success: 'border-success/30 bg-success/10',
    danger: 'border-destructive/30 bg-destructive/10',
    warning: 'border-warning/30 bg-warning/10',
    info: 'border-info/30 bg-info/10',
    primary: 'border-primary/20 bg-primary/5',
};

const ALERT_OUTLINE: Record<string, string> = {
    success: 'border-success/60 bg-background',
    danger: 'border-destructive/60 bg-background',
    warning: 'border-warning/60 bg-background',
    info: 'border-info/60 bg-background',
    primary: 'border-primary/40 bg-background',
};

const ALERT_ACCENT: Record<string, string> = {
    success: 'border-border border-l-4 border-l-success bg-card',
    danger: 'border-border border-l-4 border-l-destructive bg-card',
    warning: 'border-border border-l-4 border-l-warning bg-card',
    info: 'border-border border-l-4 border-l-info bg-card',
    primary: 'border-border border-l-4 border-l-primary bg-card',
};

/**
 * A solid fill carries its own text color — the status token's -foreground
 * pair (danger: white on a dimmed-in-dark red, like the destructive button).
 */
const ALERT_SOLID: Record<string, string> = {
    success: 'border-transparent bg-success text-success-foreground',
    danger: 'border-transparent bg-destructive text-white dark:bg-destructive/60',
    warning: 'border-transparent bg-warning text-warning-foreground',
    info: 'border-transparent bg-info text-info-foreground',
    primary: 'border-transparent bg-primary text-primary-foreground',
};

const ALERT_FALLBACK: Record<KinetixAlertVariant, string> = {
    soft: 'border-border bg-muted/50',
    outline: 'border-border bg-background',
    accent: 'border-border border-l-4 border-l-muted-foreground bg-card',
    solid: 'border-transparent bg-foreground text-background',
};

/** The alert surface for a status (`gray`/unknown = the neutral surface). */
export function statusAlertClass(
    color?: KinetixStatusColor,
    variant: KinetixAlertVariant = 'soft',
): string {
    const map =
        variant === 'outline'
            ? ALERT_OUTLINE
            : variant === 'accent'
              ? ALERT_ACCENT
              : variant === 'solid'
                ? ALERT_SOLID
                : ALERT_SOFT;

    return (
        map[color as string] ?? ALERT_FALLBACK[variant] ?? ALERT_FALLBACK.soft
    );
}

/** Soft badge: tinted background, status text, subtle border. */
export function statusBadgeClass(color?: KinetixStatusColor): string {
    return (
        BADGE[color as string] ??
        'text-muted-foreground bg-muted border border-border'
    );
}

/** Plain status text color (e.g. icons, links, emphasis). */
export function statusTextClass(
    color?: KinetixStatusColor,
    fallback = 'text-foreground',
): string {
    return TEXT[color as string] ?? fallback;
}

/** Status text with a matching `focus:` variant (e.g. menu items). */
export function statusInteractiveTextClass(color?: KinetixStatusColor): string {
    return INTERACTIVE_TEXT[color as string] ?? 'text-foreground';
}

/** Status text on a tinted background (e.g. icon wrappers, stat chips). */
export function statusSoftClass(color?: KinetixStatusColor): string {
    return SOFT[color as string] ?? 'text-muted-foreground bg-muted';
}

/** Solid filled button for a status (primary falls back to the primary button). */
export function statusButtonClass(color?: KinetixStatusColor): string {
    return (
        SOLID_BUTTON[color as string] ??
        'bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring/20'
    );
}

/** Solid fill (progress bars/rings — no text/border, just the background). */
export function statusFillClass(color?: KinetixStatusColor): string {
    return FILL[color as string] ?? FILL.primary;
}
