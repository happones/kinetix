export interface KinetixAction {
    name: string;
    label: string;
    icon?: string;
    iconPosition?: 'before' | 'after';
    url?: string;
    shouldOpenInNewTab: boolean;
    color: string;
    size?: 'xs' | 'sm' | 'md' | 'lg';
    viewType: 'button' | 'link';
    shouldClose: boolean;
    shouldMarkAsRead: boolean;
    shouldMarkAsUnread: boolean;
    dispatchEvent?: string;
    dispatchData?: Record<string, unknown>;
    inertiaVisit?: { method?: string; [key: string]: unknown };
    httpRequest?: { method?: string; toast?: string; [key: string]: unknown };
    requiresConfirmation: boolean;
    modalHeading?: string | null;
    modalDescription?: string | null;
    modalIcon?: string | null;
    modalSubmitActionLabel?: string | null;
    modalCancelActionLabel?: string | null;
    type?: 'action' | 'group';
    actions?: KinetixAction[] | null;
    isDownload?: boolean;
    isPreview?: boolean;
    previewType?: string | null;
    shortcut?: string | null;
    isIconButton?: boolean;
    /** Opens an in-table record modal instead of navigating/dispatching. */
    modal?: 'create' | 'edit' | 'view' | 'delete' | null;
}

export interface KinetixNotification {
    id: string;
    title: string;
    description?: string;
    status: 'info' | 'success' | 'warning' | 'danger';
    duration?: number;
    created_at: string;
    read?: boolean;
    icon?: string;
    iconColor?: string;
    actions?: KinetixAction[];
    type?: string;
    /** Team PRIMARY key the notification is scoped to; null/absent = global. */
    team?: string | number | null;
}

/** Public-safe Kinetix runtime config shared with Inertia page props. */
export interface KinetixConfig {
    database: boolean;
    /** Kinetix's own endpoint prefix, with the team segment already resolved. */
    route_prefix: string;
    /**
     * The active team's ROUTE key (slug/uuid-aware), or null when teams are off.
     * Build app links with `useKinetixTeams().teamUrl()` rather than reading it.
     */
    team: string | number | null;
    /**
     * The active team's PRIMARY key when notifications are team-scoped
     * (matches the `team` stamp on notification payloads); null otherwise.
     */
    team_id: string | number | null;
    /** Database-mode fallback poll interval in ms (0 = polling off). */
    poll: number;
    /** Laravel's app timezone — implicit timezone of naive picker values. */
    timezone?: string | null;
    sound: { enabled: boolean; path: string };
    broadcasting: Record<string, unknown> | null;
}

/**
 * Inertia shared props Kinetix relies on. Pass to `usePage<KinetixSharedProps>()`
 * to type `kinetix_config` / `kinetix_notifications` / `auth` without `as any`.
 */
export interface KinetixSharedProps {
    kinetix_config?: KinetixConfig;
    /**
     * One-shot toast flashed by a controller (`->with('kinetix_toast', …)`);
     * <KinetixToaster /> watches it. The uuid dedupes repeated messages.
     */
    kinetix_toast?: {
        type: 'success' | 'error' | 'info' | 'warning';
        message: string;
        id: string;
    } | null;
    kinetix_notifications?: KinetixNotification[];
    /** Unread badge + banner feed, so neither has to fetch on mount. */
    kinetix_announcements?: KinetixAnnouncementState | null;
    /** The setup checklist, so <KinetixOnboardingChecklist> doesn't fetch on mount. */
    kinetix_onboarding?: KinetixOnboarding | null;
    kinetix_permissions?: KinetixPermissionState;
    kinetix_impersonation?: KinetixImpersonationState;
    /** Resolved feature flags for the current scope (name → on/off). */
    kinetix_features?: Record<string, boolean>;
    /** The billable's current plan (+ features JSON), for useKinetixPlan / <KinetixPlanFeature>. */
    kinetix_billing?: KinetixBillingState;
    /** Authorized product tours + seen ids, for <KinetixTours /> and the tours store. */
    kinetix_tours?: KinetixToursState;
    /** Supported locales + the active one, for the language switcher. */
    kinetix_locale?: KinetixLocaleState;
    /** The user's teams + switch URLs, for the team switcher. */
    kinetix_teams?: KinetixTeamsState;
    /** The presence channel for online indicators. */
    kinetix_presence?: KinetixPresenceState;
    /** Queue widget config (enabled + poll interval). */
    kinetix_queue?: KinetixQueueConfig;
    /** Health widget config (enabled + poll interval). */
    kinetix_health?: KinetixQueueConfig;
    /** Cookie consent bar config, for <KinetixCookieConsent>. */
    kinetix_cookie_consent?: KinetixCookieConsentConfig;
    /** Reports Center config (enabled + poll interval). */
    kinetix_reports_center?: KinetixQueueConfig;
    /** Confidential-fields reveal-gate state, for <KinetixConfidentialUnlock>. */
    kinetix_confidential?: KinetixConfidentialConfig;
    auth?: { user?: { id: number | string } | null };
    [key: string]: unknown;
}

