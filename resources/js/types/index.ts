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
}

/** Public-safe Kinetix runtime config shared with Inertia page props. */
export interface KinetixConfig {
    database: boolean;
    route_prefix: string;
    sound: { enabled: boolean; path: string };
    broadcasting: Record<string, unknown> | null;
}

/**
 * Inertia shared props Kinetix relies on. Pass to `usePage<KinetixSharedProps>()`
 * to type `kinetix_config` / `kinetix_notifications` / `auth` without `as any`.
 */
export interface KinetixSharedProps {
    kinetix_config?: KinetixConfig;
    kinetix_notifications?: KinetixNotification[];
    kinetix_permissions?: KinetixPermissionState;
    kinetix_impersonation?: KinetixImpersonationState;
    /** Resolved feature flags for the current scope (name → on/off). */
    kinetix_features?: Record<string, boolean>;
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
    auth?: { user?: { id: number | string } | null };
    [key: string]: unknown;
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
}

export interface KinetixRole {
    id: number | string | null;
    name: string;
    permissions: string[];
    usersCount?: number | null;
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

export interface KinetixChartDataset {
    label: string;
    data: number[];
    backgroundColor?: string | string[];
    borderColor?: string | string[];
    borderWidth?: number;
    [key: string]: any;
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
    size?: number | string | null;
    isInline: boolean;
    extraAttributes?: Record<string, string> | null;
    // Layout components
    schema?: KinetixInfolistEntry[] | null;
    heading?: string | null;
    description?: string | null;
    columns?: number | null;
    actions?: KinetixAction[];
}

export interface KinetixInfolistData {
    schema: KinetixInfolistEntry[];
    columns: number;
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
        total: number;
        perPage: number;
        currentPage: number;
        lastPage: number;
        from: number | null;
        to: number | null;
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
    reorderable?: boolean;
    savedViewsKey?: string | null;
}

/** A computed column summary (sum/average/count/range/custom). */
export interface KinetixSummary {
    label: string | null;
    value: string;
}

export interface KinetixRelationManagerData {
    title: string;
    relationship: string;
    table: KinetixTableData;
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
