/**
 * Stub of @/composables/useKinetixHttp for the gallery. `kinetixFetch` returns
 * canned fixtures based on the URL so self-fetching components (e.g. the
 * onboarding checklist) render fully without a backend.
 */
const fixtures: Array<{ match: RegExp; data: unknown }> = [
    {
        match: /\/mail-templates\/preview$/,
        data: {
            subject: 'Welcome to Acme, Ada!',
            html: '<h1>Hi Ada 👋</h1><p>Thanks for joining Acme. Your trial ends on <strong>July 15</strong>.</p><p><a href="#">Get started →</a></p>',
        },
    },
    {
        match: /\/mail-templates$/,
        data: {
            templates: [
                {
                    id: 1,
                    key: 'welcome',
                    name: 'Welcome email',
                    subject: 'Welcome to Acme, {{ name }}!',
                    body: '# Hi {{ name }} 👋\n\nThanks for joining Acme. Your trial ends on **{{ trial_ends }}**.\n\n[Get started →](#)',
                    format: 'markdown',
                    variables: [
                        { key: 'name', label: 'Name', sample: 'Ada' },
                        {
                            key: 'trial_ends',
                            label: 'Trial end',
                            sample: 'July 15',
                        },
                    ],
                    enabled: true,
                },
                {
                    id: 2,
                    key: 'receipt',
                    name: 'Order receipt',
                    subject: 'Your receipt #{{ order }}',
                    body: 'Thanks for your order **#{{ order }}** — total {{ total }}.',
                    format: 'markdown',
                    variables: [],
                    enabled: true,
                },
                {
                    id: 3,
                    key: 'password-reset',
                    name: 'Password reset',
                    subject: 'Reset your password',
                    body: '<p>Click to reset.</p>',
                    format: 'html',
                    variables: [],
                    enabled: false,
                },
            ],
        },
    },
    {
        match: /\/health$/,
        data: {
            available: true,
            status: 'warning',
            checkedAt: '2026-06-27T10:00:00Z',
            checks: [
                {
                    name: 'database',
                    label: 'Database',
                    status: 'ok',
                    message: 'Reachable',
                },
                {
                    name: 'cache',
                    label: 'Cache',
                    status: 'ok',
                    message: 'Working',
                },
                { name: 'redis', label: 'Redis', status: 'ok', message: null },
                {
                    name: 'disk',
                    label: 'Used Disk Space',
                    status: 'warning',
                    message: 'Disk usage at 85%',
                },
                {
                    name: 'queue',
                    label: 'Queue',
                    status: 'ok',
                    message: 'Default queue healthy',
                },
            ],
        },
    },
    {
        match: /\/queue$/,
        data: {
            horizon: true,
            status: 'running',
            throughput: 128,
            recentJobs: 4200,
            failedJobs: 2,
            failed: [
                {
                    id: 'a1b2',
                    connection: 'redis',
                    queue: 'emails',
                    name: 'SendInvoiceEmail',
                    failedAt: '2026-06-27T09:14:00Z',
                },
                {
                    id: 'c3d4',
                    connection: 'redis',
                    queue: 'exports',
                    name: 'GenerateReport',
                    failedAt: '2026-06-27T08:02:00Z',
                },
            ],
            queues: [
                { name: 'default', connection: null, size: 12, wait: 3 },
                { name: 'emails', connection: null, size: 4, wait: 1 },
                { name: 'exports', connection: null, size: 0, wait: 0 },
            ],
        },
    },
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
        match: /\/webhooks\/logs/,
        data: {
            data: [
                {
                    id: 1,
                    event: 'order.created',
                    statusCode: 200,
                    success: true,
                    attempt: 1,
                    createdAt: '2026-07-10T10:04:00Z',
                    payload: { order_id: 981, total: '129.90' },
                    response: '{"ok":true}',
                    endpointName: 'Billing hook',
                    endpointUrl: 'https://api.acme.dev/hooks/billing',
                },
                {
                    id: 2,
                    event: 'invoice.paid',
                    statusCode: 500,
                    success: false,
                    attempt: 3,
                    createdAt: '2026-07-10T09:58:00Z',
                    payload: { invoice_id: 'inv_204' },
                    response: 'Server error',
                    endpointName: 'ERP sync',
                    endpointUrl: 'https://erp.acme.dev/webhooks',
                },
                {
                    id: 3,
                    event: 'order.shipped',
                    statusCode: 200,
                    success: true,
                    attempt: 1,
                    createdAt: '2026-07-10T09:41:00Z',
                    payload: { order_id: 975 },
                    response: '{"ok":true}',
                    endpointName: 'Billing hook',
                    endpointUrl: 'https://api.acme.dev/hooks/billing',
                },
            ],
            pagination: { current_page: 1, last_page: 4, total: 58 },
        },
    },
    {
        match: /\/api-logs/,
        data: {
            data: [
                {
                    id: 11,
                    method: 'GET',
                    path: '/api/v1/orders',
                    status: 200,
                    durationMs: 24,
                    tokenName: 'Zapier',
                    ip: '34.12.0.8',
                    requestBody: null,
                    responseBody: null,
                    createdAt: '2026-07-10T10:05:00Z',
                },
                {
                    id: 12,
                    method: 'POST',
                    path: '/api/v1/orders',
                    status: 422,
                    durationMs: 41,
                    tokenName: 'CI bot',
                    ip: '10.0.0.9',
                    requestBody: { sku: 'A-1', qty: 2 },
                    responseBody: '{"message":"The sku is invalid."}',
                    createdAt: '2026-07-10T10:02:00Z',
                },
                {
                    id: 13,
                    method: 'DELETE',
                    path: '/api/v1/orders/975',
                    status: 204,
                    durationMs: 18,
                    tokenName: 'Zapier',
                    ip: '34.12.0.8',
                    requestBody: null,
                    responseBody: null,
                    createdAt: '2026-07-10T09:47:00Z',
                },
            ],
            pagination: { current_page: 1, last_page: 2, total: 23 },
        },
    },
    {
        match: /\/permissions\/features$/,
        data: [
            {
                name: 'users',
                label: 'Users',
                abilities: [
                    { key: 'viewAny', label: 'List', permission: 'users.viewAny' },
                    { key: 'view', label: 'View', permission: 'users.view' },
                    { key: 'create', label: 'Create', permission: 'users.create' },
                    { key: 'update', label: 'Update', permission: 'users.update' },
                    { key: 'delete', label: 'Delete', permission: 'users.delete' },
                ],
            },
            {
                name: 'orders',
                label: 'Orders',
                abilities: [
                    { key: 'viewAny', label: 'List', permission: 'orders.viewAny' },
                    { key: 'view', label: 'View', permission: 'orders.view' },
                    { key: 'update', label: 'Update', permission: 'orders.update' },
                    { key: 'refund', label: 'Refund', permission: 'orders.refund' },
                ],
            },
            {
                name: 'reports',
                label: 'Reports',
                abilities: [
                    { key: 'viewAny', label: 'List', permission: 'reports.viewAny' },
                    { key: 'create', label: 'Create', permission: 'reports.create' },
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
                usersCount: 3,
                permissions: [
                    'users.viewAny',
                    'users.view',
                    'users.create',
                    'users.update',
                    'users.delete',
                    'orders.viewAny',
                    'orders.view',
                    'orders.update',
                    'orders.refund',
                    'reports.viewAny',
                    'reports.create',
                ],
            },
            {
                id: 2,
                name: 'editor',
                usersCount: 8,
                permissions: [
                    'users.viewAny',
                    'users.view',
                    'orders.viewAny',
                    'orders.view',
                ],
            },
            {
                id: 3,
                name: 'viewer',
                usersCount: 21,
                permissions: ['users.viewAny', 'orders.viewAny'],
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
        match: /\/announcements$/,
        data: {
            unread: 2,
            announcements: [
                {
                    id: 1,
                    title: 'Dark mode is here 🌙',
                    body: 'Toggle it from the header — your choice is remembered across devices.',
                    level: 'feature',
                    publishedAt: '2026-06-26T10:00:00Z',
                    isNew: true,
                },
                {
                    id: 2,
                    title: 'Faster global search',
                    body: 'Spotlight now returns results roughly 3× quicker.',
                    level: 'feature',
                    publishedAt: '2026-06-22T10:00:00Z',
                    isNew: true,
                },
                {
                    id: 3,
                    title: 'Fixed CSV export edge case',
                    body: 'Quoted fields with embedded newlines now round-trip correctly.',
                    level: 'fix',
                    publishedAt: '2026-06-18T10:00:00Z',
                    isNew: false,
                },
            ],
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