/** A Help Center article summary (index cards / list). */
export interface KinetixHelpArticleSummary {
    slug: string;
    title: string;
    group: string | null;
    icon: string | null;
    excerpt: string;
    /** The language this entry is actually written in. */
    locale: string;
    /** True when that differs from the requested language (untranslated). */
    isFallback: boolean;
}

/** A rendered Help Center article with its prev/next neighbors. */
export interface KinetixHelpArticleDetail {
    slug: string;
    title: string;
    group: string | null;
    html: string;
    /** The language the rendered body is written in (for `lang`/`dir`). */
    locale: string;
    /** The language that was asked for. */
    requestedLocale: string;
    /** True when the body fell back to another language. */
    isFallback: boolean;
    /** Every language this article exists in, for the per-article switcher. */
    availableLocales: string[];
    prev: { slug: string; title: string } | null;
    next: { slug: string; title: string } | null;
}

/** A Help Center search hit. */
export interface KinetixHelpSearchResult {
    slug: string;
    title: string;
    group: string | null;
    excerpt: string;
    locale: string;
    isFallback: boolean;
}

/** One product-tour step (selector + popover content). */
export interface KinetixTourStepData {
    selector: string;
    title: string | null;
    description: string | null;
    side: string | null;
    align: string | null;
}

/** A declared product tour, matched by Inertia page component or URL pattern. */
export interface KinetixTourData {
    id: string;
    /** Inertia component pattern (`*` wildcards), e.g. `Kinetix/Posts/Index`. */
    page: string | null;
    /** URL path pattern (`*` wildcards), e.g. `/posts*`. */
    url: string | null;
    /** Auto-start on the first matching visit (vs manual-only). */
    auto: boolean;
    steps: KinetixTourStepData[];
}

/** The `kinetix_tours` Inertia share. */
export interface KinetixToursState {
    enabled: boolean;
    driver: 'local' | 'database';
    tours: KinetixTourData[];
    /** Seen tour ids (database driver; the local driver reads localStorage). */
    seen: string[];
}

/** How a locked feature is presented by <KinetixPlanLock>. */
export type KinetixPlanLockVariant = 'card' | 'overlay' | 'banner' | 'badge';

/** App-wide <KinetixPlanLock> defaults from `kinetix.billing.lock`. */
export interface KinetixPlanLockDefaults {
    /** Presentation used when an instance sets no `variant`. */
    variant?: KinetixPlanLockVariant;
    /** Whether locks open the upgrade modal instead of linking straight out. */
    modal?: boolean;
    /** Whether the `overlay` variant blurs the content behind the lock. */
    blur?: boolean;
    /** Plan pill shown next to the lock title (e.g. 'Pro'). Null = none. */
    badgeLabel?: string | null;
}

/** The billable's current plan shared via Inertia (`kinetix_billing`). */
export interface KinetixBillingState {
    enabled: boolean;
    plan: {
        slug: string;
        name: string;
        /** The plan's nested features JSON (usage limits + capability flags). */
        features: Record<string, unknown>;
    } | null;
    /** Where <KinetixPlanGate>'s upsell CTA sends users to upgrade. */
    upgradeUrl?: string | null;
    /** Defaults for the <KinetixPlanLock> upsell UI. */
    lock?: KinetixPlanLockDefaults;
}

/** Cookie consent bar config shared via Inertia. */
export interface KinetixCookieConsentConfig {
    enabled: boolean;
    cookieName?: string;
    expiryDays?: number;
    position?: 'bottom' | 'top';
    policyUrl?: string | null;
}

/** A single application-health check result. */
export interface KinetixHealthCheck {
    name: string;
    label: string;
    status: string;
    message: string | null;
}

/** A live application-health snapshot from the metrics endpoint. */
export interface KinetixHealthSnapshot {
    available: boolean;
    status: 'ok' | 'warning' | 'failed' | null;
    checkedAt: string | null;
    checks: KinetixHealthCheck[];
}

/** Queue widget config shared via Inertia. */
export interface KinetixQueueConfig {
    enabled: boolean;
    poll: number;
}

/** Confidential-fields reveal-gate state, shared via Inertia. */
export interface KinetixConfidentialConfig {
    enabled: boolean;
    ttlMinutes: number;
    /** ISO timestamp the current reveal window expires at, or `null` if locked. */
    unlockedUntil: string | null;
}

/** A monitored queue and its current depth/wait. */
export interface KinetixQueueRow {
    name: string;
    connection: string | null;
    size: number;
    wait: number | null;
}

/** A failed job, for the retry/delete list. */
export interface KinetixFailedJob {
    id: number | string;
    connection: string | null;
    queue: string | null;
    name: string;
    failedAt: string | null;
}

