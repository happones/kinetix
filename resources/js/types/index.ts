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
  requiresConfirmation: boolean;
  modalHeading?: string | null;
  modalDescription?: string | null;
  modalIcon?: string | null;
  modalSubmitActionLabel?: string | null;
  modalCancelActionLabel?: string | null;
  type?: "action" | "group";
  actions?: KinetixAction[] | null;
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
    | "number-range";
  options?: Record<string, string>;
  useCalendar?: boolean;
  numberOfMonths?: number;
  locale?: string | null;
  weekdayFormat?: "narrow" | "short" | "long" | null;
  fixedWeeks?: boolean;
  minValue?: string | null;
  maxValue?: string | null;
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
  columns: KinetixTableColumn[];
  filters: KinetixTableFilter[];
  recordActions: KinetixAction[];
  toolbarActions: KinetixAction[];
  bulkActions: KinetixAction[];
  records: KinetixTableRecord[];
  isPaginated: boolean;
  paginationPageOptions: number[];
  pagination: {
    total: number;
    perPage: number;
    currentPage: number;
    lastPage: number;
  } | null;
  state: {
    search: string;
    sort: string;
    direction: "asc" | "desc";
    filters: Record<string, any>;
    perPage: number;
  };
  queryPrefix: string;
}

export interface KinetixRelationManagerData {
  title: string;
  relationship: string;
  table: KinetixTableData;
}
