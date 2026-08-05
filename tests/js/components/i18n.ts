import { createI18n } from 'vue-i18n';

/**
 * Shared vue-i18n instance for component tests, mirroring the `kinetix.*` keys
 * the Vue components consume (English values from resources/lang/en/kinetix.php).
 * Use via `mount(Component, { global: { plugins: [i18n] } })`.
 */
export const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                // pickers & preview
                pick_date: 'Pick a date',
                datetime_placeholder: 'MM/DD/YYYY hh:mm',
                download: 'Download',
                preview_unavailable:
                    'Preview not available for this file type.',
                export: 'Export',
                import: 'Import',
                // billing UI
                billing_current_plan: 'Current plan',
                billing_upgrade: 'Upgrade',
                billing_switch_plan: 'Switch plan',
                billing_per_month: '/ month',
                billing_per_year: '/ year',
                billing_monthly: 'Monthly',
                billing_yearly: 'Yearly',
                billing_subscription_status: 'Subscription status',
                billing_status: 'Status',
                billing_expires_on: 'Expires on',
                billing_no_subscription: 'No active subscription found.',
                billing_cancel_subscription: 'Cancel subscription',
                billing_resume_subscription: 'Resume subscription',
                billing_trial: 'Trial',
                billing_trial_ends_on: 'Trial ends on',
                billing_trial_active: 'You are on a free trial until {date}.',
                billing_trial_badge: '{days}-day trial',
                billing_invoices: 'Invoices',
                billing_date: 'Date',
                billing_total: 'Total',
                billing_no_invoices: 'No invoices yet.',
                billing_payment_method: 'Payment method',
                billing_payment_method_desc:
                    'Choose an existing card or add a new one.',
                billing_add_card: 'Add new card',
                billing_add_payment_method: 'Add payment method',
                billing_default: 'Default',
                billing_payment_required:
                    'Please add a payment method before subscribing to a paid plan.',
                // roles & permissions
                save: 'Save',
                select_all: 'Select all',
                search_permissions: 'Search permissions…',
                role_name: 'Role name',
                roles_title: 'Roles & Permissions',
                create_role: 'New role',
                no_roles: 'No roles yet.',
                confirm_delete: 'Delete?',
                edit: 'Edit',
                delete: 'Delete',
                cancel: 'Cancel',
                saved: 'Saved.',
                save_failed: 'Could not save.',
                deleted: 'Deleted.',
                delete_failed: 'Could not delete.',
                // roles: global toggle + delete-in-use warning
                role_global_create_label: 'Global role (all teams)',
                role_global_create_hint:
                    'Visible in every team. Only super-admins can modify it later.',
                role_delete_members_warning:
                    'The {role} role is still assigned to {count} member(s). Deleting is blocked until they are reassigned.',
                role_matrix_hint: 'Toggle permissions per module.',
                role_matrix_module: 'Module',
                // membership
                members_title: 'Members',
                member_email: 'Email',
                member_role: 'Role',
                member_provision: 'Add member',
                member_resend: 'Resend',
                member_revoke: 'Remove',
                member_search: 'Search members…',
                member_revoke_confirm:
                    'Remove {email}? Their role will be removed and the member marked as revoked.',
                member_status_pending: 'Pending',
                member_status_active: 'Active',
                member_status_revoked: 'Revoked',
                member_provisioned: 'Invitation sent.',
                member_provision_failed: 'Could not add member.',
                member_role_updated: 'Role updated.',
                member_revoked: 'Member removed.',
                no_members: 'No members yet.',
                show_more: 'Show more',
            },
        },
    },
});
