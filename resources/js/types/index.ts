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
