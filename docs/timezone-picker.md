# Timezone Picker

A searchable, richly-configurable timezone combobox — built on the same Reka
Combobox primitives as [`KinetixCombobox`](/tables), over **every IANA zone
the runtime supports** (`Intl.supportedValuesOf('timeZone')`), with no
bundled zone list to maintain.

<Screenshot name="timezone-picker" alt="Timezone picker — default and offset-only variants" />

```vue
<script setup lang="ts">
import { ref } from 'vue';
import KinetixTimezonePicker from '@/components/kinetix/KinetixTimezonePicker.vue';

const timezone = ref<string | null>('America/Mexico_City');
</script>

<template>
    <KinetixTimezonePicker v-model="timezone" clearable show-current-time />
</template>
```

---

## Props

| Prop              | Type                             | Default   | Notes |
| ------------------ | --------------------------------- | --------- | ----- |
| `modelValue`       | `string \| null`                   | `null`    | The selected IANA zone (`v-model`), e.g. `America/Mexico_City` |
| `regions`          | `string[] \| null`                 | `null`    | Restrict the list to these IANA region prefixes (`Africa`, `America`, `Antarctica`, `Arctic`, `Asia`, `Atlantic`, `Australia`, `Europe`, `Indian`, `Pacific`). `null` = every region |
| `display`          | `'name' \| 'offset' \| 'both'`     | `'both'`  | What each option (and the trigger) shows — see [§ Display modes](#display-modes) |
| `groupByRegion`    | `boolean`                         | `true`    | Group options under a region heading in the dropdown |
| `showCurrentTime`  | `boolean`                         | `false`   | Show a live-updating current time next to the selected zone |
| `locale`           | `string \| null`                   | `null`    | BCP-47 locale for the current-time preview. Defaults to the browser locale |
| `placeholder`      | `string \| null`                   | `null`    | Overrides the default "Select a timezone…" |
| `disabled`         | `boolean`                         | `false`   | |
| `clearable`        | `boolean`                         | `false`   | Show a clear (×) affordance once a zone is selected |

Emits `update:modelValue`.

---

## Display modes

`display` controls what each option — and the trigger once a zone is
selected — shows:

- **`both`** *(default)* — `Mexico City (UTC-06:00)`.
- **`name`** — just the city/region name: `Mexico City`.
- **`offset`** — just the UTC offset, no name at all: `UTC-06:00`. Handy when
  you want a compact picker that doesn't leak (or need) a location name.

<Screenshot name="timezone-picker-open" alt="Timezone picker — searchable dropdown open" />

```vue
<!-- Offset only, restricted to the Americas and Europe -->
<KinetixTimezonePicker
    v-model="timezone"
    display="offset"
    :regions="['America', 'Europe']"
/>
```

---

## Region grouping & filtering

By default the dropdown groups options under a localized region heading
(*Africa, America, Asia, …*). Pass `regions` to restrict which regions appear
at all (e.g. a US-only app might pass `:regions="['America']'"`), or
`:group-by-region="false"` for a flat, ungrouped list — options are always
sorted by UTC offset, then name, within (or across) groups.

---

## Current-time preview

`show-current-time` appends a live clock next to the selected zone's label
(refreshed every 30s), so users can sanity-check they picked the right zone
before confirming — e.g. `Mexico City (UTC-06:00) · 12:34 AM`.

---

## Localization

The search input, empty state, and region headings are all localized
(`timezone_*`, en/es/fr/pt/zh/ja/ru). City/region names themselves come
straight from the IANA identifier (e.g. `Mexico City`, `Buenos Aires`) —
these aren't translated per locale (consistent with how most timezone
pickers work), but everything *around* them is.
