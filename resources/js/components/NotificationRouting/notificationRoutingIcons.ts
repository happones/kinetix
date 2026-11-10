import {
    Bell,
    Mail,
    Radio,
    Send,
    ShieldCheck,
    User,
    UserCheck,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import type { KinetixNotificationAudienceType } from '@/types/kinetix';

const CHANNEL_ICONS: Record<string, Component> = {
    mail: Mail,
    database: Bell,
    broadcast: Radio,
};

const AUDIENCE_ICONS: Record<KinetixNotificationAudienceType, Component> = {
    member: User,
    role: ShieldCheck,
    group: Users,
    eligible: UserCheck,
};

/** The icon of a delivery channel; a channel the toolkit does not know gets a generic one. */
export const notificationChannelIcon = (key: string): Component =>
    CHANNEL_ICONS[key] ?? Send;

/** The icon of an audience kind. */
export const notificationAudienceIcon = (
    kind: KinetixNotificationAudienceType,
): Component => AUDIENCE_ICONS[kind];
