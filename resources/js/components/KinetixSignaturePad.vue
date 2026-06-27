<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useShadcnVariants';

/** Serialized signature config. */
interface SignatureConfig {
    penColor?: string | null;
    backgroundColor?: string | null;
    height?: number | null;
}

/**
 * A canvas signature pad. Emits the drawing as a PNG data URL on every stroke;
 * a Clear button resets it. Works with mouse, touch and pen via Pointer Events.
 */
const props = withDefaults(
    defineProps<{
        value?: string | null;
        config?: SignatureConfig | null;
        disabled?: boolean;
    }>(),
    { value: null, config: null, disabled: false },
);

const emit = defineEmits<{ (e: 'update:value', value: string | null): void }>();

const { t } = useI18n();
const canvas = ref<HTMLCanvasElement | null>(null);
let ctx: CanvasRenderingContext2D | null = null;
let drawing = false;

const penColor = () => props.config?.penColor || '#0f172a';
const height = () => props.config?.height ?? 160;

function prepare(): void {
    const el = canvas.value;

    if (!el) {
        return;
    }

    const ratio = window.devicePixelRatio || 1;
    const rect = el.getBoundingClientRect();
    el.width = rect.width * ratio;
    el.height = height() * ratio;
    ctx = el.getContext('2d');

    if (!ctx) {
        return;
    }

    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = penColor();

    if (props.config?.backgroundColor) {
        ctx.fillStyle = props.config.backgroundColor;
        ctx.fillRect(0, 0, rect.width, height());
    }

    if (props.value) {
        const img = new Image();
        img.onload = () => ctx?.drawImage(img, 0, 0, rect.width, height());
        img.src = props.value;
    }
}

function pos(event: PointerEvent): { x: number; y: number } {
    const rect = canvas.value!.getBoundingClientRect();

    return { x: event.clientX - rect.left, y: event.clientY - rect.top };
}

function start(event: PointerEvent): void {
    if (props.disabled || !ctx) {
        return;
    }

    drawing = true;
    canvas.value?.setPointerCapture(event.pointerId);
    const { x, y } = pos(event);
    ctx.beginPath();
    ctx.moveTo(x, y);
}

function move(event: PointerEvent): void {
    if (!drawing || !ctx) {
        return;
    }

    const { x, y } = pos(event);
    ctx.lineTo(x, y);
    ctx.stroke();
}

function end(): void {
    if (!drawing) {
        return;
    }

    drawing = false;
    emit('update:value', canvas.value?.toDataURL('image/png') ?? null);
}

function clear(): void {
    const el = canvas.value;

    if (!el || !ctx) {
        return;
    }

    ctx.clearRect(0, 0, el.width, el.height);

    if (props.config?.backgroundColor) {
        ctx.fillStyle = props.config.backgroundColor;
        ctx.fillRect(0, 0, el.width, el.height);
    }

    emit('update:value', null);
}

onMounted(prepare);
const onResize = () => prepare();
onMounted(() => window.addEventListener('resize', onResize));
onBeforeUnmount(() => window.removeEventListener('resize', onResize));
</script>

<template>
    <div class="space-y-2">
        <div
            class="shadow-xs overflow-hidden rounded-md border border-input bg-background dark:bg-input/30"
            :class="disabled ? 'cursor-not-allowed opacity-50' : ''"
        >
            <canvas
                ref="canvas"
                class="w-full touch-none"
                :style="{ height: `${height()}px` }"
                @pointerdown="start"
                @pointermove="move"
                @pointerup="end"
                @pointerleave="end"
            />
        </div>
        <button
            type="button"
            :disabled="disabled"
            :class="buttonVariants({ variant: 'outline', size: 'sm' })"
            @click="clear"
        >
            {{ t('kinetix.signature_clear') }}
        </button>
    </div>
</template>
