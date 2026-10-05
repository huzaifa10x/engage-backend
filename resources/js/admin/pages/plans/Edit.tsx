import { Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, Card, Field, Input, PageHeader, Textarea } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';

type Feature = { key: string; label: string; type: 'boolean' | 'limit' | 'metered'; unit: string | null; enabled: boolean; limit: number | null; unlimited: boolean; config: Record<string, unknown> };

type Plan = {
    id: string;
    key: string;
    name: string;
    description: string | null;
    is_public: boolean;
    is_active: boolean;
    sort_order: number;
    version: number | null;
    price_monthly_minor: number | null;
    price_yearly_minor: number | null;
    trial_days: number;
    subscribers: number;
};

type FeatureValue = { enabled: boolean; limit: number | null; unlimited: boolean; config: Record<string, unknown> | null };

type FormState = {
    key: string;
    name: string;
    description: string;
    is_public: boolean;
    is_active: boolean;
    sort_order: number;
    price_monthly: string;
    price_yearly: string;
    trial_days: number;
    apply_to_subscribers: boolean;
    features: Record<string, FeatureValue>;
};

const EMPTY: FeatureValue = { enabled: false, limit: null, unlimited: false, config: null };

const dollars = (minor: number | null) => (minor === null ? '' : (minor / 100).toString());
const cents = (value: string) => (value.trim() === '' ? null : Math.round(Number(value) * 100));

