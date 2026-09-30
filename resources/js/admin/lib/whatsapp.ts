import type { Tone } from '../types';
import { humanize } from './format';

export const qualityTone = (q: string | null): Tone => (q === 'GREEN' ? 'good' : q === 'YELLOW' ? 'warn' : q === 'RED' ? 'bad' : 'grey');

export const qualityLabel = (q: string | null): string => (q === 'GREEN' ? 'High' : q === 'YELLOW' ? 'Medium' : q === 'RED' ? 'Low' : 'Not rated');

export const tierLabel = (t: string | null): string => {
    if (!t) return '—';
    if (t === 'TIER_UNLIMITED') return 'Unlimited';
    if (t === 'TIER_NOT_SET') return 'Not set';
    const m = /TIER_(\d+)(K?)/.exec(t);
    return m ? `${m[1]}${m[2]} / 24h` : humanize(t);
};
