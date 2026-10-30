<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import {
    fetchKinetixLocations,
    fetchKinetixPostalCode,
} from '@/composables/useKinetixLocations';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import type {
    KinetixAddress,
    KinetixLocationList,
    KinetixNeighborhoodOption,
    KinetixOption,
    KinetixPostalCodeMatch,
    KinetixSharedProps,
} from '@/types/kinetix';
import KinetixCombobox from './KinetixCombobox.vue';
import KinetixLabel from './KinetixLabel.vue';

/**
 * Structured address field. Value is `{ line1, line2, city, state, postalCode,
 * country }`, plus `stateId` / `cityId` in locations mode and `neighborhood`
 * (`neighborhoodId`) when that part is shown.
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
 *
 * From a provider that knows postal codes (`neighborhoods`), the city's
 * neighborhoods are a fourth level, and picking one fills the postal code.
 * With `postal-code-lookup`, typing the code fills the state and city and
 * lists only the neighborhoods that code serves.
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
        /** The provider lists each city's neighborhoods (locations mode). */
        neighborhoods?: boolean;
        /** Typing the postal code fills state, city and neighborhoods. */
        postalCodeLookup?: boolean;
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
        neighborhoods: false,
        postalCodeLookup: false,
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
    neighborhood: 'address_neighborhood',
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

type Level = 'state' | 'city' | 'neighborhood';

const located = computed(() => !!props.locationsToken);
const showsState = computed(() => props.fields.includes('state'));
const showsNeighborhood = computed(
    () =>
        located.value &&
        props.neighborhoods &&
        props.fields.includes('neighborhood'),
);
const country = computed(() => address.value.country ?? null);
const stateId = computed(() => address.value.stateId ?? null);
const cityId = computed(() => address.value.cityId ?? null);

const stateList = ref<KinetixLocationList | null>(null);
const cityList = ref<KinetixLocationList | null>(null);
const neighborhoodList = ref<KinetixLocationList | null>(null);

/** Labels of everything listed or found, for the name stored next to an id. */
const seen = new Map<string, string>();
/** Each neighborhood's postal code, to fill the code when one is picked. */
const postalCodes = new Map<string, string>();

const remember = (list: KinetixLocationList): KinetixLocationList => {
    list.options.forEach((option) => {
        seen.set(option.value, option.label);

        const code = (option as Partial<KinetixNeighborhoodOption>).postalCode;

        if (code) {
            postalCodes.set(option.value, code);
        }
    });

    return list;
};

