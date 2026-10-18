<script setup lang="ts">
import { Plus, Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import type { KinetixFlowNode } from '@/types/kinetix';
import KinetixBusinessHours from '../KinetixBusinessHours.vue';
import KinetixLabel from '../KinetixLabel.vue';
import { flowId, flowKind } from './flowNodes';

/**
 * The settings of the selected node, by kind: what to say, what to ask and
 * where to keep the answer, the options, the rules, the API call, the wait,
 * the hours, whom to hand the conversation to. Variables insert where the
 * cursor is.
 */
const props = defineProps<{
    node: KinetixFlowNode;
    variables: string[];
    members: { id: number | string; name: string | null }[];
    problem?: string | null;
}>();

const emit = defineEmits<{
    (e: 'update', data: Record<string, unknown>): void;
    (e: 'delete'): void;
}>();

const { t } = useI18n();

const kind = computed(() => flowKind(props.node.type));
const data = computed(() => props.node.data);
const lastField = ref<{
    el: HTMLInputElement | HTMLTextAreaElement;
    key: string;
} | null>(null);

const textareaClass = `${inputClass} h-auto min-h-20 py-2 resize-y`;

function set(key: string, value: unknown): void {
    emit('update', { ...data.value, [key]: value });
}

function list<T>(key: string): T[] {
    return Array.isArray(data.value[key]) ? (data.value[key] as T[]) : [];
}

function setItem(
    key: string,
    index: number,
    patch: Record<string, unknown>,
): void {
    set(
        key,
        list<Record<string, unknown>>(key).map((item, i) =>
            i === index ? { ...item, ...patch } : item,
        ),
    );
}

function removeItem(key: string, index: number): void {
    set(
        key,
        list<unknown>(key).filter((_, i) => i !== index),
    );
}

function remember(event: FocusEvent, key: string): void {
    lastField.value = {
        el: event.target as HTMLInputElement | HTMLTextAreaElement,
        key,
    };
}

/** Put `{{variable}}` where the cursor was in the last text field. */
function insert(variable: string): void {
    const token = `{{${variable}}}`;
    const field = lastField.value;

    if (!field) {
        set('text', `${String(data.value.text ?? '')}${token}`);

        return;
    }

    const { el } = field;
    const start = el.selectionStart ?? el.value.length;
    const end = el.selectionEnd ?? el.value.length;
    const value = `${el.value.slice(0, start)}${token}${el.value.slice(end)}`;

    set(field.key, value);
    requestAnimationFrame(() => {
        el.focus();
        el.setSelectionRange(start + token.length, start + token.length);
    });
}

const options = computed(() => list<{ id: string; label: string }>('options'));
const rules = computed(() =>
    list<{ variable: string; operator: string; value: string }>('rules'),
);
const headers = computed(() => list<{ key: string; value: string }>('headers'));
const saves = computed(() => list<{ variable: string; path: string }>('save'));

const timezones = computed<string[]>(() => {
    const intl = Intl as unknown as {
        supportedValuesOf?: (key: string) => string[];
    };

    return intl.supportedValuesOf?.('timeZone') ?? [];
});

const label = computed(() => {
    const key = `kinetix.flow_node_${props.node.type}`;
    const translated = t(key);

    return translated === key ? props.node.type : translated;
});

const OPERATORS = [
    'equals',
    'not_equals',
    'contains',
    'not_contains',
    'starts_with',
    'greater',
    'less',
    'empty',
    'not_empty',
];
</script>

<template>
    <div class="space-y-4" data-test="flow-inspector">
        <div class="gap-2 flex items-center">
            <span
                class="size-8 inline-flex shrink-0 items-center justify-center rounded-md"
                :class="kind.tone"
            >
                <component :is="kind.icon" class="size-4" />
            </span>
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-semibold text-foreground">
                    {{ label }}
                </h3>
                <p class="text-xs text-muted-foreground">
                    {{ t(`kinetix.flow_node_${node.type}_help`) }}
                </p>
            </div>
            <button
                v-if="node.type !== 'start'"
                type="button"
                :class="buttonVariants({ variant: 'ghost', size: 'icon-sm' })"
                :aria-label="t('kinetix.flow_delete_node')"
                data-test="flow-delete-node"
                @click="emit('delete')"
            >
                <Trash2 class="size-4" />
            </button>
        </div>

        <p
            v-if="problem"
            class="px-3 py-2 text-xs rounded-md bg-destructive/10 text-destructive"
            role="alert"
        >
            {{ problem }}
        </p>

        <!-- What to say -->
        <div
            v-if="['message', 'question', 'options', 'end'].includes(node.type)"
            class="space-y-2"
        >
            <KinetixLabel for="flow-text">{{
                node.type === 'question'
                    ? t('kinetix.flow_field_question')
                    : t('kinetix.flow_field_text')
            }}</KinetixLabel>
            <textarea
                id="flow-text"
                :value="String(data.text ?? '')"
                :class="textareaClass"
                rows="4"
                :placeholder="t('kinetix.flow_text_placeholder')"
                data-test="flow-field-text"
                @input="
                    set('text', ($event.target as HTMLTextAreaElement).value)
                "
                @focus="remember($event, 'text')"
            />
        </div>

        <!-- Question -->
        <template v-if="node.type === 'question'">
            <div class="gap-3 grid grid-cols-2">
                <div class="space-y-2">
                    <KinetixLabel for="flow-variable">{{
                        t('kinetix.flow_field_variable')
                    }}</KinetixLabel>
                    <input
                        id="flow-variable"
                        :value="String(data.variable ?? '')"
                        :class="[inputClass, 'font-mono']"
                        @input="
                            set(
                                'variable',
                                (
                                    $event.target as HTMLInputElement
                                ).value.replace(/[^\w.]/g, ''),
                            )
                        "
                    />
                </div>
                <div class="space-y-2">
                    <KinetixLabel for="flow-validate">{{
                        t('kinetix.flow_field_validate')
                    }}</KinetixLabel>
                    <select
                        id="flow-validate"
                        :value="String(data.validate ?? 'text')"
                        :class="inputClass"
                        @change="
                            set(
                                'validate',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option
                            v-for="validation in [
                                'text',
                                'number',
                                'email',
                                'phone',
                            ]"
                            :key="validation"
                            :value="validation"
                        >
                            {{ t(`kinetix.flow_validate_${validation}`) }}
                        </option>
                    </select>
                </div>
            </div>
            <div class="space-y-2">
                <KinetixLabel for="flow-retry">{{
                    t('kinetix.flow_field_retry')
                }}</KinetixLabel>
                <input
                    id="flow-retry"
                    :value="String(data.retry ?? '')"
                    :class="inputClass"
                    :placeholder="t('kinetix.flow_retry_default')"
                    @input="
                        set('retry', ($event.target as HTMLInputElement).value)
                    "
                    @focus="remember($event, 'retry')"
                />
            </div>
        </template>

        <!-- Options -->
        <template v-if="node.type === 'options'">
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium mb-2 text-foreground">
                    {{ t('kinetix.flow_field_options') }}
                </legend>
                <div
                    v-for="(option, index) in options"
                    :key="option.id"
                    class="gap-2 flex items-center"
                >
                    <span
                        class="w-5 text-xs text-right text-muted-foreground tabular-nums"
                        >{{ index + 1 }}.</span
                    >
                    <input
                        :value="option.label"
                        :class="inputClass"
                        :aria-label="`${t('kinetix.flow_option')} ${index + 1}`"
                        maxlength="60"
                        data-test="flow-option-label"
                        @input="
                            setItem('options', index, {
                                label: ($event.target as HTMLInputElement)
                                    .value,
                            })
                        "
                    />
                    <button
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'ghost',
                                size: 'icon-sm',
                            })
                        "
                        :disabled="list('options').length <= 2"
                        :aria-label="t('kinetix.flow_remove')"
                        @click="removeItem('options', index)"
                    >
                        <X class="size-4" />
                    </button>
                </div>
                <button
                    type="button"
                    :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                    :disabled="list('options').length >= 10"
                    data-test="flow-add-option"
                    @click="
                        set('options', [
                            ...list('options'),
                            { id: flowId('opt'), label: '' },
                        ])
                    "
                >
                    <Plus class="size-4" />
                    {{ t('kinetix.flow_add_option') }}
                </button>
            </fieldset>
            <div class="space-y-2">
                <KinetixLabel for="flow-option-variable">{{
                    t('kinetix.flow_field_variable_optional')
                }}</KinetixLabel>
                <input
                    id="flow-option-variable"
                    :value="String(data.variable ?? '')"
                    :class="[inputClass, 'font-mono']"
                    @input="
                        set(
                            'variable',
                            ($event.target as HTMLInputElement).value.replace(
                                /[^\w.]/g,
                                '',
                            ),
                        )
                    "
                />
            </div>
            <p class="text-xs text-muted-foreground">
                {{ t('kinetix.flow_options_hint') }}
            </p>
        </template>

        <!-- Condition -->
        <template v-if="node.type === 'condition'">
            <div class="space-y-2">
                <KinetixLabel for="flow-match">{{
                    t('kinetix.flow_field_match')
                }}</KinetixLabel>
                <select
                    id="flow-match"
                    :value="String(data.match ?? 'all')"
                    :class="inputClass"
                    @change="
                        set('match', ($event.target as HTMLSelectElement).value)
                    "
                >
                    <option value="all">
                        {{ t('kinetix.flow_match_all') }}
                    </option>
                    <option value="any">
                        {{ t('kinetix.flow_match_any') }}
                    </option>
                </select>
            </div>
            <div
                v-for="(rule, index) in rules"
                :key="index"
                class="space-y-2 rounded-lg p-3 border border-border"
            >
                <div class="gap-2 flex">
                    <input
                        :value="rule.variable"
                        :class="[inputClass, 'font-mono']"
                        :placeholder="t('kinetix.flow_field_variable')"
                        :aria-label="t('kinetix.flow_field_variable')"
                        list="kinetix-flow-variables"
                        @input="
                            setItem('rules', index, {
                                variable: ($event.target as HTMLInputElement)
                                    .value,
                            })
                        "
                    />
                    <button
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'ghost',
                                size: 'icon-sm',
                            })
                        "
                        :disabled="list('rules').length <= 1"
                        :aria-label="t('kinetix.flow_remove')"
                        @click="removeItem('rules', index)"
                    >
                        <X class="size-4" />
                    </button>
                </div>
                <select
                    :value="rule.operator"
                    :class="inputClass"
                    :aria-label="t('kinetix.flow_field_operator')"
                    @change="
                        setItem('rules', index, {
                            operator: ($event.target as HTMLSelectElement)
                                .value,
                        })
                    "
                >
                    <option v-for="op in OPERATORS" :key="op" :value="op">
                        {{ t(`kinetix.flow_op_${op}`) }}
                    </option>
                </select>
                <input
                    v-if="!['empty', 'not_empty'].includes(rule.operator)"
                    :value="rule.value"
                    :class="inputClass"
                    :placeholder="t('kinetix.flow_field_value')"
                    :aria-label="t('kinetix.flow_field_value')"
                    @input="
                        setItem('rules', index, {
                            value: ($event.target as HTMLInputElement).value,
                        })
                    "
                />
            </div>
            <button
                type="button"
                :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                @click="
                    set('rules', [
                        ...list('rules'),
                        { variable: '', operator: 'equals', value: '' },
                    ])
                "
            >
                <Plus class="size-4" />
                {{ t('kinetix.flow_add_rule') }}
            </button>
        </template>

        <!-- Set a variable -->
        <template v-if="node.type === 'set'">
            <div class="space-y-2">
                <KinetixLabel for="flow-set-variable">{{
                    t('kinetix.flow_field_variable')
                }}</KinetixLabel>
                <input
                    id="flow-set-variable"
                    :value="String(data.variable ?? '')"
                    :class="[inputClass, 'font-mono']"
                    @input="
                        set(
                            'variable',
                            ($event.target as HTMLInputElement).value.replace(
                                /[^\w.]/g,
                                '',
                            ),
                        )
                    "
                />
            </div>
            <div class="space-y-2">
                <KinetixLabel for="flow-set-value">{{
                    t('kinetix.flow_field_value')
                }}</KinetixLabel>
                <input
                    id="flow-set-value"
                    :value="String(data.value ?? '')"
                    :class="inputClass"
                    @input="
                        set('value', ($event.target as HTMLInputElement).value)
                    "
                    @focus="remember($event, 'value')"
                />
            </div>
        </template>

        <!-- HTTP -->
        <template v-if="node.type === 'http'">
            <div class="gap-2 flex">
                <select
                    :value="String(data.method ?? 'GET')"
                    :class="[inputClass, 'w-28 shrink-0']"
                    :aria-label="t('kinetix.flow_field_method')"
                    @change="
                        set(
                            'method',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option
                        v-for="method in [
                            'GET',
                            'POST',
                            'PUT',
                            'PATCH',
                            'DELETE',
                        ]"
                        :key="method"
                        :value="method"
                    >
                        {{ method }}
                    </option>
                </select>
                <input
                    :value="String(data.url ?? '')"
                    type="url"
                    :class="[inputClass, 'font-mono']"
                    placeholder="https://api.example.com/orders/{{order_id}}"
                    :aria-label="t('kinetix.flow_field_url')"
                    data-test="flow-field-url"
                    @input="
                        set('url', ($event.target as HTMLInputElement).value)
                    "
                    @focus="remember($event, 'url')"
                />
            </div>
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium mb-2 text-foreground">
                    {{ t('kinetix.flow_field_headers') }}
                </legend>
                <div
                    v-for="(header, index) in headers"
                    :key="index"
                    class="gap-2 flex"
                >
                    <input
                        :value="header.key"
                        :class="[inputClass, 'font-mono']"
                        placeholder="Authorization"
                        :aria-label="t('kinetix.flow_field_header_name')"
                        @input="
                            setItem('headers', index, {
                                key: ($event.target as HTMLInputElement).value,
                            })
                        "
                    />
                    <input
                        :value="header.value"
                        :class="[inputClass, 'font-mono']"
                        :aria-label="t('kinetix.flow_field_value')"
                        @input="
                            setItem('headers', index, {
                                value: ($event.target as HTMLInputElement)
                                    .value,
                            })
                        "
                    />
                    <button
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'ghost',
                                size: 'icon-sm',
                            })
                        "
                        :aria-label="t('kinetix.flow_remove')"
                        @click="removeItem('headers', index)"
                    >
                        <X class="size-4" />
                    </button>
                </div>
                <button
                    type="button"
                    :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                    @click="
                        set('headers', [
                            ...list('headers'),
                            { key: '', value: '' },
                        ])
                    "
                >
                    <Plus class="size-4" />
                    {{ t('kinetix.flow_add_header') }}
                </button>
            </fieldset>
            <div v-if="data.method && data.method !== 'GET'" class="space-y-2">
                <KinetixLabel for="flow-body">{{
                    t('kinetix.flow_field_body')
                }}</KinetixLabel>
                <textarea
                    id="flow-body"
                    :value="String(data.body ?? '')"
                    :class="[textareaClass, 'font-mono text-xs']"
                    rows="5"
                    placeholder='{"phone": "{{contact.key}}"}'
                    @input="
                        set(
                            'body',
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                    @focus="remember($event, 'body')"
                />
            </div>
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium mb-1 text-foreground">
                    {{ t('kinetix.flow_field_save') }}
                </legend>
                <p class="text-xs text-muted-foreground">
                    {{ t('kinetix.flow_save_hint') }}
                </p>
                <div
                    v-for="(mapping, index) in saves"
                    :key="index"
                    class="gap-2 flex"
                >
                    <input
                        :value="mapping.variable"
                        :class="[inputClass, 'font-mono']"
                        :placeholder="t('kinetix.flow_field_variable')"
                        :aria-label="t('kinetix.flow_field_variable')"
                        @input="
                            setItem('save', index, {
                                variable: (
                                    $event.target as HTMLInputElement
                                ).value.replace(/[^\w.]/g, ''),
                            })
                        "
                    />
                    <input
                        :value="mapping.path"
                        :class="[inputClass, 'font-mono']"
                        placeholder="data.status"
                        :aria-label="t('kinetix.flow_field_path')"
                        @input="
                            setItem('save', index, {
                                path: ($event.target as HTMLInputElement).value,
                            })
                        "
                    />
                    <button
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'ghost',
                                size: 'icon-sm',
                            })
                        "
                        :aria-label="t('kinetix.flow_remove')"
                        @click="removeItem('save', index)"
                    >
                        <X class="size-4" />
                    </button>
                </div>
                <button
                    type="button"
                    :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                    @click="
                        set('save', [
                            ...list('save'),
                            { variable: '', path: '' },
                        ])
                    "
                >
                    <Plus class="size-4" />
                    {{ t('kinetix.flow_add_mapping') }}
                </button>
            </fieldset>
        </template>

        <!-- Delay -->
        <div v-if="node.type === 'delay'" class="gap-2 flex">
            <input
                :value="Number(data.amount ?? 1)"
                type="number"
                min="1"
                max="1000"
                :class="[inputClass, 'w-24']"
                :aria-label="t('kinetix.flow_field_amount')"
                @input="
                    set(
                        'amount',
                        Number(($event.target as HTMLInputElement).value) || 1,
                    )
                "
            />
            <select
                :value="String(data.unit ?? 'hours')"
                :class="inputClass"
                :aria-label="t('kinetix.flow_field_unit')"
                @change="
                    set('unit', ($event.target as HTMLSelectElement).value)
                "
            >
                <option
                    v-for="unit in ['minutes', 'hours', 'days']"
                    :key="unit"
                    :value="unit"
                >
                    {{ t(`kinetix.flow_unit_${unit}`) }}
                </option>
            </select>
        </div>

        <!-- Business hours -->
        <template v-if="node.type === 'hours'">
            <div class="space-y-2">
                <KinetixLabel for="flow-timezone">{{
                    t('kinetix.flow_field_timezone')
                }}</KinetixLabel>
                <input
                    id="flow-timezone"
                    :value="String(data.timezone ?? '')"
                    :class="inputClass"
                    list="kinetix-flow-timezones"
                    :placeholder="t('kinetix.flow_timezone_default')"
                    @change="
                        set(
                            'timezone',
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
                <datalist id="kinetix-flow-timezones">
                    <option
                        v-for="zone in timezones"
                        :key="zone"
                        :value="zone"
                    />
                </datalist>
            </div>
            <KinetixBusinessHours
                :value="(data.schedule as Record<string, never> | null) ?? null"
                @update:value="set('schedule', $event)"
            />
        </template>

        <!-- Handoff -->
        <template v-if="node.type === 'handoff'">
            <div class="space-y-2">
                <KinetixLabel for="flow-assign">{{
                    t('kinetix.flow_field_assign_to')
                }}</KinetixLabel>
                <select
                    id="flow-assign"
                    :value="
                        data.assign_to === null || data.assign_to === undefined
                            ? ''
                            : String(data.assign_to)
                    "
                    :class="inputClass"
                    @change="
                        set(
                            'assign_to',
                            ($event.target as HTMLSelectElement).value || null,
                        )
                    "
                >
                    <option value="">
                        {{ t('kinetix.flow_assign_anyone') }}
                    </option>
                    <option
                        v-for="member in members"
                        :key="member.id"
                        :value="String(member.id)"
                    >
                        {{ member.name }}
                    </option>
                </select>
            </div>
            <div class="space-y-2">
                <KinetixLabel for="flow-note">{{
                    t('kinetix.flow_field_note')
                }}</KinetixLabel>
                <textarea
                    id="flow-note"
                    :value="String(data.note ?? '')"
                    :class="textareaClass"
                    rows="3"
                    @input="
                        set(
                            'note',
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                    @focus="remember($event, 'note')"
                />
            </div>
        </template>

        <!-- Variables, inserted where the cursor is -->
        <div
            v-if="!['start', 'delay', 'hours'].includes(node.type)"
            class="space-y-1.5"
        >
            <p class="text-xs font-medium text-muted-foreground">
                {{ t('kinetix.flow_variables') }}
            </p>
            <div class="gap-1 flex flex-wrap">
                <button
                    v-for="variable in variables"
                    :key="variable"
                    type="button"
                    class="px-2 py-0.5 font-mono rounded-md border border-border bg-muted/50 text-[11px] text-foreground transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    @mousedown.prevent
                    @click="insert(variable)"
                >
                    {{ variable }}
                </button>
            </div>
        </div>
        <datalist id="kinetix-flow-variables">
            <option
                v-for="variable in variables"
                :key="variable"
                :value="variable"
            />
        </datalist>
    </div>
</template>