/** A live queue-health snapshot from the metrics endpoint. */
export interface KinetixQueueSnapshot {
    horizon: boolean;
    status: 'running' | 'paused' | 'inactive' | null;
    throughput: number | null;
    recentJobs: number | null;
    failedJobs: number;
    failed: KinetixFailedJob[];
    queues: KinetixQueueRow[];
}

/** A member present on the presence channel. */
export interface KinetixPresenceUser {
    id: number | string;
    name: string;
    avatar: string | null;
}

/** Presence state shared via Inertia for <KinetixOnlineUsers>. */
export interface KinetixPresenceState {
    enabled: boolean;
    channel: string | null;
}

/** A team the user can switch to, with a ready-made switch URL. */
export interface KinetixTeamOption {
    id: number | string;
    name: string;
    url: string | null;
    current: boolean;
}

/** Team-switcher state shared via Inertia for <KinetixTeamSwitcher>. */
export interface KinetixTeamsState {
    enabled: boolean;
    teams: KinetixTeamOption[];
    current: { id: number | string; name: string } | null;
    createUrl: string | null;
}

/** A single breadcrumb item, derived from a Kinetix Resource. */
export interface KinetixBreadcrumb {
    title: string;
    href: string;
}

/** A selectable locale: its code and native label. */
export interface KinetixLocaleOption {
    code: string;
    label: string;
}

/** Locale state shared via Inertia for <KinetixLanguageSwitcher>. */
export interface KinetixLocaleState {
    enabled: boolean;
    current: string | null;
    locales: KinetixLocaleOption[];
}

/** The authenticated user's resolved permissions, shared via Inertia. */
export interface KinetixPermissionState {
    enabled: boolean;
    permissions: string[];
    roles: string[];
    /** True when the user holds the super-admin role (server Gate::before bypass). */
    isSuperAdmin?: boolean;
}

export interface KinetixPermissionAbility {
    key: string;
    label: string;
    permission: string;
}

/** A permission feature (resource/module) and its abilities, for the matrix UI. */
export interface KinetixPermissionFeature {
    name: string;
    label: string;
    abilities: KinetixPermissionAbility[];
    /** Optional section title for the role-management UIs. */
    group?: string | null;
}

export interface KinetixRole {
    id: number | string | null;
    name: string;
    permissions: string[];
    usersCount?: number | null;
    /** With spatie teams active: a team-NULL role visible in every team (super-admin-only to modify). */
    isGlobal?: boolean;
    /** Client-only, on CREATE: request a global (team-NULL) role — super-admin only. */
    global?: boolean;
}

/** Impersonation state shared from the server (for the banner). */
export interface KinetixImpersonationState {
    active: boolean;
    user?: { id: number | string | null; name: string | null };
}

/** A customer webhook endpoint (the secret is never included). */
export interface KinetixWebhookEndpoint {
    id: number | string | null;
    name: string;
    url: string;
    events: string[];
    active: boolean;
    createdAt: string | null;
}

/** A webhook delivery attempt. */
export interface KinetixWebhookLog {
    id: number | string | null;
    event: string;
    statusCode: number | null;
    success: boolean;
    attempt: number;
    createdAt: string | null;
    payload?: Record<string, unknown> | null;
    response?: string | null;
    endpointName?: string | null;
    endpointUrl?: string | null;
}

/** One configurable knob of a PDF template. */
export interface KinetixPdfField {
    name: string;
    type: 'color' | 'text' | 'select' | 'toggle' | 'number';
    label: string;
    default: unknown;
    help: string | null;
    palette: string[];
    options: Record<string, string>;
    maxLength: number | null;
}

/** A registered PdfTemplate's descriptor for <KinetixPdfTemplate>. */
export interface KinetixPdfTemplateData {
    key: string;
    label: string;
    fields: KinetixPdfField[];
    settings: Record<string, unknown>;
    defaults: Record<string, unknown>;
    hasLogo: boolean;
}

/** One logged API request (kinetix.api-log middleware). */
export interface KinetixApiLog {
    id: number | string | null;
    method: string;
    path: string;
    status: number;
    durationMs: number | null;
    tokenName: string | null;
    ip: string | null;
    requestBody: Record<string, unknown> | null;
    responseBody: string | null;
    createdAt: string | null;
}

/** A step shown in the <KinetixWizard> indicator. */
export interface KinetixWizardStep {
    key?: string;
    label: string;
    description?: string | null;
    icon?: string | null;
    /**
     * Accent color for this step's indicator once active/complete
     * (`success` · `danger` · `warning` · `info` · `primary` · `gray`).
     * Defaults to `primary`. `stepper` variant only.
     */
    color?: string | null;
}

/** Visual style of the wizard step indicator. */
export type KinetixWizardVariant =
    | 'stepper'
    | 'default'
    | 'simple'
    | 'vertical'
    | 'panels'
    | 'gradient';

