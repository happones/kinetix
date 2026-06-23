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
  | "success"
  | "danger"
  | "warning"
  | "info"
  | "primary"
  | "gray"
  | string
  | null
  | undefined;

const BADGE: Record<string, string> = {
  success: "text-success bg-success/10 border border-success/20",
  danger: "text-destructive bg-destructive/10 border border-destructive/20",
  warning: "text-warning bg-warning/10 border border-warning/20",
  info: "text-info bg-info/10 border border-info/20",
  primary: "text-primary bg-primary/10 border border-primary/20",
};

const TEXT: Record<string, string> = {
  success: "text-success",
  danger: "text-destructive",
  warning: "text-warning",
  info: "text-info",
  primary: "text-primary",
};

const SOFT: Record<string, string> = {
  success: "text-success bg-success/10",
  danger: "text-destructive bg-destructive/10",
  warning: "text-warning bg-warning/10",
  info: "text-info bg-info/10",
  primary: "text-primary bg-primary/10",
};

const INTERACTIVE_TEXT: Record<string, string> = {
  success: "text-success focus:text-success",
  danger: "text-destructive focus:text-destructive",
  warning: "text-warning focus:text-warning",
  info: "text-info focus:text-info",
  primary: "text-primary focus:text-primary",
};

const SOLID_BUTTON: Record<string, string> = {
  success:
    "bg-success text-success-foreground hover:bg-success/90 focus-visible:ring-success/20",
  danger:
    "bg-destructive text-destructive-foreground hover:bg-destructive/90 focus-visible:ring-destructive/20",
  warning:
    "bg-warning text-warning-foreground hover:bg-warning/90 focus-visible:ring-warning/20",
  info: "bg-info text-info-foreground hover:bg-info/90 focus-visible:ring-info/20",
};

/** Soft badge: tinted background, status text, subtle border. */
export function statusBadgeClass(color?: KinetixStatusColor): string {
  return (
    BADGE[color as string] ??
    "text-muted-foreground bg-muted border border-border"
  );
}

/** Plain status text color (e.g. icons, links, emphasis). */
export function statusTextClass(
  color?: KinetixStatusColor,
  fallback = "text-foreground",
): string {
  return TEXT[color as string] ?? fallback;
}

/** Status text with a matching `focus:` variant (e.g. menu items). */
export function statusInteractiveTextClass(color?: KinetixStatusColor): string {
  return INTERACTIVE_TEXT[color as string] ?? "text-foreground";
}

/** Status text on a tinted background (e.g. icon wrappers, stat chips). */
export function statusSoftClass(color?: KinetixStatusColor): string {
  return SOFT[color as string] ?? "text-muted-foreground bg-muted";
}

/** Solid filled button for a status (primary falls back to the primary button). */
export function statusButtonClass(color?: KinetixStatusColor): string {
  return (
    SOLID_BUTTON[color as string] ??
    "bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring/20"
  );
}
