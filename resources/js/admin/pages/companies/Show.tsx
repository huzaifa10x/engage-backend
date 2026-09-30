import { Link, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, Card, Field, Input, Modal, PageHeader, Pill, Select, Table, Textarea } from '../../components/ui';
import AdminLayout from '../../layouts/AdminLayout';
import { date, dateTime, humanize, money, number, relative, statusTone } from '../../lib/format';
import type { PlanOption, SharedProps } from '../../types';

type Company = {
    id: string; name: string; slug: string; status: string; timezone: string; currency: string;
    country: string | null; billing_email: string | null; created_at: string; suspended_at: string | null;
};
type Subscription = { plan: string; plan_key: string; version: number; status: string; price_monthly_minor: number | null; trial_ends_at: string | null; provider: string } | null;
type Member = { id: string; status: string; role: string; role_key: string; name: string; email: string; last_login_at: string | null; disabled: boolean };
type Value = { enabled: boolean; limit: number | null; config: Record<string, unknown> };
type Feature = {
    key: string; label: string; type: 'boolean' | 'limit' | 'metered'; unit: string | null;
    plan: Value;
    override: { enabled: boolean | null; limit: number | null; unlimited: boolean; reason: string | null; expires_at: string | null } | null;
    effective: Value & { type: string };
};
type Props = {
    company: Company;
    subscription: Subscription;
    effectivePlan: { key: string; name: string };
    subscriptionEvents: { type: string; from_status: string | null; to_status: string; occurred_at: string }[];
    members: Member[];
    features: Feature[];
    audit: { id: string; action: string; actor_type: string; entity_type: string | null; created_at: string }[];
    planVersions: PlanOption[];
};

const describe = (f: Feature, v: Value): string => {
    if (!v.enabled) return 'Not included';
    const level = typeof v.config.level === 'string' ? humanize(v.config.level) : null;
    if (f.type === 'boolean') return level ?? 'Included';
    if (v.limit === null) return 'Unlimited';
    return `${number(v.limit)}${f.unit ? ` ${f.unit}` : ''}`;
};