/**
 * How each step's indicator + label are arranged — `stepper` variant,
 * horizontal orientation only (vertical already places the label beside the
 * indicator column and is unaffected):
 * - `inline` (default): indicator + label side by side; label hidden below `sm:`.
 * - `stacked`: indicator on top, label/description centered below — always
 *   visible, truncated to one line.
 * - `tooltip`: indicator only; label/description shown in a hover/focus
 *   tooltip — the most compact option for many steps on narrow viewports.
 */
export type KinetixWizardStepLayout = 'inline' | 'stacked' | 'tooltip';

/** Per-user accessibility preferences. */
export interface KinetixAccessibility {
    reducedMotion: boolean;
    highContrast: boolean;
    textSize: 'normal' | 'large' | 'x-large';
    underlineLinks: boolean;
    enhancedFocus: boolean;
}

/** A single first-run onboarding checklist step. */
export interface KinetixOnboardingStep {
    key: string;
    title: string;
    description: string | null;
    ctaLabel: string | null;
    ctaHref: string | null;
    icon: string | null;
    completed: boolean;
    manual: boolean;
}

/** The onboarding checklist state for the current user. */
export interface KinetixOnboarding {
    steps: KinetixOnboardingStep[];
    completedCount: number;
    total: number;
    complete: boolean;
    dismissed: boolean;
}

/**
 * The announcements payload shared on every Inertia response
 * (`kinetix.announcements.share`).
 */
export interface KinetixAnnouncementState {
    unread: number;
    /** The `limit` the shared banner feed was built with. */
    bannerLimit: number;
    banner: KinetixAnnouncement[];
}

/**
 * An announcement as its author edits it — drafts and scheduled entries
 * included, which the reader feed never returns.
 */
export interface KinetixEditableAnnouncement {
    /** `null` until the entry is created. */
    id: number | string | null;
    title: string;
    body: string;
    level: string;
    /** `null` = draft; a future date = scheduled. */
    publishedAt: string | null;
    /** `null` never expires; otherwise the entry leaves the feed on its own. */
    expiresAt?: string | null;
    /** Platform-wide (visible to every team); read-only inside a team. */
    isGlobal?: boolean;
    status?: 'draft' | 'scheduled' | 'published' | 'expired';
}

/** A product announcement ("what's new" entry). */
export interface KinetixAnnouncement {
    id: number | string | null;
    title: string;
    body: string;
    level: string;
    publishedAt: string | null;
    isNew: boolean;
}

/**
 * A calendar event. `start`/`end` are absolute-instant ISO-8601 datetimes
 * (with UTC offset) — safe to re-render in any timezone client-side.
 */
export interface KinetixCalendarEvent {
    id: number | string;
    title: string;
    start: string;
    end: string | null;
    /** True when start/end fall exactly at midnight (no meaningful time-of-day). */
    allDay: boolean;
    color: string | null;
    url: string | null;
    description: string | null;
    /** Per-event actions (edit/delete/custom) resolved via `Calendar::eventActions()`. */
    actions: KinetixAction[];
}

/** A calendar: a list of events the component lays out by month/week/day. */
export interface KinetixCalendarData {
    heading: string | null;
    events: KinetixCalendarEvent[];
    /** IANA timezone events were resolved against server-side. */
    timezone: string;
    /**
     * Encrypted move descriptor — present only when the calendar opted into
     * drag-and-drop rescheduling via `Calendar::moveable()`.
     */
    model?: string | null;
}

/** Which day/week/month view is active in <KinetixEventCalendar>. */
export type KinetixCalendarView = 'month' | 'week' | 'day';

/** How <KinetixEventCalendar> presents an event's details on click. */
export type KinetixCalendarEventDisplay = 'modal' | 'sheet';

/** Which edge <KinetixSheet> slides in from. */
export type KinetixSheetSide = 'top' | 'right' | 'bottom' | 'left';

/** What each <KinetixTimezonePicker> option (and its trigger) shows. */
export type KinetixTimezoneDisplay = 'name' | 'offset' | 'both';

/** A Kanban card. */
export interface KinetixKanbanCard {
    id: number | string;
    title: string;
    description: string | null;
}

/** A Kanban column (a status) with its cards. */
export interface KinetixKanbanColumn {
    key: string;
    label: string;
    color: string | null;
    cards: KinetixKanbanCard[];
}

/** A Kanban board: columns + a signed model descriptor for the move endpoint. */
export interface KinetixKanbanData {
    heading: string | null;
    columns: KinetixKanbanColumn[];
    model: string;
}

/** A saved table view — a named snapshot of the table's state. */
export interface KinetixSavedView {
    id: number | string | null;
    name: string;
    state: Record<string, unknown>;
    isDefault: boolean;
}

/** The per-user notification preference matrix (types × channels). */
export interface KinetixNotificationPreferences {
    channels: { key: string; label: string }[];
    types: {
        key: string;
        label: string;
        channels: Record<string, boolean>;
    }[];
}

