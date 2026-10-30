<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import { fetchKinetixLocations } from '@/composables/useKinetixLocations';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import type {
    KinetixAddress,
    KinetixLocationList,
    KinetixOption,
    KinetixSharedProps,
} from '@/types/kinetix';
import KinetixCombobox from './KinetixCombobox.vue';
import KinetixLabel from './KinetixLabel.vue';

/**
 * Structured address field. Value is `{ line1, line2, city, state, postalCode,
 * country }`, plus `stateId` / `cityId` in locations mode.
 *
 * Free text (no `locations-token`): a text input per part and a searchable
 * country select.
 *
 * Locations mode: country → state → city selects, each listing the children
 * of the one before it. Changing the country clears the state and city;
 * changing the state clears the city. A level with nothing to choose (a
 * country without states in the catalog) falls back to typing, so the address
 * can always be completed. States arrive whole and filter locally; a city
 * list longer than one page is searched on the server as the user types.
 */
const props = withDefaults(
    defineProps<{
        value?: KinetixAddress | null;
        fields?: string[];
        /** Country options (code => label). */
        countries?: Record<string, string>;
        disabled?: boolean;
        /** Signed token of the location provider; set = locations mode. */
        locationsToken?: string | null;
        /** Parts that can't be left blank. */
        required?: string[];
    }>(),
    {
        value: null,
        fields: () => [
            'line1',
            'line2',
            'city',
            'state',
            'postalCode',
            'country',
        ],
        countries: () => ({}),
        disabled: false,
        locationsToken: null,
        required: () => [],
    },
);

const emit = defineEmits<{
    (e: 'update:value', value: KinetixAddress | null): void;
}>();

const { t } = useI18n();
const page = usePage<KinetixSharedProps>();

const labels: Record<string, string> = {
    line1: 'address_line1',
    line2: 'address_line2',
    city: 'address_city',
    state: 'address_state',
    postalCode: 'address_postal',
    country: 'address_country',
};

// line1/line2 span the full grid; the rest sit two-per-row.
const fullWidth = (field: string) => field === 'line1' || field === 'line2';

const address = computed<KinetixAddress>(() => props.value ?? {});

const update = (patch: Partial<KinetixAddress>): void => {
    emit('update:value', { ...address.value, ...patch });
};

const set = (field: string, value: string | null) => {
    update({ [field]: value || null });
};

const val = (field: string): string =>
    ((address.value as Record<string, string | null | undefined>)[field] ??
        '') as string;

// Unique per instance: a repeater of addresses must not repeat ids.
const uid = useId();
const id = (field: string) => `kx-addr-${uid}-${field}`;
const isRequired = (field: string) => props.required.includes(field);

// ── Locations mode ──────────────────────────────────────────────────────────

const located = computed(() => !!props.locationsToken);
const showsState = computed(() => props.fields.includes('state'));
const country = computed(() => address.value.country ?? null);
const stateId = computed(() => address.value.stateId ?? null);

const stateList = ref<KinetixLocationList | null>(null);
const cityList = ref<KinetixLocationList | null>(null);

/** Labels of everything listed or found, for the name stored next to an id. */
const seen = new Map<string, string>();

const remember = (list: KinetixLocationList): KinetixLocationList => {
    list.options.forEach((option) => seen.set(option.value, option.label));

    return list;
};

const load = async (
    level: 'states' | 'cities',
    state: string | null,
    q = '',
): Promise<KinetixLocationList> =>
    remember(
        await fetchKinetixLocations(kinetixRoutePrefix(page), {
            token: props.locationsToken as string,
            level,
            country: country.value as string,
            state,
            q,
        }),
    );

// A list that fails to load leaves the level as free text rather than stuck.
const NOTHING: KinetixLocationList = { options: [], complete: true };

let stateRequest = 0;

watch(
    [located, country, showsState],
    async () => {
        stateList.value = null;
        const request = ++stateRequest;

        if (!located.value || !country.value || !showsState.value) {
            return;
        }

        const list = await load('states', null).catch(() => NOTHING);

        if (request === stateRequest) {
            stateList.value = list;
        }
    },
    { immediate: true },
);

const hasStates = computed(() => (stateList.value?.options.length ?? 0) > 0);

/**
 * The state the cities hang from: the chosen one, or none (the whole
 * country) when the picker has no state level or the country has no states.
 * Undefined while that can't be known yet.
 */
const cityParent = computed<string | null | undefined>(() => {
    if (!located.value || !country.value) {
        return undefined;
    }

    if (!showsState.value) {
        return null;
    }

    if (stateList.value === null) {
        return undefined;
    }

    if (!hasStates.value) {
        return null;
    }

    return stateId.value ?? undefined;
});

let cityRequest = 0;

watch(
    [cityParent, () => props.fields.includes('city')],
    async ([parent, showsCity]) => {
        cityList.value = null;
        const request = ++cityRequest;

        if (parent === undefined || !showsCity) {
            return;
        }

        const list = await load('cities', parent).catch(() => NOTHING);

        if (request === cityRequest) {
            cityList.value = list;
        }
    },
    { immediate: true },
);

const hasCities = computed(() => (cityList.value?.options.length ?? 0) > 0);