export default function CompanyShow(props: Props) {
    const { company, subscription, effectivePlan, members, features, audit, subscriptionEvents, planVersions } = props;
    const abilities = usePage<SharedProps>().props.auth.admin?.abilities ?? [];
    const canManage = abilities.includes('companies.manage');
    const canImpersonate = abilities.includes('impersonate');

    const [modal, setModal] = useState<null | 'plan' | 'trial' | 'status' | 'impersonate' | { feature: Feature }>(null);
    const close = () => setModal(null);
    const suspended = company.status === 'suspended';
    const featureModal = modal !== null && typeof modal === 'object' ? modal.feature : null;

    return (
        <AdminLayout title={company.name}>
            <div className="mb-2 text-[13px]">
                <Link href="/admin/companies" className="font-semibold text-muted hover:text-ink">Companies</Link>
            </div>
            <PageHeader
                title={company.name}
                description={
                    <span className="inline-flex flex-wrap items-center gap-2">
                        <Pill tone={statusTone(company.status)} dot>{humanize(company.status)}</Pill>
                        <span>{effectivePlan.name} plan</span>
                        <span className="text-faint">/</span>
                        <span>Joined {date(company.created_at)}</span>
                    </span>
                }
                actions={
                    <>
                        {canImpersonate && (
                            <Button onClick={() => setModal('impersonate')} disabled={suspended || members.length === 0}>
                                Log in as a member
                            </Button>
                        )}
                        {canManage && (
                            <>
                                <Button onClick={() => setModal('plan')}>Change plan</Button>
                                {subscription?.status === 'trialing' && <Button onClick={() => setModal('trial')}>Extend trial</Button>}
                                <Button variant={suspended ? 'primary' : 'danger'} onClick={() => setModal('status')}>
                                    {suspended ? 'Reactivate' : 'Suspend'}
                                </Button>
                            </>
                        )}
                    </>
                }
            />

            {suspended && (
                <div className="mb-4 rounded-card border border-bad/20 bg-bad-bg px-4 py-3 text-[13.5px] text-bad">
                    Suspended {company.suspended_at ? relative(company.suspended_at) : ''}. Members cannot sign in to this workspace until it is reactivated.
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                <Card title="Subscription" padded>
                    {subscription ? (
                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-[13.5px]">
                            <dt className="text-muted">Plan</dt>
                            <dd className="font-semibold">{subscription.plan} <span className="font-normal text-muted">v{subscription.version}</span></dd>
                            <dt className="text-muted">Status</dt>
                            <dd><Pill tone={statusTone(subscription.status)}>{humanize(subscription.status)}</Pill></dd>
                            <dt className="text-muted">Price</dt>
                            <dd>{subscription.price_monthly_minor === null ? 'Custom contract' : `${money(subscription.price_monthly_minor)} / month`}</dd>
                            {subscription.trial_ends_at && (
                                <>
                                    <dt className="text-muted">Trial ends</dt>
                                    <dd>{date(subscription.trial_ends_at)} <span className="text-muted">({relative(subscription.trial_ends_at)})</span></dd>
                                </>
                            )}
                            <dt className="text-muted">Billing</dt>
                            <dd>{humanize(subscription.provider)}</dd>
                        </dl>
                    ) : (
                        <p className="text-[13.5px] text-muted">No live subscription. The company runs on the Free plan.</p>
                    )}
                </Card>
                <Card title="Workspace" padded>
                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-[13.5px]">
                        <dt className="text-muted">Slug</dt><dd className="font-mono text-[13px]">{company.slug}</dd>
                        <dt className="text-muted">Billing email</dt><dd className="truncate">{company.billing_email ?? '—'}</dd>
                        <dt className="text-muted">Country</dt><dd>{company.country ?? '—'}</dd>
                        <dt className="text-muted">Timezone</dt><dd>{company.timezone}</dd>
                        <dt className="text-muted">Currency</dt><dd>{company.currency}</dd>
                    </dl>
                </Card>
                <Card title="Plan history">
                    <ul className="divide-y divide-line-2 text-[13px]">
                        {subscriptionEvents.map((e, i) => (
                            <li key={i} className="flex justify-between gap-3 px-4 py-2.5">
                                <span>{humanize(e.type)} <span className="text-muted">to {humanize(e.to_status)}</span></span>
                                <span className="shrink-0 text-muted">{date(e.occurred_at)}</span>
                            </li>
                        ))}
                        {subscriptionEvents.length === 0 && <li className="px-4 py-6 text-center text-muted">No plan changes yet.</li>}
                    </ul>
                </Card>
            </div>

            <Card title={`Members (${members.length})`} className="mt-4">
                <Table head={['Member', 'Role', 'Last sign-in', 'Status']} empty="This workspace has no members.">
                    {members.map((m) => (
                        <tr key={m.id}>
                            <td><div className="font-semibold">{m.name}</div><div className="text-[12.5px] text-muted">{m.email}</div></td>
                            <td>{m.role}</td>
                            <td className="text-muted">{relative(m.last_login_at)}</td>
                            <td>
                                {m.disabled ? <Pill tone="bad">Account disabled</Pill> : <Pill tone={m.status === 'active' ? 'good' : 'warn'}>{humanize(m.status)}</Pill>}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>

            <Card title="Features and limits" aside={<span className="text-[12.5px] text-muted">Overrides replace the plan value for this company only</span>} className="mt-4">
                <Table head={['Feature', `${effectivePlan.name} plan`, 'Override', 'In effect', '']}>
                    {features.map((f) => (
                        <tr key={f.key} className={f.override ? 'bg-brand-50/50' : undefined}>
                            <td className="font-medium">{f.label}</td>
                            <td className="text-muted">{describe(f, f.plan)}</td>
                            <td>
                                {f.override ? (
                                    <span title={f.override.reason ?? undefined}>
                                        <Pill tone="brand">
                                            {f.override.enabled === false ? 'Off' : f.override.unlimited ? 'Unlimited' : f.override.limit !== null ? number(f.override.limit) : 'On'}
                                        </Pill>
                                        {f.override.expires_at && <span className="ml-1.5 text-[12px] text-muted">until {date(f.override.expires_at)}</span>}
                                    </span>
                                ) : (
                                    <span className="text-faint">None</span>
                                )}
                            </td>
                            <td className="font-semibold">{describe(f, f.effective)}</td>
                            <td className="text-right">
                                {canManage && <Button size="sm" variant="quiet" onClick={() => setModal({ feature: f })}>{f.override ? 'Edit' : 'Override'}</Button>}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>

            <Card title="Recent activity" aside={<Link href={`/admin/audit-log?tenant_id=${company.id}`} className="text-[13px] font-semibold text-brand">Full audit log</Link>} className="mt-4">
                <Table head={['Action', 'By', 'When']} empty="No activity recorded yet.">
                    {audit.map((a) => (
                        <tr key={a.id}>
                            <td className="font-medium">{humanize(a.action)}</td>
                            <td className="text-muted">{humanize(a.actor_type)}</td>
                            <td className="text-muted">{dateTime(a.created_at)}</td>
                        </tr>
                    ))}
                </Table>
            </Card>

            <PlanModal open={modal === 'plan'} onClose={close} companyId={company.id} planVersions={planVersions} currentKey={subscription?.plan_key} />
            <TrialModal open={modal === 'trial'} onClose={close} companyId={company.id} />
            <StatusModal open={modal === 'status'} onClose={close} companyId={company.id} suspended={suspended} />
            <ImpersonateModal open={modal === 'impersonate'} onClose={close} companyId={company.id} members={members.filter((m) => m.status === 'active' && !m.disabled)} />
            {featureModal && <OverrideModal key={featureModal.key} feature={featureModal} onClose={close} companyId={company.id} />}
        </AdminLayout>
    );
}

function PlanModal({ open, onClose, companyId, planVersions, currentKey }: { open: boolean; onClose: () => void; companyId: string; planVersions: PlanOption[]; currentKey?: string }) {
    const form = useForm({ plan_version_id: planVersions.find((p) => p.plan_key === currentKey)?.id ?? '', status: 'active', trial_days: 14, reason: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/admin/companies/${companyId}/plan`, { preserveScroll: true, onSuccess: () => { form.reset('reason'); onClose(); } });
    };
    return (
        <Modal open={open} onClose={onClose} title="Change plan" footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" form="plan-form" disabled={form.processing}>Change plan</Button></>}>
            <form id="plan-form" onSubmit={submit} className="space-y-3.5">
                <p className="text-[13px] text-muted">Replaces the current subscription immediately. Billing is manual until Stripe arrives.</p>
                <Field label="Plan" error={form.errors.plan_version_id}>
                    <Select value={form.data.plan_version_id} onChange={(e) => form.setData('plan_version_id', e.target.value)} required>
                        <option value="" disabled>Choose a plan</option>
                        {planVersions.map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}
                    </Select>
                </Field>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Start as" error={form.errors.status}>
                        <Select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            <option value="active">Active</option>
                            <option value="trialing">Trial</option>
                        </Select>
                    </Field>
                    {form.data.status === 'trialing' && (
                        <Field label="Trial length (days)" error={form.errors.trial_days}>
                            <Input type="number" min={1} max={90} value={form.data.trial_days} onChange={(e) => form.setData('trial_days', Number(e.target.value))} />
                        </Field>
                    )}
                </div>
                <Field label="Reason" error={form.errors.reason} hint="Recorded in the audit log.">
                    <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="e.g. Signed annual Growth contract" required />
                </Field>
            </form>
        </Modal>
    );
}

function TrialModal({ open, onClose, companyId }: { open: boolean; onClose: () => void; companyId: string }) {
    const form = useForm({ days: 7, reason: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/admin/companies/${companyId}/trial`, { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } });
    };
    return (
        <Modal open={open} onClose={onClose} title="Extend trial" footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" form="trial-form" disabled={form.processing}>Extend trial</Button></>}>
            <form id="trial-form" onSubmit={submit} className="space-y-3.5">
                <Field label="Extra days" error={form.errors.days}>
                    <Input type="number" min={1} max={60} value={form.data.days} onChange={(e) => form.setData('days', Number(e.target.value))} />
                </Field>
                <Field label="Reason" error={form.errors.reason} hint="Recorded in the audit log.">
                    <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                </Field>
            </form>
        </Modal>
    );
}

function StatusModal({ open, onClose, companyId, suspended }: { open: boolean; onClose: () => void; companyId: string; suspended: boolean }) {
    const form = useForm({ status: suspended ? 'active' : 'suspended', reason: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, status: suspended ? 'active' : 'suspended' }));
        form.patch(`/admin/companies/${companyId}/status`, { preserveScroll: true, onSuccess: () => { form.reset('reason'); onClose(); } });
    };
    return (
        <Modal
            open={open}
            onClose={onClose}
            title={suspended ? 'Reactivate company' : 'Suspend company'}
            footer={<><Button onClick={onClose}>Cancel</Button><Button variant={suspended ? 'primary' : 'danger'} form="status-form" disabled={form.processing}>{suspended ? 'Reactivate' : 'Suspend'}</Button></>}
        >
            <form id="status-form" onSubmit={submit} className="space-y-3.5">
                <p className="text-[13.5px] text-ink-2">
                    {suspended
                        ? 'Members can sign in again immediately.'
                        : 'Every member loses access to this workspace immediately. Inbound WhatsApp messages keep being stored, so nothing is lost.'}
                </p>
                <Field label="Reason" error={form.errors.reason} hint="Recorded in the audit log.">
                    <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                </Field>
            </form>
        </Modal>
    );
}

function ImpersonateModal({ open, onClose, companyId, members }: { open: boolean; onClose: () => void; companyId: string; members: Member[] }) {
    const owner = members.find((m) => m.role_key === 'owner') ?? members[0];
    const form = useForm({ membership_id: owner?.id ?? '', reason: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/admin/companies/${companyId}/impersonate`);
    };
    return (
        <Modal open={open} onClose={onClose} title="Log in as a member" footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" form="imp-form" disabled={form.processing}>Open their workspace</Button></>}>
            <form id="imp-form" onSubmit={submit} className="space-y-3.5">
                <p className="text-[13.5px] text-ink-2">
                    Opens the client app as this person for up to 60 minutes. Everything you do is recorded under your name, and the workspace shows a support banner.
                </p>
                <Field label="Member" error={form.errors.membership_id}>
                    <Select value={form.data.membership_id} onChange={(e) => form.setData('membership_id', e.target.value)}>
                        {members.map((m) => <option key={m.id} value={m.id}>{m.name} ({m.role}), {m.email}</option>)}
                    </Select>
                </Field>
                <Field label="Reason" error={form.errors.reason} hint="Shown to the company in their audit log. At least 10 characters.">
                    <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="e.g. Ticket #1042, templates not syncing" required />
                </Field>
            </form>
        </Modal>
    );
}

function OverrideModal({ feature, onClose, companyId }: { feature: Feature; onClose: () => void; companyId: string }) {
    const quantified = feature.type !== 'boolean';
    const o = feature.override;
    const form = useForm({
        enabled: o?.enabled === null || o?.enabled === undefined ? '' : o.enabled ? '1' : '0',
        unlimited: o?.unlimited ?? false,
        limit: o?.limit ?? feature.plan.limit ?? 0,
        expires_at: o?.expires_at?.slice(0, 10) ?? '',
        reason: o?.reason ?? '',
    });
    const remove = useForm({ reason: '' });
    const url = `/admin/companies/${companyId}/overrides/${feature.key}`;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            enabled: d.enabled === '' ? null : d.enabled === '1',
            unlimited: quantified && d.unlimited,
            limit: quantified && !d.unlimited ? d.limit : null,
            expires_at: d.expires_at || null,
            reason: d.reason,
        }));
        form.put(url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={`${feature.label} override`}
            footer={
                <>
                    {o && (
                        <Button
                            className="mr-auto"
                            variant="quiet"
                            disabled={remove.processing || form.data.reason.length < 5}
                            onClick={() => { remove.transform(() => ({ reason: form.data.reason })); remove.delete(url, { preserveScroll: true, onSuccess: onClose }); }}
                        >
                            Remove override
                        </Button>
                    )}
                    <Button onClick={onClose}>Cancel</Button>
                    <Button variant="primary" form="ovr-form" disabled={form.processing}>Save override</Button>
                </>
            }
        >
            <form id="ovr-form" onSubmit={submit} className="space-y-3.5">
                <p className="text-[13px] text-muted">Plan value: {describe(feature, feature.plan)}.</p>
                <Field label="Availability" error={form.errors.enabled}>
                    <Select value={form.data.enabled} onChange={(e) => form.setData('enabled', e.target.value)}>
                        <option value="">Same as plan</option>
                        <option value="1">Force on</option>
                        <option value="0">Force off</option>
                    </Select>
                </Field>
                {quantified && (
                    <>
                        <label className="flex items-center gap-2 text-[13.5px] font-medium">
                            <input type="checkbox" checked={form.data.unlimited} onChange={(e) => form.setData('unlimited', e.target.checked)} />
                            Unlimited
                        </label>
                        {!form.data.unlimited && (
                            <Field label={`Limit${feature.unit ? ` (${feature.unit})` : ''}`} error={form.errors.limit}>
                                <Input type="number" min={0} value={form.data.limit} onChange={(e) => form.setData('limit', Number(e.target.value))} />
                            </Field>
                        )}
                    </>
                )}
                <Field label="Expires (optional)" error={form.errors.expires_at} hint="Leave empty for a permanent contract term.">
                    <Input type="date" value={form.data.expires_at} onChange={(e) => form.setData('expires_at', e.target.value)} />
                </Field>
                <Field label="Reason" error={form.errors.reason ?? remove.errors.reason} hint="Recorded in the audit log.">
                    <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="e.g. Enterprise contract, 25 seats" required />
                </Field>
            </form>
        </Modal>
    );
}
