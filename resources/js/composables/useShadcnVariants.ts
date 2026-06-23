/**
 * Class-string helpers mirroring shadcn-vue **new-york-v4** for the non-Reka,
 * "built from scratch" elements (button, badge, input). Kinetix uses these so
 * its buttons/badges/inputs match the official shadcn-vue design exactly,
 * without importing the consumer's per-app `@/components/ui/*` files.
 *
 * Strings are kept literal (Tailwind JIT-safe). `danger`/status variants beyond
 * the shadcn set live in `useStatusColor`.
 */

export type ButtonVariant =
  | "default"
  | "destructive"
  | "outline"
  | "secondary"
  | "ghost"
  | "link"
  // Kinetix status variants (extend shadcn with the success/warning/info tokens).
  | "success"
  | "warning"
  | "info";

export type ButtonSize =
  | "default"
  | "sm"
  | "lg"
  | "icon"
  | "icon-sm"
  | "icon-lg";

const BUTTON_BASE =
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive";

const BUTTON_VARIANTS: Record<ButtonVariant, string> = {
  default: "bg-primary text-primary-foreground hover:bg-primary/90",
  destructive:
    "bg-destructive text-white hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40 dark:bg-destructive/60",
  outline:
    "border border-input bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50",
  secondary: "bg-secondary text-secondary-foreground hover:bg-secondary/80",
  ghost: "hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50",
  link: "text-primary underline-offset-4 hover:underline",
  success:
    "bg-success text-success-foreground hover:bg-success/90 focus-visible:ring-success/20",
  warning:
    "bg-warning text-warning-foreground hover:bg-warning/90 focus-visible:ring-warning/20",
  info: "bg-info text-info-foreground hover:bg-info/90 focus-visible:ring-info/20",
};

/**
 * Map a Kinetix action `color` (primary/danger/success/warning/info/gray/
 * secondary) to the matching button variant. `gray` → outline.
 */
export function actionButtonVariant(color?: string | null): ButtonVariant {
  switch (color) {
    case "danger":
      return "destructive";
    case "success":
      return "success";
    case "warning":
      return "warning";
    case "info":
      return "info";
    case "gray":
      return "outline";
    case "secondary":
      return "secondary";
    case "link":
      return "link";
    default:
      return "default";
  }
}

/** Map a Kinetix action `size` (xs/sm/md/lg) to a shadcn button size. */
export function actionButtonSize(size?: string | null): ButtonSize {
  switch (size) {
    case "xs":
    case "sm":
      return "sm";
    case "lg":
      return "lg";
    default:
      return "default";
  }
}

const BUTTON_SIZES: Record<ButtonSize, string> = {
  default: "h-9 px-4 py-2 has-[>svg]:px-3",
  sm: "h-8 rounded-md gap-1.5 px-3 has-[>svg]:px-2.5",
  lg: "h-10 rounded-md px-6 has-[>svg]:px-4",
  icon: "size-9",
  "icon-sm": "size-8",
  "icon-lg": "size-10",
};

export function buttonVariants(
  options: { variant?: ButtonVariant; size?: ButtonSize } = {},
): string {
  const { variant = "default", size = "default" } = options;

  return `${BUTTON_BASE} ${BUTTON_VARIANTS[variant]} ${BUTTON_SIZES[size]}`;
}

export type BadgeVariant = "default" | "secondary" | "destructive" | "outline";

const BADGE_BASE =
  "inline-flex items-center justify-center rounded-full border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden";

const BADGE_VARIANTS: Record<BadgeVariant, string> = {
  default:
    "border-transparent bg-primary text-primary-foreground [a&]:hover:bg-primary/90",
  secondary:
    "border-transparent bg-secondary text-secondary-foreground [a&]:hover:bg-secondary/90",
  destructive:
    "border-transparent bg-destructive text-white [a&]:hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40 dark:bg-destructive/60",
  outline:
    "text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground",
};

export function badgeVariants(
  options: { variant?: BadgeVariant } = {},
): string {
  const { variant = "default" } = options;

  return `${BADGE_BASE} ${BADGE_VARIANTS[variant]}`;
}

/** shadcn-vue new-york-v4 input field classes. */
export const inputClass =
  "file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive";

/** shadcn-vue new-york-v4 textarea field classes. */
export const textareaClass =
  "border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:bg-input/30 flex field-sizing-content min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 md:text-sm";
