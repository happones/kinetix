export interface KinetixAction {
  name: string;
  label: string;
  icon?: string;
  iconPosition?: "before" | "after";
  url?: string;
  shouldOpenInNewTab: boolean;
  color: string;
  size?: "xs" | "sm" | "md" | "lg";
  viewType: "button" | "link";
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
  type?: "action" | "group";
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
  status: "info" | "success" | "warning" | "danger";
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
  auth?: { user?: { id: number | string } | null };
  [key: string]: unknown;
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
}

/** A step shown in the <KinetixWizard> indicator. */
export interface KinetixWizardStep {
  key?: string;
  label: string;
  description?: string | null;
  icon?: string | null;
}

/** Visual style of the wizard step indicator. */
export type KinetixWizardVariant =
  | "default"
  | "simple"
  | "vertical"
  | "panels"
  | "gradient";

/** Per-user accessibility preferences. */
export interface KinetixAccessibility {
  reducedMotion: boolean;
  highContrast: boolean;
  textSize: "normal" | "large" | "x-large";
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
  status: "pending" | "active" | "revoked";
  expired: boolean;
  activatedAt: string | null;
  expiresAt: string | null;
}

export interface KinetixStat {
  label: string;
  value: string | number;
  description?: string;
  descriptionIcon?: string;
  descriptionColor?: "success" | "danger" | "warning" | "info" | "gray";
  chart?: number[];
}

export interface KinetixChartDataset {
  label: string;
  data: number[];
  backgroundColor?: string | string[];
  borderColor?: string | string[];
  borderWidth?: number;
  [key: string]: any;
}

export interface KinetixWidget {
  id: string;
  type: "stats" | "chart" | "table" | "custom";
  title?: string;
  description?: string;
  columnSpan: number | string | Record<string, number | string>;
  sort: number;
  data: any;
}

export interface KinetixWidgetsGridData {
  columns: number | Record<string, number>;
  widgets: KinetixWidget[];
}

export interface KinetixTableColumn {
  name: string;
  label: string;
  isSearchable: boolean;
  isSortable: boolean;
  alignment: "left" | "center" | "right";
  isToggleable: boolean;
  isToggledHiddenByDefault: boolean;
  type: string;
  isBadge?: boolean;
  hasSummary?: boolean;
}

export interface KinetixTableFilter {
  name: string;
  label: string;
  default: any;
  type:
    | "checkbox"
    | "select"
    | "ternary"
    | "multi-select"
    | "date"
    | "datetime"
    | "date-range"
    | "number-range"
    | "month"
    | "year"
    | "week"
    | "address";
  options?: Record<string, string>;
  useCalendar?: boolean;
  numberOfMonths?: number;
  locale?: string | null;
  weekdayFormat?: "narrow" | "short" | "long" | null;
  fixedWeeks?: boolean;
  minValue?: string | null;
  maxValue?: string | null;
  minuteStep?: number;
  hour12?: boolean;
  weekStartsOn?: number | null;
}

export interface KinetixTableRecord {
  id: string | number;
  values: Record<string, any>;
  icons: Record<string, string | null>;
  iconColors: Record<string, string>;
  badgeColors: Record<string, string>;
  descriptions: Record<
    string,
    { text: string | null; position: "above" | "below" }
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
    | "text"
    | "icon"
    | "image"
    | "color"
    | "section"
    | "grid"
    | "fieldset"
    | "tabs"
    | "tab";
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
    direction: "asc" | "desc";
    filters: Record<string, any>;
    perPage: number;
  };
  queryPrefix: string;
  stickyActions?: boolean;
  summaries?: Record<string, KinetixSummary[]>;
  hasSummaries?: boolean;
  reorderable?: boolean;
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
  status: string;
  endsAt: string | null;
  stripePrice: string | null;
}