/** A comment (with its threaded replies) on a commentable model. */
export interface KinetixComment {
    id: number | string | null;
    body: string;
    authorId: number | string | null;
    authorName: string | null;
    authorAvatar: string | null;
    parentId: number | string | null;
    createdAt: string | null;
    edited: boolean;
    editable: boolean;
    replies: KinetixComment[];
}

/** An active browser session (from Laravel's sessions table). */
export interface KinetixBrowserSession {
    id: string;
    ipAddress: string | null;
    browser: string;
    platform: string;
    device: 'desktop' | 'mobile' | 'tablet';
    isCurrentDevice: boolean;
    lastActive: string | null;
}

/** A user's linked OAuth identity (tokens are never included). */
export interface KinetixConnectedAccount {
    id: number | string | null;
    provider: string;
    name: string | null;
    nickname: string | null;
    email: string | null;
    avatar: string | null;
    createdAt: string | null;
}

/** An OAuth provider offered for linking / login, with its linked state. */
export interface KinetixConnectedProvider {
    key: string;
    label: string;
    icon: string | null;
    color: string | null;
    linked: boolean;
}

/** A personal access token (the plaintext value is never included). */
export interface KinetixToken {
    id: number | string | null;
    name: string;
    abilities: string[];
    lastUsedAt: string | null;
    createdAt: string | null;
    expiresAt?: string | null;
}

/** A spotlight result item. */
export interface KinetixSpotlightItem {
    type: string;
    group: string;
    title: string;
    subtitle: string | null;
    url: string | null;
    event: string | null;
    icon: string | null;
    id: number | string | null;
}

/** A group of spotlight results. */
export interface KinetixSpotlightGroup {
    label: string;
    items: KinetixSpotlightItem[];
}

/** A single activity entry from the Activity module. */
export interface KinetixActivityEntry {
    id: number | string | null;
    event: string;
    description: string | null;
    causerName: string | null;
    causerId: number | string | null;
    subjectType: string | null;
    subjectId: number | string | null;
    changes: {
        old: Record<string, unknown>;
        attributes: Record<string, unknown>;
    };
    createdAt: string | null;
}

