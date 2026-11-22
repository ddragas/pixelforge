/**
 * Money arrives from the server as an exact decimal string of US dollars
 * ("0.046125000"). It is only ever trimmed for display here, never turned
 * into a number, so the browser cannot round it.
 */
export function usd(amount: string | null | undefined): string {
    if (amount === null || amount === undefined) {
        return '—';
    }

    const negative = amount.startsWith('-');
    const [whole, fraction = ''] = amount.replace('-', '').split('.');

    // At least cents; beyond that only as many digits as carry information.
    const trimmed = fraction.replace(/0+$/, '').padEnd(2, '0');

    return `${negative ? '-' : ''}$${whole}.${trimmed}`;
}
