import {
    Braces,
    CircleStop,
    Clock,
    Globe,
    Hourglass,
    ListChecks,
    MessageCircleQuestion,
    MessageSquareText,
    Play,
    Split,
    UserRoundCheck,
} from '@lucide/vue';
import type { Component } from 'vue';

type Translate = (key: string, params?: Record<string, unknown>) => string;

export interface FlowNodeOutput {
    id: string;
    label: string;
}

export interface FlowNodeKind {
    icon: Component;
    /** Tailwind classes for the node's icon tile. */
    tone: string;
    /** Whether it can be added from the palette (the start node can't). */
    addable: boolean;
    defaults: () => Record<string, unknown>;
    /** The outputs it exposes with these settings (mirrors the PHP node). */
    outputs: (data: Record<string, unknown>, t: Translate) => FlowNodeOutput[];
    /** A one-line preview of its settings, on the canvas. */
    summary: (data: Record<string, unknown>, t: Translate) => string;
}

const text = (value: unknown): string =>
    typeof value === 'string' ? value : '';

const next = (
    _data: Record<string, unknown>,
    t: Translate,
): FlowNodeOutput[] => [{ id: 'next', label: t('kinetix.flow_out_next') }];

let counter = 0;

/** A short unique id for a new node or option. */
export function flowId(prefix: string): string {
    counter += 1;

    return `${prefix}-${Date.now().toString(36)}${counter.toString(36)}`;
}

/**
 * The kinds of node the editor knows. A node of a kind registered only in
 * PHP still shows (as a generic step with one output).
 */
export const FLOW_NODES: Record<string, FlowNodeKind> = {
    start: {
        icon: Play,
        tone: 'bg-primary text-primary-foreground',
        addable: false,
        defaults: () => ({}),
        outputs: next,
        summary: (_data, t) => t('kinetix.flow_node_start_summary'),
    },
    message: {
        icon: MessageSquareText,
        tone: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
        addable: true,
        defaults: () => ({ text: '' }),
        outputs: next,
        summary: (data) => text(data.text),
    },
    question: {
        icon: MessageCircleQuestion,
        tone: 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
        addable: true,
        defaults: () => ({
            text: '',
            variable: 'answer',
            validate: 'text',
            retry: '',
        }),
        outputs: (_data, t) => [
            { id: 'next', label: t('kinetix.flow_out_answered') },
            { id: 'invalid', label: t('kinetix.flow_out_invalid') },
        ],
        summary: (data) =>
            `${text(data.text)}${data.variable ? ` → {{${text(data.variable)}}}` : ''}`,
    },
    options: {
        icon: ListChecks,
        tone: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
        addable: true,
        defaults: () => ({
            text: '',
            variable: '',
            options: [
                { id: flowId('opt'), label: '' },
                { id: flowId('opt'), label: '' },
            ],
        }),
        outputs: (data, t) => [
            ...(Array.isArray(data.options) ? data.options : []).map(
                (option: { id: string; label: string }, index: number) => ({
                    id: String(option.id),
                    label:
                        option.label ||
                        `${t('kinetix.flow_option')} ${index + 1}`,
                }),
            ),
            { id: 'other', label: t('kinetix.flow_out_other') },
        ],
        summary: (data) => text(data.text),
    },
    condition: {
        icon: Split,
        tone: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
        addable: true,
        defaults: () => ({
            match: 'all',
            rules: [{ variable: '', operator: 'equals', value: '' }],
        }),
        outputs: (_data, t) => [
            { id: 'yes', label: t('kinetix.flow_out_yes') },
            { id: 'no', label: t('kinetix.flow_out_no') },
        ],
        summary: (data, t) => {
            const rules = Array.isArray(data.rules) ? data.rules : [];
            const first = rules[0] as
                | { variable?: string; operator?: string; value?: string }
                | undefined;

            if (!first?.variable) {
                return '';
            }

            const more = rules.length > 1 ? ` +${rules.length - 1}` : '';

            return `{{${first.variable}}} ${t(`kinetix.flow_op_${first.operator ?? 'equals'}`)} ${first.value ?? ''}${more}`;
        },
    },
    set: {
        icon: Braces,
        tone: 'bg-slate-500/15 text-slate-700 dark:text-slate-300',
        addable: true,
        defaults: () => ({ variable: '', value: '' }),
        outputs: next,
        summary: (data) =>
            data.variable
                ? `{{${text(data.variable)}}} = ${text(data.value)}`
                : '',
    },
    http: {
        icon: Globe,
        tone: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300',
        addable: true,
        defaults: () => ({
            method: 'GET',
            url: '',
            headers: [],
            body: '',
            save: [],
        }),
        outputs: (_data, t) => [
            { id: 'next', label: t('kinetix.flow_out_success') },
            { id: 'error', label: t('kinetix.flow_out_error') },
        ],
        summary: (data) =>
            data.url ? `${text(data.method) || 'GET'} ${text(data.url)}` : '',
    },
    delay: {
        icon: Hourglass,
        tone: 'bg-orange-500/15 text-orange-700 dark:text-orange-300',
        addable: true,
        defaults: () => ({ amount: 1, unit: 'hours' }),
        outputs: next,
        summary: (data, t) =>
            t('kinetix.flow_node_delay_summary', {
                amount: Number(data.amount ?? 1),
                unit: t(`kinetix.flow_unit_${text(data.unit) || 'minutes'}`),
            }),
    },
    hours: {
        icon: Clock,
        tone: 'bg-teal-500/15 text-teal-700 dark:text-teal-300',
        addable: true,
        defaults: () => ({ timezone: '', schedule: null }),
        outputs: (_data, t) => [
            { id: 'open', label: t('kinetix.flow_out_open') },
            { id: 'closed', label: t('kinetix.flow_out_closed') },
        ],
        summary: (data, t) =>
            text(data.timezone) || t('kinetix.flow_node_hours_summary'),
    },
    handoff: {
        icon: UserRoundCheck,
        tone: 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
        addable: true,
        defaults: () => ({ assign_to: null, note: '' }),
        outputs: () => [],
        summary: (data, t) =>
            text(data.note) || t('kinetix.flow_node_handoff_summary'),
    },
    end: {
        icon: CircleStop,
        tone: 'bg-muted text-muted-foreground',
        addable: true,
        defaults: () => ({ text: '' }),
        outputs: () => [],
        summary: (data, t) =>
            text(data.text) || t('kinetix.flow_node_end_summary'),
    },
};

/** A node's kind, or a generic one for kinds the editor doesn't know. */
export function flowKind(type: string): FlowNodeKind {
    return (
        FLOW_NODES[type] ?? {
            icon: Braces,
            tone: 'bg-muted text-muted-foreground',
            addable: false,
            defaults: () => ({}),
            outputs: next,
            summary: () => '',
        }
    );
}

/** The variables a graph offers to `{{…}}`: the built-in ones and the ones its nodes set. */
export function flowVariables(
    nodes: { type: string; data: Record<string, unknown> }[],
): string[] {
    const found = new Set([
        'contact.name',
        'contact.key',
        'line.name',
        'message',
    ]);

    for (const node of nodes) {
        if (typeof node.data.variable === 'string' && node.data.variable) {
            found.add(node.data.variable);
        }

        if (node.type === 'http') {
            found.add('http.status');

            for (const save of (node.data.save as { variable?: string }[]) ??
                []) {
                if (save.variable) {
                    found.add(save.variable);
                }
            }
        }
    }

    return [...found];
}
