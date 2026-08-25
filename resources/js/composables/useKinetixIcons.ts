import {
    Activity,
    AlertCircle,
    AlertTriangle,
    ArrowDown,
    ArrowDownRight,
    ArrowUp,
    ArrowUpRight,
    Book,
    BookOpen,
    Calendar,
    ChartBar,
    ChartColumn,
    Check,
    CheckCircle2,
    ChevronDown,
    Circle,
    Clock,
    Copy,
    CreditCard,
    DollarSign,
    Download,
    Edit3,
    Eye,
    ExternalLink,
    Filter,
    Info,
    Link,
    Mail,
    MoreVertical,
    Package,
    Pencil,
    Percent,
    Phone,
    Plus,
    RotateCcw,
    Settings,
    ShoppingBag,
    ShoppingCart,
    SlidersHorizontal,
    Star,
    Unlink,
    Trash2,
    TrendingDown,
    TrendingUp,
    Upload,
    User,
    Users,
    Wallet,
    X,
    XCircle,
    // Vocabulary a line-of-business app declares constantly — commerce
    // (store/printer/receipt/truck), org charts (building/briefcase), status
    // (shield/ban/history) — shipped because "the icon silently disappears"
    // is a worse default than a few kilobytes.
    Archive,
    ArrowLeftRight,
    Ban,
    Banknote,
    Briefcase,
    Building,
    Building2,
    Crown,
    FileText,
    Globe,
    Grid3x3,
    HandCoins,
    Heart,
    HelpCircle,
    History,
    Home,
    LockOpen,
    MinusCircle,
    MoreHorizontal,
    Pill,
    Printer,
    Receipt,
    RefreshCw,
    Shield,
    ShieldCheck,
    ShoppingBasket,
    Snowflake,
    Sparkles,
    Stethoscope,
    Store,
    Table,
    Truck,
    UserCheck,
    UtensilsCrossed,
    Webhook,
    Wrench,
} from '@lucide/vue';
import type { Component } from 'vue';

/**
 * Shared Lucide icon map for Kinetix actions/columns. Single source of truth so
 * every action-rendering component (table, dropdown, page header) resolves the
 * same names — prevents "missing icon" drift when prebuilt actions add icons.
 *
 * Names mirror the kebab-case strings actions/columns set via `->icon('name')`.
 */
const ICONS: Record<string, Component> = {
    edit: Edit3,
    pencil: Pencil,
    delete: Trash2,
    trash: Trash2,
    'trash-2': Trash2,
    view: Eye,
    eye: Eye,
    create: Plus,
    plus: Plus,
    download: Download,
    upload: Upload,
    restore: RotateCcw,
    'rotate-ccw': RotateCcw,
    settings: Settings,
    filter: Filter,
    'sliders-horizontal': SlidersHorizontal,
    'ellipsis-vertical': MoreVertical,
    'more-vertical': MoreVertical,
    'chevron-down': ChevronDown,
    'arrow-up': ArrowUp,
    'arrow-down': ArrowDown,
    check: Check,
    'check-circle': CheckCircle2,
    x: X,
    'x-circle': XCircle,
    circle: Circle,
    star: Star,
    copy: Copy,
    link: Link,
    unlink: Unlink,
    'external-link': ExternalLink,
    mail: Mail,
    phone: Phone,
    calendar: Calendar,
    user: User,
    users: Users,
    info: Info,
    'credit-card': CreditCard,
    // Dashboard / widget icons
    'dollar-sign': DollarSign,
    wallet: Wallet,
    'shopping-cart': ShoppingCart,
    'shopping-bag': ShoppingBag,
    package: Package,
    cube: Package,
    box: Package,
    clock: Clock,
    activity: Activity,
    'alert-circle': AlertCircle,
    'alert-triangle': AlertTriangle,
    'trending-up': TrendingUp,
    'trending-down': TrendingDown,
    'arrow-up-right': ArrowUpRight,
    'arrow-down-right': ArrowDownRight,
    percent: Percent,
    book: Book,
    'book-open': BookOpen,
    'chart-bar': ChartBar,
    'chart-column': ChartColumn,
    'bar-chart': ChartColumn,
    // Commerce / point of sale
    store: Store,
    printer: Printer,
    receipt: Receipt,
    banknote: Banknote,
    'hand-coins': HandCoins,
    'shopping-basket': ShoppingBasket,
    truck: Truck,
    archive: Archive,
    // Organization
    building: Building,
    'building-2': Building2,
    briefcase: Briefcase,
    home: Home,
    globe: Globe,
    crown: Crown,
    // Status / permissions
    ban: Ban,
    shield: Shield,
    'shield-check': ShieldCheck,
    'lock-open': LockOpen,
    'minus-circle': MinusCircle,
    'check-circle-2': CheckCircle2,
    'user-check': UserCheck,
    // Actions / layout
    'refresh-cw': RefreshCw,
    refresh: RefreshCw,
    'arrow-left-right': ArrowLeftRight,
    history: History,
    'more-horizontal': MoreHorizontal,
    'ellipsis-horizontal': MoreHorizontal,
    table: Table,
    'grid-3x3': Grid3x3,
    'file-text': FileText,
    'help-circle': HelpCircle,
    webhook: Webhook,
    wrench: Wrench,
    sparkles: Sparkles,
    heart: Heart,
    snowflake: Snowflake,
    // Health / hospitality
    stethoscope: Stethoscope,
    pill: Pill,
    'utensils-crossed': UtensilsCrossed,
};

