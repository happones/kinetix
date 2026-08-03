import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';
import { useKinetixFocusTrap } from '@/composables/useKinetixFocusTrap';

/**
 * A minimal stand-in for the hand-rolled dialogs: a panel with three buttons,
 * rendered only while `open`, plus a background button that must stay
 * unreachable by Tab while the panel is open.
 */
const Harness = defineComponent({
    props: { open: { type: Boolean, default: true } },
    setup(props) {
        const panel = ref<HTMLElement | null>(null);
        const { headingId } = useKinetixFocusTrap({
            active: () => props.open,
            container: () => panel.value,
        });

        return () =>
            h('div', [
                h('button', { id: 'background' }, 'background'),
                props.open
                    ? h('div', { ref: panel, tabindex: '-1', id: 'panel' }, [
                          h('h2', { id: headingId }, 'Heading'),
                          h('button', { id: 'first' }, 'first'),
                          h('button', { id: 'middle' }, 'middle'),
                          h('button', { id: 'last' }, 'last'),
                      ])
                    : null,
            ]);
    },
});

const byId = (id: string) =>
    document.getElementById(id) as HTMLButtonElement | null;

/**
 * Dispatch Tab and report whether the trap took it over. `dispatchEvent`'s
 * return value is unreliable under happy-dom, so `defaultPrevented` is read off
 * the event itself.
 */
const pressTab = (shiftKey = false): boolean => {
    const event = new KeyboardEvent('keydown', {
        key: 'Tab',
        shiftKey,
        cancelable: true,
    });
    window.dispatchEvent(event);

    return event.defaultPrevented;
};

// Every trap is unmounted between tests: a still-mounted one keeps its window
// keydown listener and would hijack the next test's Tab.
const mounted: { unmount: () => void }[] = [];

const mountTrap = async (open = true) => {
    const wrapper = mount(Harness, {
        props: { open },
        attachTo: document.body,
    });
    mounted.push(wrapper);
    // The panel is created on the tick after mount, like a teleported dialog.
    await nextTick();
    await nextTick();

    return wrapper;
};

describe('useKinetixFocusTrap', () => {
    afterEach(() => {
        while (mounted.length > 0) {
            mounted.pop()!.unmount();
        }

        document.body.innerHTML = '';
    });

    it('moves focus into the panel when it opens', async () => {
        await mountTrap();

        expect(document.activeElement).toBe(byId('first'));
    });

    it('cycles Tab from the last stop back to the first', async () => {
        await mountTrap();

        byId('last')!.focus();
        expect(pressTab()).toBe(true); // the trap took the Tab over
        expect(document.activeElement).toBe(byId('first'));
    });

    it('cycles Shift+Tab from the first stop back to the last', async () => {
        await mountTrap();

        byId('first')!.focus();
        pressTab(true);
        expect(document.activeElement).toBe(byId('last'));
    });

    it('leaves Tab alone between two stops inside the panel', async () => {
        await mountTrap();

        byId('middle')!.focus();
        // Not at an edge → the browser's own Tab order applies.
        expect(pressTab()).toBe(false);
        expect(document.activeElement).toBe(byId('middle'));
    });

    it('pulls focus back in when it sits outside the panel', async () => {
        await mountTrap();

        byId('background')!.focus();
        pressTab();
        expect(document.activeElement).toBe(byId('first'));
    });

    it('restores focus to the opener when it closes', async () => {
        const opener = document.createElement('button');
        opener.id = 'opener';
        document.body.append(opener);
        opener.focus();

        const wrapper = await mountTrap();
        expect(document.activeElement).toBe(byId('first'));

        await wrapper.setProps({ open: false });
        expect(document.activeElement).toBe(byId('opener'));
    });

    it('stops trapping Tab once closed', async () => {
        const wrapper = await mountTrap();
        await wrapper.setProps({ open: false });

        byId('background')!.focus();
        // No handler left → the event is untouched and so is focus.
        expect(pressTab()).toBe(false);
        expect(document.activeElement).toBe(byId('background'));
    });

    it('removes its keydown listener on unmount', async () => {
        const wrapper = await mountTrap();
        wrapper.unmount();

        const outside = document.createElement('button');
        document.body.append(outside);
        outside.focus();

        expect(pressTab()).toBe(false);
        expect(document.activeElement).toBe(outside);
    });

    it('never traps while closed from the start', async () => {
        await mountTrap(false);

        byId('background')!.focus();
        expect(pressTab()).toBe(false);
    });

    it('gives each trap in an app its own heading id', () => {
        const TwoTraps = defineComponent({
            setup() {
                const a = useKinetixFocusTrap({
                    active: () => false,
                    container: () => null,
                });
                const b = useKinetixFocusTrap({
                    active: () => false,
                    container: () => null,
                });

                return () =>
                    h('div', [
                        h('span', { class: 'a' }, a.headingId),
                        h('span', { class: 'b' }, b.headingId),
                    ]);
            },
        });

        const wrapper = mount(TwoTraps);
        const first = wrapper.get('.a').text();
        const second = wrapper.get('.b').text();

        expect(first).toMatch(/^kinetix-dialog-/);
        expect(second).not.toBe(first);
    });
});