/** The paginated activity feed response. */
export interface KinetixActivityResponse {
    data: KinetixActivityEntry[];
    pagination: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

/** A settings page (metadata + its filled form) from the Settings module. */
export interface KinetixSettingsPageData {
    key: string;
    title: string;
    icon: string;
    form: {
        schema: unknown[];
        data: Record<string, unknown>;
        rules: Record<string, unknown>;
        operation: string;
    };
}

/** A provisioned member (pending/active/revoked) from the Membership module. */
export interface KinetixMemberProvision {
    id: number | string | null;
    email: string;
    name: string | null;
    role: string;
    status: 'pending' | 'active' | 'revoked';
    expired: boolean;
    activatedAt: string | null;
    expiresAt: string | null;
}

export interface KinetixStat {
    label: string;
    value: string | number;
    description?: string;
    descriptionIcon?: string;
    descriptionColor?: 'success' | 'danger' | 'warning' | 'info' | 'gray';
    icon?: string | null;
    iconColor?: string | null;
    badge?: string | null;
    badgeColor?: string | null;
    linkLabel?: string | null;
    linkUrl?: string | null;
    chart?: number[];
}

/** A link/button action shown in a widget header. */
export interface KinetixWidgetAction {
    label: string;
    url: string;
    icon?: string | null;
}

/** A star-level row in a KinetixRatingWidget breakdown. */
export interface KinetixRatingLevel {
    level: number;
    count: number;
    pct: number;
}

/** A declared variable on a mail template. */
export interface KinetixMailVariable {
    key: string;
    label?: string | null;
    sample?: string | null;
}

/** An editable mail template. */
export interface KinetixMailTemplate {
    id?: number | string | null;
    key: string;
    name: string;
    subject: string;
    body: string;
    format: 'markdown' | 'html';
    variables: KinetixMailVariable[];
    enabled: boolean;
}

/** A headline metric shown in a chart widget header. */
export interface KinetixChartMetric {
    label: string;
    value: string;
    badge?: string | null;
    badgeColor?: string | null;
}

/**
 * One series in a chart widget's `datasets` array.
 *
 * Colours accept an array because per-point palettes are valid for bar/pie
 * charts, and the index signature keeps room for extra keys a host puts in the
 * widget's open `data` payload.
 */
export interface KinetixChartDataset {
    label?: string | null;
    data: (number | string | null)[];
    borderColor?: string | string[] | null;
    backgroundColor?: string | string[] | null;
    borderWidth?: number;
    [key: string]: unknown;
}

/** The `data` payload of a `chart` widget. */
export interface KinetixChartData {
    labels?: string[];
    datasets?: KinetixChartDataset[];
    chartType?: string;
    stacked?: boolean;
    /** true/false forces the legend; null/absent = auto (shown for ≥ 2 entries). */
    legend?: boolean | null;
    centerValue?: string | null;
    centerLabel?: string | null;
    metrics?: KinetixChartMetric[];
}

/**
 * One x-position in the series data handed to the chart canvas. Labels are
 * mapped to numeric indices (`x`) because a continuous scale yields NaN for
 * string categories; each series' value lands under `y_<datasetIndex>`.
 */
export interface KinetixChartPoint {
    x: number;
    label: string;
    [series: `y_${number}`]: number | string | null;
}

/** One slice of a pie/donut chart. */
export interface KinetixChartSlice {
    label: string;
    value: number | string | null;
}

/** A row in a KinetixListWidget. */
export interface KinetixListItem {
    title: string;
    subtitle?: string | null;
    icon?: string | null;
    iconColor?: string | null;
    value?: string | null;
    badge?: string | null;
    badgeColor?: string | null;
    progress?: number | null;
    url?: string | null;
}

/** A goal/quota progress widget payload. */
export interface KinetixProgressData {
    value: number;
    target: number;
    percent: number;
    display: string;
    caption?: string | null;
    color: string;
    ring: boolean;
}

export interface KinetixWidget {
    id: string;
    type:
        | 'stats'
        | 'chart'
        | 'table'
        | 'custom'
        | 'list'
        | 'rating'
        | 'hero'
        | 'progress'
        | 'queue-stats'
        | 'health-status';
    title?: string;
    description?: string;
    columnSpan: number | string | Record<string, number | string>;
    sort: number;
    headerActions?: KinetixWidgetAction[];
    data: any;
}

export interface KinetixWidgetsGridData {
    columns: number | Record<string, number>;
    gap: number | string | Record<string, number | string>;
    /** `masonry` ignores each widget's `columnSpan` — one widget per column, packed by height. */
    layout: 'grid' | 'masonry';
    /** `grid-auto-flow: dense` — only applies to `layout: 'grid'`. */
    dense: boolean;
    /** Number of masonry columns (only used when `layout === 'masonry'`) — distinct from `columns`. */
    masonryColumns: number | Record<string, number>;
    widgets: KinetixWidget[];
}

export interface KinetixTableColumn {
    name: string;
    label: string;
    isSearchable: boolean;
    isSortable: boolean;
    alignment: 'left' | 'center' | 'right';
    isToggleable: boolean;
    isToggledHiddenByDefault: boolean;
    type: string;
    isBadge?: boolean;
    isConfidential?: boolean;
    hasSummary?: boolean;
    view?: string | null;
}

export interface KinetixTableFilter {
    name: string;
    label: string;
    default: any;
    type:
        | 'checkbox'
        | 'select'
        | 'ternary'
        | 'multi-select'
        | 'date'
        | 'datetime'
        | 'date-range'
        | 'number-range'
        | 'month'
        | 'year'
        | 'week'
        | 'address';
    options?: Record<string, string>;
    useCalendar?: boolean;
    numberOfMonths?: number;
    locale?: string | null;
    weekdayFormat?: 'narrow' | 'short' | 'long' | null;
    fixedWeeks?: boolean;
    minValue?: string | null;
    maxValue?: string | null;
    minuteStep?: number;
    hour12?: boolean;
    /** Date/time pickers: commit only via the Apply button (draft until then). */
    confirm?: boolean;
    /** DatePicker: show a Today shortcut in the popover footer. */
    showToday?: boolean;
    /** DatePicker: whether picking a date closes the popover (default true). */
    closeOnSelect?: boolean;
    /** Date/time pickers: IANA timezone the Today/Now presets read the clock in. */
    timezone?: string | null;
    isSearchable?: boolean;
    searchToken?: string | null;
    weekStartsOn?: number | null;
}

export interface KinetixTableRecord {
    id: string | number;
    values: Record<string, any>;
    icons: Record<string, string | null>;
    iconColors: Record<string, string>;
    badgeColors: Record<string, string>;
    progress: Record<string, number | null>;
    progressColors: Record<string, string>;
    viewProps: Record<string, Record<string, any>>;
    descriptions: Record<
        string,
        { text: string | null; position: 'above' | 'below' }
    >;
    recordUrl: string | null;
    actions?: KinetixAction[];
}

/** One entry in a KinetixMediaLibrary value (an ordered array of these). */
export interface KinetixMediaItem {
    id?: number | string | null;
    path?: string | null;
    url: string;
    name: string;
    size?: number | null;
    mime?: string | null;
    thumb?: string | null;
}

/**
 * The column slice a cell renderer needs. Narrower than `KinetixTableColumn`
 * (which also carries table-level concerns like sorting and toggling) and
 * additionally carries the per-type rendering options.
 */
export interface KinetixTableCellColumn {
    name: string;
    label: string;
    type: string;
    isBadge?: boolean;
    alignment?: 'left' | 'center' | 'right' | null;
    isCircular?: boolean;
    isPreviewable?: boolean;
    size?: number | string | null;
    isCopyable?: boolean;
    isConfidential?: boolean;
    options?: Record<string, string> | null;
    inputType?: string | null;
    placeholder?: string | null;
    numberConfig?: Record<string, unknown> | null;
    view?: string | null;
    /** Static hover tooltip (title attribute). */
    tooltip?: string | null;
    /** TextColumn: render the value as (trusted) HTML. */
    isHtml?: boolean | null;
    /** TextColumn: allow multi-line wrapping. */
    wrap?: boolean | null;
    /** TextColumn: open the per-record cell link in a new tab. */
    openUrlInNewTab?: boolean | null;
}

/** A per-column description rendered above or below a cell's value. */
export interface KinetixTableCellDescription {
    text: string | null;
    position: 'above' | 'below';
}

/** The record slice a cell renderer reads, keyed by column name. */
export interface KinetixTableCellRecord {
    id: string | number;
    values: Record<string, any>;
    descriptions: Record<string, KinetixTableCellDescription | null>;
    icons: Record<string, string | null>;
    iconColors: Record<string, string | null>;
    badgeColors: Record<string, string | null>;
    progress: Record<string, number | null>;
    progressColors: Record<string, string | null>;
    viewProps: Record<string, Record<string, any>>;
    /** Per-column cell links (TextColumn::url()). */
    urls?: Record<string, string | null>;
}

export interface KinetixImportColumn {
    name: string;
    label: string;
    isRequired: boolean;
    guesses: string[];
}

export interface KinetixImportOptions {
    delimiter: string;
    enclosure: string;
    skipLines: number;
    hasHeader: boolean;
}

export interface KinetixImportPreview {
    headers: string[];
    rows: (string | null)[][];
    columns: KinetixImportColumn[];
    options: KinetixImportOptions;
    autoMapping: Record<string, number | null>;
    fileToken: string;
    totalRows: number;
}

export interface KinetixInfolistEntry {
    type:
        | 'text'
        | 'icon'
        | 'image'
        | 'color'
        | 'section'
        | 'grid'
        | 'fieldset'
        | 'tabs'
        | 'tab';
    name?: string;
    label?: string;
    columnSpan: number | string | Record<string, number | string>;
    state?: any;
    placeholder?: string;
    icon?: string | null;
    color?: string | null;
    url?: string | null;
    openUrlInNewTab: boolean;
    isBadge?: boolean | null;
    isCopyable?: boolean | null;
    isCircular?: boolean | null;
    isConfidential?: boolean | null;
    size?: number | string | null;
    isInline: boolean;
    extraAttributes?: Record<string, string> | null;
    // Layout components
    schema?: KinetixInfolistEntry[] | null;
    heading?: string | null;
    description?: string | null;
    columns?: number | Record<string, number> | null;
    actions?: KinetixAction[];
}

export interface KinetixInfolistData {
    schema: KinetixInfolistEntry[];
    /** Column count, or a breakpoint map (default/sm/md/lg/xl/2xl). */
    columns: number | Record<string, number>;
    operation: string;
}

export interface KinetixTableData {
    heading: string | null;
    description: string | null;
    poll: string | null;
    isStriped: boolean;
    model: string;
    columns: KinetixTableColumn[];
    filters: KinetixTableFilter[];
    recordActions: KinetixAction[];
    toolbarActions: KinetixAction[];
    bulkActions: KinetixAction[];
    footerActions?: KinetixAction[];
    records: KinetixTableRecord[];
    isPaginated: boolean;
    paginationPageOptions: number[];
    pagination: {
        perPage: number;
        /** Present in every mode — prefer it over comparing currentPage/lastPage. */
        hasMore: boolean;
        /** Null in cursor mode: a cursor has no page number. */
        currentPage: number | null;
        /** Null in simple and cursor modes — no COUNT(*) is run. */
        total: number | null;
        /** Null in simple and cursor modes. */
        lastPage: number | null;
        /** Null in cursor mode — a seek has no offsets to report. */
        from: number | null;
        to: number | null;
        /** Opaque seek positions; only cursor mode sets them. */
        nextCursor: string | null;
        prevCursor: string | null;
        /** Cursor mode has no page number, so "can I go back" is explicit. */
        onFirstPage: boolean | null;
    } | null;
    state: {
        search: string;
        sort: string;
        direction: 'asc' | 'desc';
        filters: Record<string, any>;
        perPage: number;
    };
    queryPrefix: string;
    stickyActions?: boolean;
    summaries?: Record<string, KinetixSummary[]>;
    hasSummaries?: boolean;
    /** KPI cards rendered above the table (Table::stats()). */
    stats?: KinetixTableStat[];
    reorderable?: boolean;
    savedViewsKey?: string | null;
    /** Toolbar arrangement: 'auto' (container-adaptive) | 'inline' | 'stacked'. */
    toolbarLayout?: 'auto' | 'inline' | 'stacked';
    /** When true, all rows are shipped and a TanStack renderer handles interactions client-side. */
    clientSide?: boolean;
    /** In-table modal CRUD wiring (simple resources). Null/absent = disabled. */
    recordModals?: KinetixRecordModals | null;
    /** Custom empty state (heading/description/icon/CTAs). Null = default text. */
    emptyState?: KinetixTableEmptyState | null;
}

/**
 * Drives the in-table create/edit/view modals for a "simple" resource table.
 * The `token` is a signed { model, resource } descriptor sent with every
 * resolve/store/update/destroy request. See Table::recordModals() (PHP).
 */
export interface KinetixRecordModals {
    enabled: boolean;
    token: string;
    /** 'server' fetches a fresh record; 'row' prefills the edit form from the loaded row. */
    source: 'server' | 'row';
    hasForm: boolean;
    hasInfolist: boolean;
    /** Blueprint form DTO for an instant create modal (no round-trip). */
    createForm?: Record<string, any> | null;
    /**
     * 'resource' → the simple-resource record endpoint; 'relation' → the
     * relation-manager record endpoint (CRUD bound to the parent record).
     */
    scope?: 'resource' | 'relation';
}

/** A computed column summary (sum/average/count/range/custom). */
export interface KinetixSummary {
    label: string | null;
    value: string;
}

/**
 * One KPI card above a table. The value is aggregated and formatted server-side
 * (see Table::stats() / TableStat in PHP), so the card only lays it out.
 */
export interface KinetixTableStat {
    label: string;
    value: string;
    icon?: string | null;
    color?: string;
    description?: string | null;
    descriptionIcon?: string | null;
    /** Renders the description as a colored trend chip (success/danger/…). */
    descriptionColor?: string | null;
    /** Sparkline series, tinted by descriptionColor (falls back to color). */
    chart?: number[];
    url?: string | null;
}

export interface KinetixRelationManagerData {
    title: string;
    relationship: string;
    /** Null while a lazy manager is deferred — only the tab stub shipped. */
    table: KinetixTableData | null;
    /** Badge next to the title / on the tab (e.g. a record count). */
    badge?: number | string | null;
    /** Kinetix status color for the badge (primary, gray, success…). */
    badgeColor?: string | null;
    /** Signed attach/detach descriptor (BelongsToMany managers only). */
    descriptor?: string | null;
    /**
     * Serialized pivot form the attach modal renders below the record picker
     * (AttachAction::form()).
     */
    attachForm?: Record<string, any> | null;
    /**
     * A lazy manager whose table hasn't been loaded yet: the frontend
     * requests the full payload by revisiting with `?relation=`.
     */
    deferred?: boolean;
    /** Translated group label — managers sharing it render as ONE tab. */
    group?: string | null;
    /** The group's stable `?relation=` key (raw label, slugged). */
    groupKey?: string | null;
    /** The section shows a collapse toggle wherever its heading renders. */
    collapsible?: boolean;
    /** Start collapsed. */
    collapsed?: boolean;
}

export interface KinetixTableEmptyState {
    heading?: string | null;
    description?: string | null;
    /** Lucide icon name (shared resolveIcon map). */
    icon?: string | null;
    /** CTA actions rendered under the text (e.g. a Create modal action). */
    actions: KinetixAction[];
}

export interface KinetixPlanData {
    id: number | string | null;
    name: string;
    slug: string;
    description: string | null;
    monthlyPrice: number | null;
    yearlyPrice: number | null;
    features: Record<string, any>;
    highlightedFeatures: string[];
    isFeatured: boolean;
    isFree: boolean;
    sortOrder: number;
    trialDays: number | null;
}

export interface KinetixPaymentMethod {
    id: string;
    brand: string;
    last4: string;
    expMonth: number;
    expYear: number;
}

export interface KinetixInvoice {
    id: string;
    date: string;
    total: string;
    status: string;
    url: string;
}

export interface KinetixSubscriptionData {
    active: boolean;
    onGracePeriod: boolean;
    status: string | null;
    endsAt: string | null;
    stripePrice: string | null;
    onTrial: boolean;
    trialEndsAt: string | null;
    onGenericTrial: boolean;
    trialPlan: string | null;
}

/** One metered usage dimension (API calls, seats, storage, …) for <KinetixUsageMeters>. */
export interface KinetixUsageMetricData {
    key: string;
    label: string;
    used: number;
    limit: number | null;
    percent: number;
    display: string;
    unit: string | null;
    color: string;
    overLimit: boolean;
}

/** A registered `Report` type, for <KinetixReportLauncher>. */
export interface ReportTypeData {
    token: string;
    label: string;
    description: string | null;
    format: string;
}

/** A recurring/scheduled report definition, for <KinetixReportSchedules>. */
export interface ReportScheduleData {
    id: number | string;
    reportClass: string;
    reportLabel: string;
    frequency: string;
    parameters: Record<string, unknown> | null;
    enabled: boolean;
    nextRunAt: string | null;
    lastRunAt: string | null;
    notifyOnCompletion: boolean;
}