function Toggle({ checked, onChange, label }: { checked: boolean; onChange: (v: boolean) => void; label: string }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            onClick={() => onChange(!checked)}
            className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors ${checked ? 'bg-brand' : 'bg-line'}`}
        >
            <span className={`inline-block size-4 rounded-full bg-white shadow transition-transform ${checked ? 'translate-x-4.5' : 'translate-x-0.5'}`} />
        </button>
    );
}

export default function PlanEdit({ plan, features }: { plan: Plan | null; features: Feature[] }) {
    const [data, setAll] = useState<FormState>({
        key: plan?.key ?? '',
        name: plan?.name ?? '',
        description: plan?.description ?? '',
        is_public: plan?.is_public ?? true,
        is_active: plan?.is_active ?? true,
        sort_order: plan?.sort_order ?? 10,
        price_monthly: dollars(plan ? plan.price_monthly_minor : 0),
        price_yearly: dollars(plan ? plan.price_yearly_minor : 0),
        trial_days: plan?.trial_days ?? 0,
        apply_to_subscribers: true,
        features: Object.fromEntries(
            features.map((f) => [f.key, { enabled: f.enabled, limit: f.limit, unlimited: f.unlimited, config: Object.keys(f.config ?? {}).length ? f.config : null }]),
        ),
    });
    const [errors, setErrors] = useState<Record<string, string | undefined>>({});
    const [processing, setProcessing] = useState(false);
    const form = {
        data,
        processing,
        setData: <K extends keyof FormState>(key: K, value: FormState[K]) => setAll((d) => ({ ...d, [key]: value })),
    };

    const setFeature = (key: string, patch: Partial<FeatureValue>) => form.setData('features', { ...data.features, [key]: { ...(data.features[key] ?? EMPTY), ...patch } });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // Plain JSON for the request (prices converted from dollars to cents).
        const payload = JSON.parse(JSON.stringify({ ...data, price_monthly_minor: cents(data.price_monthly), price_yearly_minor: cents(data.price_yearly) }));
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (errs: Record<string, string>) => setErrors(errs),
            onSuccess: () => setErrors({}),
        };
        if (plan) router.put(`/admin/plans/${plan.id}`, payload, options);
        else router.post('/admin/plans', payload, options);
    };

    const limits = features.filter((f) => f.type !== 'boolean');
    const toggles = features.filter((f) => f.type === 'boolean');
    const priceChanged = plan !== null && (cents(form.data.price_monthly) !== plan.price_monthly_minor || cents(form.data.price_yearly) !== plan.price_yearly_minor);

    return (
        <AdminLayout title={plan ? `Plan · ${plan.name}` : 'New plan'}>
            <PageHeader
                title={plan ? `${plan.name} plan` : 'New plan'}
                description={plan ? `Version ${plan.version ?? '—'} is live · ${plan.subscribers} subscriber(s). Saving publishes a new version.` : 'Create a plan and its first version.'}
                actions={<Link href="/admin/plans" className="inline-flex h-9 items-center rounded-lg border border-line bg-white px-3.5 text-[13.5px] font-semibold text-ink-2 hover:bg-line-2">Back to plans</Link>}
            />
            <form onSubmit={submit} className="grid gap-4">
                <Card title="Details" padded>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" error={errors.name}>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={64} required />
                        </Field>
                        <Field label="Key" error={errors.key} hint={plan ? 'Cannot be changed once created.' : 'Lowercase letters, numbers and underscores, e.g. growth_plus'}>
                            <Input value={form.data.key} onChange={(e) => form.setData('key', e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ''))} disabled={plan !== null} maxLength={32} required />
                        </Field>
                        <div className="sm:col-span-2">
                            <Field label="Description" error={errors.description} hint="Shown to customers under the plan name.">
                                <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} maxLength={500} rows={2} />
                            </Field>
                        </div>
                        <label className="flex items-center justify-between gap-3 rounded-lg border border-line px-3 py-2.5 text-[13.5px]">
                            <span><span className="font-semibold">Active</span><span className="block text-[12.5px] text-muted">Can be bought. Turning this off keeps existing subscribers.</span></span>
                            <Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="Active" />
                        </label>
                        <label className="flex items-center justify-between gap-3 rounded-lg border border-line px-3 py-2.5 text-[13.5px]">
                            <span><span className="font-semibold">Shown on the pricing page</span><span className="block text-[12.5px] text-muted">Hidden plans can only be assigned by your team.</span></span>
                            <Toggle checked={form.data.is_public} onChange={(v) => form.setData('is_public', v)} label="Shown on the pricing page" />
                        </label>
                    </div>
                </Card>

                <Card title="Pricing and billing intervals" padded>
                    <div className="grid gap-4 sm:grid-cols-4">
                        <Field label="Monthly price (USD)" error={errors.price_monthly_minor} hint="0 = free. Empty = custom (sales).">
                            <Input type="number" min={0} step="0.01" value={form.data.price_monthly} onChange={(e) => form.setData('price_monthly', e.target.value)} />
                        </Field>
                        <Field label="Yearly price (USD)" error={errors.price_yearly_minor} hint="Billed once a year. Empty = no yearly option.">
                            <Input type="number" min={0} step="0.01" value={form.data.price_yearly} onChange={(e) => form.setData('price_yearly', e.target.value)} />
                        </Field>
                        <Field label="Trial length (days)" error={errors.trial_days}>
                            <Input type="number" min={0} max={365} value={form.data.trial_days} onChange={(e) => form.setData('trial_days', Number(e.target.value))} />
                        </Field>
                        <Field label="Order on the pricing page" error={errors.sort_order}>
                            <Input type="number" min={0} max={1000} value={form.data.sort_order} onChange={(e) => form.setData('sort_order', Number(e.target.value))} />
                        </Field>
                    </div>
                    {priceChanged && (
                        <p className="mt-3 rounded-lg bg-warn-bg px-3 py-2 text-[13px] text-warn">
                            The new price applies to new purchases and plan changes. Customers already paying keep their current price until they change plan — change it in Stripe if you
                            need to move them.
                        </p>
                    )}
                </Card>

                <Card title="Limits, usage and rate limits" padded>
                    <div className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                        {limits.map((f) => {
                            const v = form.data.features[f.key] ?? EMPTY;

                            return (
                                <div key={f.key} className="flex items-center gap-3 rounded-lg border border-line px-3 py-2">
                                    <Toggle checked={v.enabled} onChange={(enabled) => setFeature(f.key, { enabled })} label={`${f.label} included`} />
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-[13.5px] font-semibold">{f.label}</div>
                                        <div className="text-[12px] text-muted">{f.unit ?? (f.type === 'metered' ? 'per month' : 'maximum')}</div>
                                    </div>
                                    <Input
                                        type="number"
                                        min={0}
                                        className="!w-28"
                                        aria-label={`${f.label} limit`}
                                        disabled={!v.enabled || v.unlimited}
                                        value={v.unlimited || v.limit === null ? '' : v.limit}
                                        placeholder={v.unlimited ? '∞' : '0'}
                                        onChange={(e) => setFeature(f.key, { limit: e.target.value === '' ? null : Number(e.target.value) })}
                                    />
                                    <label className="flex items-center gap-1.5 text-[12.5px] text-muted">
                                        <input type="checkbox" checked={v.unlimited} disabled={!v.enabled} onChange={(e) => setFeature(f.key, { unlimited: e.target.checked })} /> Unlimited
                                    </label>
                                </div>
                            );
                        })}
                    </div>
                </Card>

                <Card title="Feature access" padded>
                    <div className="grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                        {toggles.map((f) => {
                            const v = form.data.features[f.key] ?? EMPTY;
                            const level = typeof v.config?.level === 'string' ? v.config.level : null;

                            return (
                                <div key={f.key} className="flex items-center gap-3 py-1">
                                    <Toggle checked={v.enabled} onChange={(enabled) => setFeature(f.key, { enabled })} label={f.label} />
                                    <span className="min-w-0 flex-1 truncate text-[13.5px]">{f.label}</span>
                                    {level !== null && (
                                        <Input
                                            className="!h-7 !w-28 !text-[12.5px]"
                                            aria-label={`${f.label} level`}
                                            value={level}
                                            disabled={!v.enabled}
                                            onChange={(e) => setFeature(f.key, { config: { ...(v.config ?? {}), level: e.target.value } })}
                                        />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </Card>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    {plan ? (
                        <label className="flex items-center gap-2 text-[13.5px]">
                            <input type="checkbox" checked={form.data.apply_to_subscribers} onChange={(e) => form.setData('apply_to_subscribers', e.target.checked)} />
                            Apply the new limits and features to the {plan.subscribers} existing subscriber(s) now
                        </label>
                    ) : (
                        <span />
                    )}
                    <Button variant="primary" type="submit" disabled={form.processing}>
                        {form.processing ? 'Saving…' : plan ? 'Publish new version' : 'Create plan'}
                    </Button>
                </div>
            </form>
        </AdminLayout>
    );
}
