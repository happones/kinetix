import { ref, type Ref } from 'vue';
import { xsrfToken } from '@/composables/useKinetixHttp';

/**
 * A dependency-free Laravel Precognition client for Kinetix forms.
 *
 * Precognition lets the server validate a form *as the user edits it*, reusing
 * the exact FormRequest rules — no duplicated client rules, no full submit. The
 * client sends the request with a `Precognition` header and a
 * `Precognition-Validate-Only` list; Laravel's `HandlePrecognitiveRequests`
 * middleware runs only those fields' rules and returns `204` (valid) or `422`
 * (with the error bag) without ever reaching the controller.
 *
 * It's built directly on `fetch` (not `kinetixFetch`, which throws on 422 and
 * would discard the error bag) but reuses `xsrfToken()`, so it adds no runtime
 * dependency to consuming apps. It handles only the slice a schema-driven form
 * needs: debounced per-field validation and a reactive error bag. Enable it per
 * form with `Form::precognitive()`.
 */
export interface KinetixPrecognitionOptions {
    /** Endpoint to validate against (usually the form's submit URL). */
    url: string;
    /** HTTP verb the submit uses; validation mirrors it. */
    method?: string;
    /** Returns the current, full form payload at call time. */
    getData: () => Record<string, any>;
    /** Debounce window per field, in ms. */
    timeout?: number;
}

export interface KinetixPrecognition {
    errors: Ref<Record<string, string>>;
    validating: Ref<boolean>;
    /** Validate a single field (debounced). */
    validate: (name: string) => void;
    /** Validate every field now (used before an optimistic submit). */
    validateAll: () => Promise<boolean>;
    /** Drop the error for one field (e.g. once the user starts editing it). */
    clearError: (name: string) => void;
}

export function useKinetixPrecognition(
    options: KinetixPrecognitionOptions,
): KinetixPrecognition {
    const method = (options.method ?? 'post').toUpperCase();
    const timeout = options.timeout ?? 300;

    const errors = ref<Record<string, string>>({});
    const validating = ref(false);
    const timers = new Map<string, ReturnType<typeof setTimeout>>();

    const clearError = (name: string): void => {
        if (name in errors.value) {
            const next = { ...errors.value };
            delete next[name];
            errors.value = next;
        }
    };

    /**
     * Fire one precognitive request. `only` scopes which fields the server
     * validates; their errors replace the current ones for those fields while
     * leaving untouched fields' errors intact.
     */
    async function request(only: string[]): Promise<boolean> {
        // Real PUT/PATCH/DELETE are method-spoofed over POST so the route's CSRF
        // + form-method handling matches a normal Inertia submit.
        const spoof = method !== 'GET' && method !== 'POST';
        const payload = { ...options.getData() };

        if (spoof) {
            payload._method = method;
        }

        const headers: Record<string, string> = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            Precognition: 'true',
        };

        if (only.length > 0) {
            headers['Precognition-Validate-Only'] = only.join(',');
        }

        const token = xsrfToken();
        if (token) {
            headers['X-XSRF-TOKEN'] = token;
        }

        validating.value = true;

        try {
            const response = await fetch(options.url, {
                method: spoof ? 'POST' : method,
                headers,
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });

            if (response.status === 422) {
                const body = await response.json();
                const bag: Record<string, string[] | string> =
                    body?.errors ?? {};
                const next = { ...errors.value };

                // Clear the validated fields, then apply any fresh errors.
                for (const name of only) {
                    delete next[name];
                }

                for (const [name, messages] of Object.entries(bag)) {
                    // Only surface errors for fields we asked about, so a
                    // per-field check never floods the form with siblings'.
                    if (only.length === 0 || only.includes(name)) {
                        next[name] = Array.isArray(messages)
                            ? messages[0]
                            : messages;
                    }
                }

                errors.value = next;

                return false;
            }

            // 2xx / 204 → the validated fields are clean.
            for (const name of only) {
                clearError(name);
            }

            return true;
        } catch {
            // Network failures shouldn't block editing; server-side validation
            // on submit remains the source of truth.
            return true;
        } finally {
            validating.value = false;
        }
    }

    const validate = (name: string): void => {
        const existing = timers.get(name);
        if (existing) {
            clearTimeout(existing);
        }

        timers.set(
            name,
            setTimeout(() => {
                timers.delete(name);
                void request([name]);
            }, timeout),
        );
    };

    const validateAll = (): Promise<boolean> => request([]);

    return { errors, validating, validate, validateAll, clearError };
}
