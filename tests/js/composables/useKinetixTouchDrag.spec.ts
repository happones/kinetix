import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { useKinetixTouchDrag } from '@/composables/useKinetixTouchDrag';
import type { KinetixTouchDragOptions } from '@/composables/useKinetixTouchDrag';

const harness = (options: Partial<KinetixTouchDragOptions<string>> = {}) => {
    const calls = {
        onStart: vi.fn(),
        onHover: vi.fn(),
        onDrop: vi.fn(),
        onCancel: vi.fn(),
    };
    let api!: ReturnType<typeof useKinetixTouchDrag<string>>;

    const wrapper = mount(
        defineComponent({
            setup() {
                api = useKinetixTouchDrag<string>({
                    targetAttr: 'data-target',
                    ...calls,
                    ...options,
                });

                return () => h('div');
            },
        }),
    );

    return { api, calls, wrapper };
};

const pointer = (type: string, init: PointerEventInit = {}): PointerEvent =>
    new PointerEvent(type, {
        bubbles: true,
        cancelable: true,
        pointerType: 'touch',
        isPrimary: true,
        clientX: 10,
        clientY: 10,
        ...init,
    });

/** A source element, and a drop target the finger is "over". */
const setUp = () => {
    const source = document.createElement('div');
    const target = document.createElement('div');
    target.setAttribute('data-target', 'b');
    document.body.append(source, target);
    document.elementFromPoint = vi.fn(() => target);

    return { source };
};

describe('useKinetixTouchDrag', () => {
    const elementFromPoint = document.elementFromPoint;

    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        document.elementFromPoint = elementFromPoint;
        document.body.innerHTML = '';
    });

    it('waits for the long-press by default, then lifts a clone', () => {
        const { api, calls } = harness();
        const { source } = setUp();

        api.startFromPointerDown(pointer('pointerdown'), source, 'a');
        expect(calls.onStart).not.toHaveBeenCalled();

        vi.advanceTimersByTime(250);
        expect(calls.onStart).toHaveBeenCalledWith('a');
        expect(calls.onHover).toHaveBeenCalledWith('b');
        expect(document.body.children).toHaveLength(3);

        window.dispatchEvent(pointer('pointerup'));
        expect(calls.onDrop).toHaveBeenCalledWith('a', 'b');
        expect(document.body.children).toHaveLength(2);
    });

    it('a dedicated grip starts the drag on touch, without a clone when asked', () => {
        const { api, calls } = harness({
            activation: 'immediate',
            clone: false,
        });
        const { source } = setUp();

        api.startFromPointerDown(pointer('pointerdown'), source, 'a');

        expect(calls.onStart).toHaveBeenCalledWith('a');
        expect(api.isTouchDragging.value).toBe(true);
        expect(document.body.children).toHaveLength(2);
    });

    it('ignores the mouse, which native drag-and-drop handles', () => {
        const { api, calls } = harness({ activation: 'immediate' });
        const { source } = setUp();

        api.startFromPointerDown(
            pointer('pointerdown', { pointerType: 'mouse' }),
            source,
            'a',
        );

        expect(calls.onStart).not.toHaveBeenCalled();
    });

    it('tells the host when the platform cancels an active drag', () => {
        const { api, calls } = harness({ activation: 'immediate' });
        const { source } = setUp();

        api.startFromPointerDown(pointer('pointerdown'), source, 'a');
        window.dispatchEvent(pointer('pointercancel'));

        expect(calls.onCancel).toHaveBeenCalledTimes(1);
        expect(calls.onDrop).not.toHaveBeenCalled();
        expect(api.isTouchDragging.value).toBe(false);
    });

    it('a cancelled long-press that never started is not a cancelled drag', () => {
        const { api, calls } = harness();
        const { source } = setUp();

        api.startFromPointerDown(pointer('pointerdown'), source, 'a');
        window.dispatchEvent(pointer('pointercancel'));

        expect(calls.onCancel).not.toHaveBeenCalled();
    });

    it('cancels an active drag when the component unmounts', () => {
        const { api, calls, wrapper } = harness({ activation: 'immediate' });
        const { source } = setUp();

        api.startFromPointerDown(pointer('pointerdown'), source, 'a');
        wrapper.unmount();

        expect(calls.onCancel).toHaveBeenCalledTimes(1);
    });

    it('scrolls the page near its bottom edge and re-reads what the finger is over', () => {
        const scrollBy = vi.fn();
        const windowScrollBy = window.scrollBy;
        window.scrollBy = scrollBy as typeof window.scrollBy;
        // The page moves under the still finger.
        scrollBy.mockImplementation(() => {
            Object.defineProperty(window, 'scrollY', {
                value: window.scrollY + 12,
                configurable: true,
            });
        });

        const { api, calls } = harness({
            activation: 'immediate',
            clone: false,
            scrollAxis: 'y',
        });
        const { source } = setUp();

        api.startFromPointerDown(
            pointer('pointerdown', { clientY: window.innerHeight - 5 }),
            source,
            'a',
        );
        calls.onHover.mockClear();
        const next = document.createElement('div');
        next.setAttribute('data-target', 'c');
        document.body.append(next);
        document.elementFromPoint = vi.fn(() => next);

        vi.advanceTimersToNextFrame();

        expect(scrollBy).toHaveBeenCalledWith(0, 12);
        expect(calls.onHover).toHaveBeenCalledWith('c');

        window.dispatchEvent(pointer('pointerup'));
        window.scrollBy = windowScrollBy;
    });
});
