<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import KinetixCombobox from './KinetixCombobox.vue';

interface PhoneCountry {
    code: string;
    name: string;
    dial: string;
}
interface PhoneConfig {
    defaultCountry?: string | null;
    countries?: PhoneCountry[] | null;
}

/**
 * International phone field: a searchable country selector (flag + dial code) and
 * a national-number input. Emits the full E.164-style string, e.g. "+5215512345678".
 */
const props = withDefaults(
    defineProps<{
        value?: string | null;
        config?: PhoneConfig | null;
        disabled?: boolean;
        placeholder?: string | null;
    }>(),
    { value: null, config: null, disabled: false, placeholder: null },
);

const emit = defineEmits<{ (e: 'update:value', value: string): void }>();

const countries = computed<PhoneCountry[]>(() => props.config?.countries ?? []);

/** ISO code → flag emoji via regional indicator symbols. */
function flag(code: string): string {
    if (code.length !== 2) {
        return '';
    }

    return String.fromCodePoint(
        ...[...code.toUpperCase()].map((c) => 0x1f1e6 + c.charCodeAt(0) - 65),
    );
}

// Combobox options: code → "🇲🇽 Mexico +52".
const options = computed<Record<string, string>>(() => {
    const out: Record<string, string> = {};

    for (const c of countries.value) {
        out[c.code] = `${flag(c.code)} ${c.name} +${c.dial}`;
    }

    return out;
});

const dialFor = (code: string): string =>
    countries.value.find((c) => c.code === code)?.dial ?? '';

// Resolve the initial country: the longest dial-code prefix of the value,
// else the configured default.
function initialCountry(): string {
    const digits = (props.value ?? '').replace(/^\+/, '');
    let best = '';
    let bestLen = 0;

    for (const c of countries.value) {
        if (digits.startsWith(c.dial) && c.dial.length > bestLen) {
            best = c.code;
            bestLen = c.dial.length;
        }
    }

    return best || props.config?.defaultCountry || 'US';
}

const country = ref(initialCountry());
const national = ref(
    (props.value ?? '')
        .replace(/^\+/, '')
        .replace(new RegExp(`^${dialFor(country.value)}`), ''),
);

watch(
    () => props.config,
    () => {
        country.value = initialCountry();
    },
);

function emitValue(): void {
    const digits = national.value.replace(/\D/g, '');
    emit('update:value', `+${dialFor(country.value)}${digits}`);
}

function onCountry(code: string): void {
    country.value = code;
    emitValue();
}

function onNational(event: Event): void {
    national.value = (event.target as HTMLInputElement).value;
    emitValue();
}
</script>

<template>
    <div class="gap-2 flex items-center">
        <div class="w-44 shrink-0">
            <KinetixCombobox
                :value="country"
                :options="options"
                :disabled="disabled"
                @update:value="onCountry"
            />
        </div>
        <div class="relative flex-1">
            <span
                class="inset-y-0 left-3 text-sm pointer-events-none absolute flex items-center text-muted-foreground"
                >+{{ dialFor(country) }}</span
            >
            <input
                :value="national"
                type="tel"
                inputmode="tel"
                :disabled="disabled"
                :placeholder="placeholder ?? ''"
                :class="inputClass"
                class="pl-12"
                @input="onNational"
            />
        </div>
    </div>
</template>