/**
 * Host-registered icons, merged on top of the shipped map — see
 * {@see registerIcons}. Kept as a separate object so a host registration is
 * never lost to `vendor:publish --force`: the shipped map stays untouched.
 */
const EXTRA: Record<string, Component> = {};

/**
 * Register additional icon names, so `->icon('my-name')` in PHP resolves to a
 * component the package does not ship.
 *
 * Call it ONCE from your app's entry point (`resources/js/app.ts`) — a file
 * Kinetix never publishes, so an upgrade cannot drop the registration:
 *
 *     import { RefreshCw, Printer } from '@lucide/vue';
 *     import { registerIcons } from '@/composables/useKinetixIcons';
 *
 *     registerIcons({ 'refresh-cw': RefreshCw, printer: Printer });
 *
 * Names are matched case-insensitively, like the shipped ones. Registering a
 * name Kinetix already ships overrides it, which is the supported way to swap
 * an icon (or to use a set other than Lucide) without editing a published
 * component. Icons are passed as COMPONENTS, not strings, so your bundler still
 * tree-shakes the ones you don't use.
 */
export function registerIcons(icons: Record<string, Component>): void {
    for (const [name, icon] of Object.entries(icons)) {
        EXTRA[name.toLowerCase()] = icon;
    }
}

/**
 * Whether an action should render as icon-ONLY.
 *
 * `false` when the icon cannot be resolved, even for an `->iconButton()`:
 * an icon button hides its label, so an icon that resolves to nothing paints a
 * control with no pixels at all — present, clickable, invisible. Degrading to a
 * normal labelled button keeps it usable, and callers get one predicate for the
 * three places that have to agree (the button's size variant, its
 * `title`/`aria-label`, and whether the label renders).
 */
export function isIconOnlyAction(action: {
    icon?: string | null;
    isIconButton?: boolean;
}): boolean {
    return action.isIconButton === true && resolveIcon(action.icon) !== null;
}

/**
 * Every icon name that currently resolves — shipped plus registered. Useful in
 * a host test that asserts every `->icon('…')` the app declares is resolvable.
 */
export function registeredIconNames(): string[] {
    return [...new Set([...Object.keys(ICONS), ...Object.keys(EXTRA)])].sort();
}

/**
 * Names already warned about, so an unresolvable icon in a table row logs once
 * instead of on every re-render.
 */
const warned = new Set<string>();

/**
 * Resolve an icon component by name: anything the host registered with
 * {@see registerIcons} first, then the shipped map. Returns null for an empty or
 * unknown name, so callers guard on the RESOLVED COMPONENT — never on the name.
 *
 * Guarding on the name is what made an unknown icon invisible: an icon-only
 * button rendered nothing for the icon AND nothing for the label (an icon
 * button hides it), so the control was there but had no pixels. A non-empty
 * name that cannot be resolved therefore warns once in development, naming the
 * fix.
 */
export function resolveIcon(name?: string | null): Component | null {
    if (!name) {
        return null;
    }

    // Registered icons win over the shipped ones: a host registration is an
    // explicit choice, which makes this the supported way to SWAP an icon (or
    // to use a set other than Lucide) without editing a published component.
    const key = name.toLowerCase();
    const icon = EXTRA[key] ?? ICONS[key] ?? null;

    if (icon === null && !warned.has(key) && import.meta.env?.DEV) {
        warned.add(key);
        console.warn(
            `[kinetix] Unknown icon "${name}". Register it once from your app entry point: ` +
                `import { registerIcons } from '@/composables/useKinetixIcons'; ` +
                `registerIcons({ '${key}': YourIcon }).`,
        );
    }

    return icon;
}