const load = async (
    level: 'states' | 'cities' | 'neighborhoods',
    state: string | null,
    q = '',
    city: string | null = null,
): Promise<KinetixLocationList> =>
    remember(
        await fetchKinetixLocations(kinetixRoutePrefix(page), {
            token: props.locationsToken as string,
            level,
            country: country.value as string,
            state,
            city,
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

/** The last postal code looked up, and what it told. */
const postalMatch = ref<KinetixPostalCodeMatch | null>(null);

/**
 * The neighborhoods to choose from: the ones the typed postal code serves
 * while its city is the chosen one, otherwise every one of the city's.
 */
const matchedNeighborhoods = computed<KinetixLocationList | null>(() =>
    postalMatch.value !== null &&
    cityId.value !== null &&
    postalMatch.value.city?.value === cityId.value
        ? { options: postalMatch.value.neighborhoods, complete: true }
        : null,
);

let neighborhoodRequest = 0;

watch(
    [showsNeighborhood, cityId, matchedNeighborhoods],
    async ([shows, city, matched]) => {
        neighborhoodList.value = null;
        const request = ++neighborhoodRequest;

        if (!shows || city === null) {
            return;
        }

        const list =
            matched ??
            (await load('neighborhoods', stateId.value, '', city).catch(
                () => NOTHING,
            ));

        if (request === neighborhoodRequest) {
            neighborhoodList.value = list;
        }
    },
    { immediate: true },
);

const hasNeighborhoods = computed(
    () => (neighborhoodList.value?.options.length ?? 0) > 0,
);

/**
 * What a combobox gets for a level: the whole list to filter locally, or —
 * past one page — a server search seeded with that first page.
 */
const source = (
    list: KinetixLocationList | null,
    search: (q: string) => Promise<KinetixLocationList>,
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
            q.trim() === '' ? first : (await search(q.trim())).options,
    };
};

const sources = computed(() => ({
    state: source(stateList.value, (q) => load('states', null, q)),
    city: source(cityList.value, (q) =>
        load('cities', cityParent.value ?? null, q),
    ),
    neighborhood: source(neighborhoodList.value, (q) =>
        load('neighborhoods', stateId.value, q, cityId.value),
    ),
}));

const isLevel = (field: string): field is Level =>
    field === 'state' ||
    field === 'city' ||
    (field === 'neighborhood' && showsNeighborhood.value);

/** How a level renders in locations mode. */
const levelMode = (field: Level): 'select' | 'text' | 'waiting' => {
    if (!country.value) {
        return 'waiting';
    }

    if (field === 'neighborhood') {
        // A city typed as text (nothing to choose) leaves the neighborhood
        // to type as well.
        if (props.fields.includes('city') && levelMode('city') === 'text') {
            return 'text';
        }

        if (neighborhoodList.value === null) {
            return 'waiting';
        }

        return hasNeighborhoods.value ? 'select' : 'text';
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

const waitingText = (field: Level): string => {
    if (!country.value) {
        return t('kinetix.address_choose_country_first');
    }

    if (field === 'city' && hasStates.value && !stateId.value) {
        return t('kinetix.address_choose_state_first');
    }

    if (field === 'neighborhood' && !cityId.value) {
        return t('kinetix.address_choose_city_first');
    }

    return t('kinetix.relation_loading');
};

const levelValue = (field: Level): string | null =>
    ({
        state: address.value.stateId,
        city: address.value.cityId,
        neighborhood: address.value.neighborhoodId,
    })[field] ?? null;

const levelLabel = (field: Level): string | null =>
    address.value[field] ?? null;

const levelKey = (field: Level): string =>
    ({
        state: `state-${country.value}`,
        city: `city-${country.value}-${cityParent.value}`,
        neighborhood: `neighborhood-${country.value}-${cityId.value}-${matchedNeighborhoods.value ? 'code' : 'city'}`,
    })[field];

/** A changed level clears the neighborhood, when the picker shows one. */
const noNeighborhood = (): Partial<KinetixAddress> =>
    props.fields.includes('neighborhood')
        ? { neighborhood: null, neighborhoodId: null }
        : {};

const chooseCountry = (code: string | null): void => {
    if (!located.value) {
        set('country', code);

        return;
    }

    if ((code || null) === country.value) {
        return;
    }

    postalMatch.value = null;

    update({
        country: code || null,
        state: null,
        stateId: null,
        city: null,
        cityId: null,
        ...noNeighborhood(),
    });
};

const chooseLevel = (field: Level, value: string | null): void => {
    const label = value ? (seen.get(value) ?? null) : null;

    if (field === 'state') {
        update({
            stateId: value || null,
            state: label,
            city: null,
            cityId: null,
            ...noNeighborhood(),
        });

        return;
    }

    if (field === 'city') {
        update({ cityId: value || null, city: label, ...noNeighborhood() });

        return;
    }

    const code = value ? postalCodes.get(value) : undefined;

    update({
        neighborhoodId: value || null,
        neighborhood: label,
        ...(code ? { postalCode: code } : {}),
    });
};

/** A typed part in locations mode carries no id. */
const typeLevel = (field: Level, value: string): void => {
    const patches: Record<Level, Partial<KinetixAddress>> = {
        state: {
            state: value || null,
            stateId: null,
            city: null,
            cityId: null,
            ...noNeighborhood(),
        },
        city: { city: value || null, cityId: null, ...noNeighborhood() },
        neighborhood: { neighborhood: value || null, neighborhoodId: null },
    };

    update(patches[field]);
};

// ── Postal code lookup ──────────────────────────────────────────────────────

const looksUpPostalCode = computed(
    () =>
        located.value &&
        props.postalCodeLookup &&
        props.fields.includes('postalCode'),
);

/** What the last lookup found, announced under the postal code. */
const postalStatus = ref('');

let postalTimer: ReturnType<typeof setTimeout> | undefined;
let postalRequest = 0;

onBeforeUnmount(() => clearTimeout(postalTimer));

const applyPostalCode = (match: KinetixPostalCodeMatch | null): void => {
    postalMatch.value = match;

    if (match === null) {
        postalStatus.value = t('kinetix.address_postal_code_not_found');

        return;
    }

    [match.state, match.city, ...match.neighborhoods].forEach((option) => {
        if (option) {
            seen.set(option.value, option.label);
        }
    });
    remember({ options: match.neighborhoods, complete: true });

    const patch: Partial<KinetixAddress> = {};

    if (match.state) {
        Object.assign(patch, {
            state: match.state.label,
            stateId: match.state.value,
        });
    }

    if (match.city) {
        Object.assign(patch, {
            city: match.city.label,
            cityId: match.city.value,
        });
    }

    if (props.fields.includes('neighborhood')) {
        const current = address.value.neighborhoodId;
        const only =
            match.neighborhoods.length === 1 ? match.neighborhoods[0] : null;
        const kept = match.neighborhoods.find((n) => n.value === current);
        const chosen = only ?? kept ?? null;

        Object.assign(patch, {
            neighborhood: chosen?.label ?? null,
            neighborhoodId: chosen?.value ?? null,
        });
    }

    update(patch);

    const place = [match.city?.label, match.state?.label]
        .filter(Boolean)
        .join(', ');

    postalStatus.value = place
        ? t('kinetix.address_postal_code_found', { place })
        : '';
};

const typePostalCode = (value: string): void => {
    set('postalCode', value);

    if (!looksUpPostalCode.value) {
        return;
    }

    clearTimeout(postalTimer);
    const request = ++postalRequest;
    const code = value.trim().toUpperCase();

    if (code.length < 3 || !country.value) {
        postalStatus.value = '';

        return;
    }

    postalTimer = setTimeout(async () => {
        const match = await fetchKinetixPostalCode(kinetixRoutePrefix(page), {
            token: props.locationsToken as string,
            country: country.value as string,
            code,
        }).catch(() => undefined);

        // A failed lookup says nothing: the levels stay to choose by hand.
        if (request === postalRequest && match !== undefined) {
            applyPostalCode(match);
        }
    }, 350);
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

            <template v-else-if="located && isLevel(field)">
                <KinetixCombobox
                    v-if="levelMode(field) === 'select'"
                    :id="id(field)"
                    :key="levelKey(field)"
                    :value="levelValue(field)"
                    :value-label="levelLabel(field)"
                    :options="sources[field].options"
                    :fetch-options="sources[field].fetchOptions"
                    :disabled="disabled"
                    :placeholder="t(`kinetix.${labels[field]}`)"
                    :aria-required="isRequired(field) || undefined"
                    :data-test="`address-${field}-select`"
                    @update:value="chooseLevel(field, $event)"
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

            <template v-else-if="field === 'postalCode' && looksUpPostalCode">
                <input
                    :id="id(field)"
                    :value="val(field)"
                    type="text"
                    inputmode="text"
                    autocomplete="postal-code"
                    :disabled="disabled"
                    :required="isRequired(field)"
                    :aria-describedby="id('postalCode-status')"
                    :class="inputClass"
                    @input="
                        typePostalCode(
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
                <p
                    :id="id('postalCode-status')"
                    role="status"
                    aria-live="polite"
                    class="text-xs text-muted-foreground"
                    data-test="address-postal-code-status"
                >
                    {{ postalStatus }}
                </p>
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
