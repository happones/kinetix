/**
 * The translation key for copy that names the providers on offer, so an app
 * that only turns on WhatsApp never talks about Telegram (and vice versa):
 * `<base>` for WhatsApp and Telegram together, `<base>_<provider>` for one of
 * them alone, and `<base>_any` for any other set (custom providers).
 */
export function providerCopyKey(base: string, providers: string[]): string {
    const keys = [...new Set(providers)].sort();

    if (keys.join(',') === 'telegram,whatsapp') {
        return base;
    }

    if (keys.length === 1 && ['telegram', 'whatsapp'].includes(keys[0])) {
        return `${base}_${keys[0]}`;
    }

    return `${base}_any`;
}
