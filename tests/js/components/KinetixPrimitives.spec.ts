import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Card from '@/components/primitives/Card.vue';
import CardContent from '@/components/primitives/CardContent.vue';
import CardFooter from '@/components/primitives/CardFooter.vue';
import CardHeader from '@/components/primitives/CardHeader.vue';
import { cn } from '@/components/primitives/cn';
import {
    badgeVariants,
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';

describe('cn', () => {
    it('joins truthy classes and drops falsy ones', () => {
        expect(cn('a', false, undefined, null, 'b')).toBe('a b');
    });
});

describe('Card primitives (new-york-v4 parity)', () => {
    it('Card uses the v4 structure: gap-6 + py-6, not per-section p-6', () => {
        const wrapper = mount(Card);
        const cls = wrapper.get('[data-slot="card"]').classes();

        expect(cls).toContain('flex');
        expect(cls).toContain('flex-col');
        expect(cls).toContain('gap-6');
        expect(cls).toContain('py-6');
        expect(cls).toContain('rounded-xl');
        expect(cls).toContain('bg-card');
        expect(cls).not.toContain('p-6');
    });

    it('CardHeader / CardContent / CardFooter use px-6', () => {
        expect(
            mount(CardHeader).get('[data-slot="card-header"]').classes(),
        ).toContain('px-6');
        expect(
            mount(CardContent).get('[data-slot="card-content"]').classes(),
        ).toContain('px-6');
        expect(
            mount(CardFooter).get('[data-slot="card-footer"]').classes(),
        ).toContain('px-6');
    });

    it('merges a custom class via the class prop', () => {
        const wrapper = mount(Card, { props: { class: 'justify-between' } });

        expect(wrapper.get('[data-slot="card"]').classes()).toContain(
            'justify-between',
        );
    });
});

/**
 * Parity guard: these are the distinguishing tokens of the shadcn new-york-v4
 * recipe, not a copy of the class strings. A restyle that keeps the contract
 * (variant colour, size height, pill shape, focus ring) stays green; dropping
 * one of them is a deliberate departure from v4 and should fail here.
 */
describe('shadcn variants (new-york-v4 parity)', () => {
    it.each([
        [
            'button default',
            () => buttonVariants(),
            [
                'bg-primary',
                'text-primary-foreground',
                'h-9',
                'focus-visible:ring-ring/50',
            ],
        ],
        [
            'button outline + sm',
            () => buttonVariants({ variant: 'outline', size: 'sm' }),
            ['border-input', 'h-8'],
        ],
        ['badge default', () => badgeVariants(), ['rounded-full']],
        [
            'badge secondary',
            () => badgeVariants({ variant: 'secondary' }),
            ['rounded-full', 'bg-secondary'],
        ],
        [
            'input field',
            () => inputClass,
            ['h-9', 'border-input', 'focus-visible:ring-ring/50'],
        ],
    ] as const)('%s keeps its v4 tokens', (_name, build, tokens) => {
        const classes = build().split(/\s+/);

        for (const token of tokens) {
            expect(classes).toContain(token);
        }
    });
});

describe('variant contract', () => {
    const buttonVariantNames = [
        'default',
        'destructive',
        'outline',
        'secondary',
        'ghost',
        'link',
        'success',
        'warning',
        'info',
        'ghost-current',
    ] as const;

    it('every button variant and size selects a different class string', () => {
        const byVariant = buttonVariantNames.map((variant) =>
            buttonVariants({ variant }),
        );
        const bySize = (
            ['default', 'sm', 'lg', 'icon', 'icon-sm', 'icon-lg'] as const
        ).map((size) => buttonVariants({ size }));

        expect(new Set(byVariant).size).toBe(byVariant.length);
        expect(new Set(bySize).size).toBe(bySize.length);
    });

    it('a variant swaps its own colour tokens and keeps the shared base', () => {
        const destructive = buttonVariants({ variant: 'destructive' }).split(
            /\s+/,
        );

        expect(destructive).toContain('bg-destructive');
        expect(destructive).not.toContain('bg-primary');
        expect(destructive).toEqual(
            expect.arrayContaining([
                'inline-flex',
                'items-center',
                'rounded-md',
            ]),
        );
    });
});
