import type { Component } from "vue";
import {
  ArrowDown,
  ArrowUp,
  Calendar,
  Check,
  CheckCircle2,
  ChevronDown,
  Circle,
  Copy,
  CreditCard,
  Download,
  Edit3,
  Eye,
  ExternalLink,
  Filter,
  Info,
  Mail,
  MoreVertical,
  Pencil,
  Phone,
  Plus,
  RotateCcw,
  Settings,
  SlidersHorizontal,
  Star,
  Trash2,
  Upload,
  User,
  X,
  XCircle,
} from "@lucide/vue";

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
  "trash-2": Trash2,
  view: Eye,
  eye: Eye,
  create: Plus,
  plus: Plus,
  download: Download,
  upload: Upload,
  restore: RotateCcw,
  "rotate-ccw": RotateCcw,
  settings: Settings,
  filter: Filter,
  "sliders-horizontal": SlidersHorizontal,
  "ellipsis-vertical": MoreVertical,
  "more-vertical": MoreVertical,
  "chevron-down": ChevronDown,
  "arrow-up": ArrowUp,
  "arrow-down": ArrowDown,
  check: Check,
  "check-circle": CheckCircle2,
  x: X,
  "x-circle": XCircle,
  circle: Circle,
  star: Star,
  copy: Copy,
  "external-link": ExternalLink,
  mail: Mail,
  phone: Phone,
  calendar: Calendar,
  user: User,
  info: Info,
  "credit-card": CreditCard,
};

/**
 * Resolve a Lucide icon component by name. Returns null for empty/unknown names
 * so callers can simply guard with `v-if`.
 */
export function resolveIcon(name?: string | null): Component | null {
  if (!name) {
    return null;
  }

  return ICONS[name.toLowerCase()] ?? null;
}
