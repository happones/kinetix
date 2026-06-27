/**
 * Stub of @/composables/useKinetixHttp for the gallery. `kinetixFetch` returns
 * canned fixtures based on the URL so self-fetching components (e.g. the
 * onboarding checklist) render fully without a backend.
 */
const fixtures: Array<{ match: RegExp; data: unknown }> = [
    {
        match: /\/tokens$/,
        data: {
            tokens: [
                {
                    id: 1,
                    name: 'CI deploy',
                    abilities: ['posts.read', 'posts.write'],
                    lastUsedAt: '2026-06-20T10:00:00Z',
                    createdAt: '2026-06-01T09:00:00Z',
                },
                {
                    id: 2,
                    name: 'Zapier',
                    abilities: ['*'],
                    lastUsedAt: null,
                    createdAt: '2026-06-18T14:30:00Z',
                },
            ],
            scopes: {
                'posts.read': 'Read posts',
                'posts.write': 'Write posts',
            },
        },
    },
    {
        match: /\/webhooks$/,
        data: {
            endpoints: [
                {
                    id: 1,
                    name: 'Order events',
                    url: 'https://example.com/hooks/orders',
                    events: ['order.created', 'order.shipped'],
                    active: true,
                    createdAt: '2026-06-10T09:00:00Z',
                },
                {
                    id: 2,
                    name: 'Billing sync',
                    url: 'https://example.com/hooks/billing',
                    events: ['invoice.paid'],
                    active: false,
                    createdAt: '2026-06-12T11:00:00Z',
                },
            ],
            events: {
                'order.created': 'Order created',
                'order.shipped': 'Order shipped',
                'invoice.paid': 'Invoice paid',
            },
        },
    },
    {
        match: /\/activity$/,
        data: {
            data: [
                {
                    id: 1,
                    event: 'updated',
                    description: null,
                    causerName: 'Ada Lovelace',
                    causerId: 1,
                    subjectType: 'Order',
                    subjectId: 1042,
                    changes: {
                        old: { status: 'pending' },
                        attributes: { status: 'paid' },
                    },
                    createdAt: '2026-06-20T10:00:00Z',
                },
                {
                    id: 2,
                    event: 'created',
                    description: null,
                    causerName: 'Grace Hopper',
                    causerId: 2,
                    subjectType: 'Order',
                    subjectId: 1043,
                    changes: { old: {}, attributes: {} },
                    createdAt: '2026-06-19T16:30:00Z',
                },
            ],
            pagination: { current_page: 1, last_page: 1 },
        },
    },
    {
        match: /\/permissions\/features$/,
        data: [
            {
                name: 'users',
                label: 'Users',
                abilities: [
                    { name: 'users.view', label: 'View' },
                    { name: 'users.create', label: 'Create' },
                    { name: 'users.delete', label: 'Delete' },
                ],
            },
            {
                name: 'orders',
                label: 'Orders',
                abilities: [
                    { name: 'orders.view', label: 'View' },
                    { name: 'orders.refund', label: 'Refund' },
                ],
            },
        ],
    },
    {
        match: /\/permissions\/roles$/,
        data: [
            {
                id: 1,
                name: 'admin',
                permissions: [
                    'users.view',
                    'users.create',
                    'users.delete',
                    'orders.view',
                    'orders.refund',
                ],
            },
            {
                id: 2,
                name: 'editor',
                permissions: ['users.view', 'orders.view'],
            },
        ],
    },
    {
        match: /\/members$/,
        data: {
            provisions: [
                {
                    id: 1,
                    email: 'ada@example.com',
                    name: 'Ada Lovelace',
                    role: 'editor',
                    status: 'active',
                    expired: false,
                    activatedAt: '2026-06-10T09:00:00Z',
                    expiresAt: null,
                },
                {
                    id: 2,
                    email: 'grace@example.com',
                    name: null,
                    role: 'viewer',
                    status: 'pending',
                    expired: false,
                    activatedAt: null,
                    expiresAt: '2026-07-01T00:00:00Z',
                },
            ],
            assignable_roles: ['editor', 'viewer'],
        },
    },
    {
        match: /\/connected-accounts$/,
        data: {
            accounts: [
                {
                    id: 1,
                    provider: 'github',
                    name: 'Ada Lovelace',
                    nickname: 'ada',
                    email: 'ada@example.com',
                    avatar: null,
                    createdAt: '2026-06-01T09:00:00Z',
                },
            ],
            providers: [
                {
                    key: 'github',
                    label: 'GitHub',
                    icon: 'github',
                    color: '#181717',
                    linked: true,
                },
                {
                    key: 'google',
                    label: 'Google',
                    icon: 'google',
                    color: '#4285F4',
                    linked: false,
                },
            ],
            hasPassword: false,
        },
    },
    {
        match: /\/saved-views(\?|$)/,
        data: {
            views: [
                { id: 1, name: 'All products', state: {}, isDefault: false },
                { id: 2, name: 'Active', state: {}, isDefault: true },
                { id: 3, name: 'Low stock', state: {}, isDefault: false },
            ],
        },
    },
    {
        match: /\/tags(\?|$)/,
        data: { tags: ['laravel', 'vue', 'inertia', 'shadcn'] },
    },
    {
        match: /\/notification-preferences$/,
        data: {
            channels: [
                { key: 'mail', label: 'Email' },
                { key: 'database', label: 'In-app' },
                { key: 'broadcast', label: 'Push' },
            ],
            types: [
                {
                    key: 'orders',
                    label: 'Order updates',
                    channels: { mail: true, database: true, broadcast: true },
                },
                {
                    key: 'mentions',
                    label: 'Mentions & replies',
                    channels: { mail: false, database: true, broadcast: true },
                },
                {
                    key: 'marketing',
                    label: 'Marketing & tips',
                    channels: { mail: false, database: true, broadcast: false },
                },
            ],
        },
    },
    {
        match: /\/comments(\?|$)/,
        data: {
            comments: [
                {
                    id: 1,
                    body: 'This looks great — ship it! 🚀',
                    authorId: 1,
                    authorName: 'Ada Lovelace',
                    authorAvatar: null,
                    parentId: null,
                    createdAt: '2026-06-26T10:00:00Z',
                    edited: false,
                    editable: true,
                    replies: [
                        {
                            id: 2,
                            body: "Agreed. I'll review the tests this afternoon.",
                            authorId: 2,
                            authorName: 'Grace Hopper',
                            authorAvatar: null,
                            parentId: 1,
                            createdAt: '2026-06-26T10:30:00Z',
                            edited: false,
                            editable: false,
                            replies: [],
                        },
                    ],
                },
                {
                    id: 3,
                    body: 'One nit: can we rename this field?',
                    authorId: 2,
                    authorName: 'Grace Hopper',
                    authorAvatar: null,
                    parentId: null,
                    createdAt: '2026-06-26T11:15:00Z',
                    edited: true,
                    editable: false,
                    replies: [],
                },
            ],
        },
    },
    {
        match: /\/sessions$/,
        data: {
            sessions: [
                {
                    id: 'a1',
                    ipAddress: '203.0.113.10',
                    browser: 'Chrome',
                    platform: 'macOS',
                    device: 'desktop',
                    isCurrentDevice: true,
                    lastActive: '2026-06-26T10:00:00Z',
                },
                {
                    id: 'b2',
                    ipAddress: '198.51.100.4',
                    browser: 'Safari',
                    platform: 'iOS',
                    device: 'mobile',
                    isCurrentDevice: false,
                    lastActive: '2026-06-25T18:30:00Z',
                },
                {
                    id: 'c3',
                    ipAddress: '192.0.2.7',
                    browser: 'Firefox',
                    platform: 'Windows',
                    device: 'desktop',
                    isCurrentDevice: false,
                    lastActive: '2026-06-20T09:15:00Z',
                },
            ],
            databaseDriver: true,
            requiresPassword: true,
        },
    },
    {
        match: /\/onboarding$/,
        data: {
            steps: [
                {
                    key: 'verify-email',
                    title: 'Verify your email',
                    description: 'Confirm your address to unlock everything.',
                    ctaLabel: 'Resend',
                    ctaHref: '#',
                    icon: 'mail',
                    completed: true,
                    manual: false,
                },
                {
                    key: 'invite',
                    title: 'Invite a teammate',
                    description: 'Collaboration is better together.',
                    ctaLabel: 'Invite',
                    ctaHref: '#',
                    icon: 'user',
                    completed: false,
                    manual: false,
                },
                {
                    key: 'read-docs',
                    title: 'Read the quickstart',
                    description: null,
                    ctaLabel: null,
                    ctaHref: null,
                    icon: null,
                    completed: false,
                    manual: true,
                },
            ],
            completedCount: 1,
            total: 3,
            complete: false,
            dismissed: false,
        },
    },
];

export async function kinetixFetch<T = unknown>(
    url: string,
): Promise<T | null> {
    const hit = fixtures.find((f) => f.match.test(url));
    return (hit ? (hit.data as T) : null) ?? null;
}

export function kinetixRoutePrefix(): string {
    return '_kinetix';
}