/**
 * What a combobox gets for a level: the whole list to filter locally, or —
 * past one page — a server search seeded with that first page.
 */
const source = (
    list: KinetixLocationList | null,
    level: 'states' | 'cities',
    parent: string | null,
): {
    options: KinetixOption[];
    fetchOptions: ((q: string) => Promise<KinetixOption[]>) | null;
} => {
    if (list === null || list.complete) {
        return { options: list?.options ?? [], fetchOptions: null };
    }

    const first = list.options;

    return {
        options: first,
        fetchOptions: async (q: string) =>
            q.trim() === ''
                ? first
                : (await load(level, parent, q.trim())).options,
    };
};

const stateSource = computed(() => source(stateList.value, 'states', null));
const citySource = computed(() =>
    source(cityList.value, 'cities', cityParent.value ?? null),
);

/** How a level renders in locations mode. */
const levelMode = (field: 'state' | 'city'): 'select' | 'text' | 'waiting' => {
    if (!country.value) {
        return 'waiting';
    }

    const list = field === 'state' ? stateList.value : cityList.value;

    if (field === 'city' && cityParent.value === undefined) {
        return 'waiting';
    }

    if (list === null) {
        return 'waiting';
    }

    return (field === 'state' ? hasStates.value : hasCities.value)
        ? 'select'
        : 'text';
};

const waitingText = (field: 'state' | 'city'): string => {
    if (!country.value) {
        return t('kinetix.address_choose_country_first');
    }

    if (field === 'city' && hasStates.value && !stateId.value) {
        return t('kinetix.address_choose_state_first');
    }

    return t('kinetix.relation_loading');
};

const chooseCountry = (code: string | null): void => {
    if (!located.value) {
        set('country', code);

        return;
    }

    if ((code || null) === country.value) {
        return;
    }

    update({
        country: code || null,
        state: null,
        stateId: null,
        city: null,
        cityId: null,
    });
};

const chooseState = (value: string | null): void => {
    update({
        stateId: value || null,
        state: value ? (seen.get(value) ?? null) : null,
        city: null,
        cityId: null,
    });
};

const chooseCity = (value: string | null): void => {
    update({
        cityId: value || null,
        city: value ? (seen.get(value) ?? null) : null,
    });
};

/** A typed part in locations mode carries no id. */
const typeLevel = (field: 'state' | 'city', value: string): void => {
    update(
        field === 'state'
            ? { state: value || null, stateId: null, city: null, cityId: null }
            : { city: value || null, cityId: null },
    );
};

const orderedFields = computed(() => props.fields);
</script>

<template>
    <div class="gap-3 grid grid-cols-2">
        <div
            v-for="field in orderedFields"
            :key="field"
            class="space-y-1.5"
            :class="
                fullWidth(field) ? 'col-span-2' : 'sm:col-span-1 col-span-2'
            "
        >
            <KinetixLabel :for="id(field)">
                {{ t(`kinetix.${labels[field]}`) }}
                <span
                    v-if="isRequired(field)"
                    class="text-destructive"
                    aria-hidden="true"
                    >*</span
                >
            </KinetixLabel>

            <KinetixCombobox
                v-if="field === 'country'"
                :id="id(field)"
                :value="val('country')"
                :options="countries"
                :disabled="disabled"
                :placeholder="t('kinetix.address_country')"
                :aria-required="isRequired(field) || undefined"
                @update:value="chooseCountry"
            />

            <template
                v-else-if="located && (field === 'state' || field === 'city')"
            >
                <KinetixCombobox
                    v-if="levelMode(field) === 'select'"
                    :id="id(field)"
                    :key="`${field}-${country}-${field === 'city' ? cityParent : ''}`"
                    :value="
                        field === 'state'
                            ? (address.stateId ?? null)
                            : (address.cityId ?? null)
                    "
                    :value-label="
                        field === 'state'
                            ? (address.state ?? null)
                            : (address.city ?? null)
                    "
                    :options="
                        field === 'state'
                            ? stateSource.options
                            : citySource.options
                    "
                    :fetch-options="
                        field === 'state'
                            ? stateSource.fetchOptions
                            : citySource.fetchOptions
                    "
                    :disabled="disabled"
                    :placeholder="t(`kinetix.${labels[field]}`)"
                    :aria-required="isRequired(field) || undefined"
                    :data-test="`address-${field}-select`"
                    @update:value="
                        field === 'state'
                            ? chooseState($event)
                            : chooseCity($event)
                    "
                />
                <input
                    v-else-if="levelMode(field) === 'text'"
                    :id="id(field)"
                    :value="val(field)"
                    type="text"
                    :disabled="disabled"
                    :required="isRequired(field)"
                    :class="inputClass"
                    @input="
                        typeLevel(
                            field,
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
                <KinetixCombobox
                    v-else
                    :id="id(field)"
                    :value="null"
                    disabled
                    :placeholder="waitingText(field)"
                    :data-test="`address-${field}-waiting`"
                />
            </template>

            <input
                v-else
                :id="id(field)"
                :value="val(field)"
                type="text"
                :disabled="disabled"
                :required="isRequired(field)"
                :class="inputClass"
                @input="set(field, ($event.target as HTMLInputElement).value)"
            />
        </div>
    </div>
</template>
