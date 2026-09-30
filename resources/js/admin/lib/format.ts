import type { Tone } from '../types';

export const money = (minor: number | null | undefined, currency = 'USD'): string =>
    minor === null || minor === undefined
        ? 'Custom'
        : new Intl.NumberFormat('en-US', { style: 'currency', currency, maximumFractionDigits: minor % 100 === 0 ? 0 : 2 }).format(minor / 100);

export const number = (n: number | null | undefined): string => (n === null || n === undefined ? '—' : new Intl.NumberFormat('en-US').format(n));

export const date = (iso: string | null | undefined): string =>
    iso ? new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(iso)) : '—';

export const dateTime = (iso: string | null | undefined): string =>
    iso
        ? new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso))
        : '—';

export const relative = (iso: string | null | undefined): string => {
    if (!iso) return 'Never';
    const diff = (new Date(iso).getTime() - Date.now()) / 1000;
    const units: [Intl.RelativeTimeFormatUnit, number][] = [['day', 86400], ['hour', 3600], ['minute', 60]];
    const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
    for (const [unit, secs] of units) {
        if (Math.abs(diff) >= secs) return rtf.format(Math.round(diff / secs), unit);
    }
    return 'just now';
};

export const statusTone = (status: string | null | undefined): Tone =>
    ({ active: 'good', trialing: 'info', past_due: 'warn', suspended: 'bad', closed: 'grey', canceled: 'grey', expired: 'grey' } as Record<string, Tone>)[status ?? ''] ??
    'grey';

export const humanize = (s: string | null | undefined): string => (s ? s.replace(/[._]/g, ' ').replace(/^\w/, (c) => c.toUpperCase()) : '—');
