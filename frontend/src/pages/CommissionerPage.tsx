import { useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import {
  useCommissionerSettings, useUpdateCommissionerSettings,
  useLeagueMembers, useReviewTrade, useForceAdvance,
  useSubmissionStatus, useTrades,
  useInvites, useCreateInvite, useCancelInvite,
} from '@/hooks/useApi';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { motion } from 'framer-motion';
import { Shield, Users, Settings, ArrowLeftRight, Send, Copy, Clock } from 'lucide-react';
import { toast } from 'sonner';

interface Member {
  id: number;
  username: string;
  team_name: string;
  team_emoji: string;
  is_human: boolean;
  wins: number;
  losses: number;
  ties: number;
}

interface CommSettings {
  trade_review_mode: string;
  trade_deadline_week: number;
  salary_cap_enabled: boolean;
  max_roster_size: number;
  injured_reserve_slots: number;
  allow_ai_trades: boolean;
  force_advance_enabled: boolean;
  submission_deadline_hours: number;
  [key: string]: unknown;
}

interface Submission {
  team_name: string;
  team_emoji: string;
  coach_name: string;
  is_human: boolean;
  submitted: boolean;
  submitted_at: string | null;
}

interface Invite {
  id: number;
  code: string;
  team_name: string | null;
  team_emoji: string | null;
  created_by: string;
  expires_at: string;
  claimed: boolean;
  claimed_by: string | null;
}

function SettingsTab() {
  const { data, isLoading } = useCommissionerSettings();
  const updateMut = useUpdateCommissionerSettings();
  const settings = (data as CommSettings | undefined);

  const [form, setForm] = useState<Record<string, unknown>>({});

  // Populate form when settings load
  const effectiveForm = { ...settings, ...form };

  function handleSave() {
    updateMut.mutate(form, {
      onSuccess: () => {
        toast.success('Settings updated');
        setForm({});
      },
      onError: (err) => toast.error(err.message),
    });
  }

  if (isLoading) {
    return <div className="flex h-32 items-center justify-center text-sm text-[var(--text-secondary)]">Loading settings...</div>;
  }

  return (
    <div className="space-y-4">
      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 font-display text-base">
            <Settings className="h-4 w-4 text-[var(--accent-blue)]" />
            League Settings
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* Trade Review Mode */}
          <div className="grid grid-cols-2 items-center gap-4">
            <label className="text-sm text-[var(--text-secondary)]">Trade Review Mode</label>
            <Select
              value={String(effectiveForm.trade_review_mode ?? 'commissioner')}
              onValueChange={(v) => setForm((f) => ({ ...f, trade_review_mode: v }))}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="commissioner">Commissioner Review</SelectItem>
                <SelectItem value="vote">League Vote</SelectItem>
                <SelectItem value="none">No Review</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Trade Deadline */}
          <div className="grid grid-cols-2 items-center gap-4">
            <label className="text-sm text-[var(--text-secondary)]">Trade Deadline (Week)</label>
            <input
              type="number"
              min={1}
              max={18}
              value={Number(effectiveForm.trade_deadline_week ?? 12)}
              onChange={(e) => setForm((f) => ({ ...f, trade_deadline_week: Number(e.target.value) }))}
              className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] px-3 py-1.5 text-sm text-[var(--text-primary)]"
            />
          </div>

          {/* Max Roster Size */}
          <div className="grid grid-cols-2 items-center gap-4">
            <label className="text-sm text-[var(--text-secondary)]">Max Roster Size</label>
            <input
              type="number"
              min={40}
              max={75}
              value={Number(effectiveForm.max_roster_size ?? 53)}
              onChange={(e) => setForm((f) => ({ ...f, max_roster_size: Number(e.target.value) }))}
              className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] px-3 py-1.5 text-sm text-[var(--text-primary)]"
            />
          </div>

          {/* Submission Deadline Hours */}
          <div className="grid grid-cols-2 items-center gap-4">
            <label className="text-sm text-[var(--text-secondary)]">Submission Deadline (hours)</label>
            <input
              type="number"
              min={1}
              max={168}
              value={Number(effectiveForm.submission_deadline_hours ?? 24)}
              onChange={(e) => setForm((f) => ({ ...f, submission_deadline_hours: Number(e.target.value) }))}
              className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] px-3 py-1.5 text-sm text-[var(--text-primary)]"
            />
          </div>

          {/* Salary Cap */}
          <div className="grid grid-cols-2 items-center gap-4">
            <label className="text-sm text-[var(--text-secondary)]">Salary Cap Enabled</label>
            <button
              onClick={() => setForm((f) => ({ ...f, salary_cap_enabled: !effectiveForm.salary_cap_enabled }))}
              className={`h-8 w-14 rounded-full transition-colors ${
                effectiveForm.salary_cap_enabled ? 'bg-[var(--accent-blue)]' : 'bg-[var(--bg-elevated)]'
              }`}
            >
              <div className={`h-6 w-6 rounded-full bg-white transition-transform ${
                effectiveForm.salary_cap_enabled ? 'translate-x-7' : 'translate-x-1'
              }`} />
            </button>
          </div>

          {/* Allow AI Trades */}
          <div className="grid grid-cols-2 items-center gap-4">
            <label className="text-sm text-[var(--text-secondary)]">Allow AI Trades</label>
            <button
              onClick={() => setForm((f) => ({ ...f, allow_ai_trades: !effectiveForm.allow_ai_trades }))}
              className={`h-8 w-14 rounded-full transition-colors ${
                effectiveForm.allow_ai_trades ? 'bg-[var(--accent-blue)]' : 'bg-[var(--bg-elevated)]'
              }`}
            >
              <div className={`h-6 w-6 rounded-full bg-white transition-transform ${
                effectiveForm.allow_ai_trades ? 'translate-x-7' : 'translate-x-1'
              }`} />
            </button>
          </div>

          <Separator className="my-2" />
          <div className="flex justify-end">
            <Button onClick={handleSave} disabled={updateMut.isPending || Object.keys(form).length === 0}>
              {updateMut.isPending ? 'Saving...' : 'Save Settings'}
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

function MembersTab() {
  const { data, isLoading } = useLeagueMembers();
  const members = (data as Member[] | undefined) ?? [];

  if (isLoading) {
    return <div className="flex h-32 items-center justify-center text-sm text-[var(--text-secondary)]">Loading members...</div>;
  }

  return (
    <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 font-display text-base">
          <Users className="h-4 w-4 text-[var(--accent-blue)]" />
          League Members
        </CardTitle>
      </CardHeader>
      <CardContent>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Team</TableHead>
              <TableHead>Coach</TableHead>
              <TableHead>Type</TableHead>
              <TableHead className="text-right">Record</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {members.map((m) => (
              <TableRow key={m.id}>
                <TableCell>
                  <div className="flex items-center gap-2">
                    <span className="text-lg">{m.team_emoji}</span>
                    <span className="text-sm font-medium">{m.team_name}</span>
                  </div>
                </TableCell>
                <TableCell className="text-sm">{m.username}</TableCell>
                <TableCell>
                  <Badge
                    variant="outline"
                    className={`text-[10px] ${
                      m.is_human
                        ? 'bg-green-500/10 text-green-400 border-green-500/30'
                        : 'bg-gray-500/10 text-gray-400 border-gray-500/30'
                    }`}
                  >
                    {m.is_human ? 'Human' : 'AI'}
                  </Badge>
                </TableCell>
                <TableCell className="text-right text-sm">
                  {m.wins}-{m.losses}{m.ties > 0 ? `-${m.ties}` : ''}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  );
}

function TradeReviewTab() {
  const { data: trades } = useTrades();
  const reviewMut = useReviewTrade();

  const pendingTrades = (trades ?? []).filter(
    (t) => t.status === 'pending_review' || t.status === 'pending',
  );

  function handleReview(id: number, action: string) {
    reviewMut.mutate(
      { id, action },
      {
        onSuccess: () => toast.success(`Trade ${action}d`),
        onError: (err) => toast.error(err.message),
      },
    );
  }

  return (
    <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 font-display text-base">
          <ArrowLeftRight className="h-4 w-4 text-[var(--accent-blue)]" />
          Pending Trade Reviews
        </CardTitle>
      </CardHeader>
      <CardContent>
        {pendingTrades.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-8">
            <ArrowLeftRight className="h-8 w-8 text-[var(--text-muted)] mb-2" />
            <p className="text-sm text-[var(--text-secondary)]">No trades pending review</p>
          </div>
        ) : (
          <div className="space-y-3">
            {pendingTrades.map((t) => (
              <div key={t.id} className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-4">
                <div className="flex items-center justify-between mb-3">
                  <div className="flex items-center gap-2">
                    <span className="text-lg">{t.proposing_team?.logo_emoji}</span>
                    <span className="text-sm font-semibold">{t.proposing_team?.city} {t.proposing_team?.name}</span>
                    <ArrowLeftRight className="h-3.5 w-3.5 text-[var(--text-muted)]" />
                    <span className="text-lg">{t.receiving_team?.logo_emoji}</span>
                    <span className="text-sm font-semibold">{t.receiving_team?.city} {t.receiving_team?.name}</span>
                  </div>
                  <span className="text-[10px] text-[var(--text-muted)]">
                    {new Date(t.created_at).toLocaleDateString()}
                  </span>
                </div>
                <div className="grid grid-cols-2 gap-3 mb-3">
                  <div>
                    <p className="text-xs text-[var(--text-muted)] mb-1">Sends</p>
                    <div className="text-sm text-[var(--text-secondary)]">
                      {(t.offered_players ?? []).map((p) => (
                        <p key={p.id}>{p.first_name} {p.last_name} ({p.position})</p>
                      ))}
                      {(t.offered_players ?? []).length === 0 && <p>--</p>}
                    </div>
                  </div>
                  <div>
                    <p className="text-xs text-[var(--text-muted)] mb-1">Receives</p>
                    <div className="text-sm text-[var(--text-secondary)]">
                      {(t.requested_players ?? []).map((p) => (
                        <p key={p.id}>{p.first_name} {p.last_name} ({p.position})</p>
                      ))}
                      {(t.requested_players ?? []).length === 0 && <p>--</p>}
                    </div>
                  </div>
                </div>
                <div className="flex justify-end gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    className="text-red-400 border-red-500/30 hover:bg-red-500/10"
                    onClick={() => handleReview(t.id, 'veto')}
                    disabled={reviewMut.isPending}
                  >
                    Veto
                  </Button>
                  <Button
                    size="sm"
                    className="bg-green-600 hover:bg-green-700"
                    onClick={() => handleReview(t.id, 'approve')}
                    disabled={reviewMut.isPending}
                  >
                    Approve
                  </Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function SubmissionsTab() {
  const { data, isLoading } = useSubmissionStatus();
  const forceAdvanceMut = useForceAdvance();
  const submissions = (data as Submission[] | undefined) ?? [];
  const submitted = submissions.filter((s) => s.submitted).length;
  const total = submissions.length;

  function handleForceAdvance() {
    forceAdvanceMut.mutate(undefined, {
      onSuccess: () => toast.success('Week advanced'),
      onError: (err) => toast.error(err.message),
    });
  }

  if (isLoading) {
    return <div className="flex h-32 items-center justify-center text-sm text-[var(--text-secondary)]">Loading submissions...</div>;
  }

  return (
    <div className="space-y-4">
      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardHeader>
          <div className="flex items-center justify-between">
            <CardTitle className="flex items-center gap-2 font-display text-base">
              <Clock className="h-4 w-4 text-[var(--accent-blue)]" />
              Game Plan Submissions ({submitted}/{total})
            </CardTitle>
            <Button
              size="sm"
              variant="outline"
              onClick={handleForceAdvance}
              disabled={forceAdvanceMut.isPending}
              className="text-yellow-400 border-yellow-500/30 hover:bg-yellow-500/10"
            >
              {forceAdvanceMut.isPending ? 'Advancing...' : 'Force Advance'}
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Team</TableHead>
                <TableHead>Coach</TableHead>
                <TableHead>Type</TableHead>
                <TableHead className="text-right">Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {submissions.map((s, i) => (
                <TableRow key={i}>
                  <TableCell>
                    <div className="flex items-center gap-2">
                      <span className="text-lg">{s.team_emoji}</span>
                      <span className="text-sm font-medium">{s.team_name}</span>
                    </div>
                  </TableCell>
                  <TableCell className="text-sm">{s.coach_name}</TableCell>
                  <TableCell>
                    <Badge
                      variant="outline"
                      className={`text-[10px] ${
                        s.is_human
                          ? 'bg-green-500/10 text-green-400 border-green-500/30'
                          : 'bg-gray-500/10 text-gray-400 border-gray-500/30'
                      }`}
                    >
                      {s.is_human ? 'Human' : 'AI'}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right">
                    <Badge
                      variant="outline"
                      className={`text-[10px] ${
                        s.submitted
                          ? 'bg-green-500/10 text-green-400 border-green-500/30'
                          : 'bg-yellow-500/10 text-yellow-400 border-yellow-500/30'
                      }`}
                    >
                      {s.submitted ? 'Submitted' : 'Pending'}
                    </Badge>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}

function InvitesSection() {
  const { data: invites, isLoading } = useInvites();
  const createMut = useCreateInvite();
  const cancelMut = useCancelInvite();
  const [expiresHours, setExpiresHours] = useState('48');

  const inviteList = (invites as Invite[] | undefined) ?? [];

  function handleCreate() {
    createMut.mutate(
      { expires_hours: Number(expiresHours) },
      {
        onSuccess: () => toast.success('Invite created'),
        onError: (err) => toast.error(err.message),
      },
    );
  }

  function handleCopy(code: string) {
    navigator.clipboard.writeText(code);
    toast.success('Invite code copied!');
  }

  function handleCancel(id: number) {
    cancelMut.mutate(id, {
      onSuccess: () => toast.success('Invite cancelled'),
      onError: (err) => toast.error(err.message),
    });
  }

  return (
    <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 font-display text-base">
          <Send className="h-4 w-4 text-[var(--accent-blue)]" />
          Invite Management
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Create Invite */}
        <div className="flex items-center gap-3">
          <Select value={expiresHours} onValueChange={(v) => v && setExpiresHours(v)}>
            <SelectTrigger className="w-40">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="24">24 hours</SelectItem>
              <SelectItem value="48">48 hours</SelectItem>
              <SelectItem value="168">7 days</SelectItem>
              <SelectItem value="720">30 days</SelectItem>
            </SelectContent>
          </Select>
          <Button onClick={handleCreate} disabled={createMut.isPending}>
            <Send className="mr-1 h-4 w-4" />
            {createMut.isPending ? 'Creating...' : 'Create Invite'}
          </Button>
        </div>

        <Separator />

        {/* Existing Invites */}
        {isLoading ? (
          <p className="text-sm text-[var(--text-secondary)]">Loading invites...</p>
        ) : inviteList.length === 0 ? (
          <p className="text-sm text-[var(--text-muted)]">No active invites</p>
        ) : (
          <div className="space-y-2">
            {inviteList.map((inv) => (
              <div
                key={inv.id}
                className="flex items-center justify-between rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-3"
              >
                <div className="flex items-center gap-3">
                  <code className="rounded bg-[var(--bg-elevated)] px-2 py-1 text-xs font-mono text-[var(--accent-blue)]">
                    {inv.code}
                  </code>
                  {inv.team_name && (
                    <span className="text-xs text-[var(--text-secondary)]">
                      {inv.team_emoji} {inv.team_name}
                    </span>
                  )}
                  <span className="text-[10px] text-[var(--text-muted)]">
                    Expires {new Date(inv.expires_at).toLocaleDateString()}
                  </span>
                  {inv.claimed && (
                    <Badge variant="outline" className="text-[9px] bg-green-500/10 text-green-400 border-green-500/30">
                      Claimed by {inv.claimed_by}
                    </Badge>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => handleCopy(inv.code)}
                    className="h-7"
                  >
                    <Copy className="h-3 w-3" />
                  </Button>
                  {!inv.claimed && (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => handleCancel(inv.id)}
                      disabled={cancelMut.isPending}
                      className="h-7 text-red-400 border-red-500/30 hover:bg-red-500/10"
                    >
                      Cancel
                    </Button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

export default function CommissionerPage() {
  const user = useAuthStore((s) => s.user);

  if (!user?.is_admin) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="text-center">
          <Shield className="mx-auto h-10 w-10 text-red-400 mb-2" />
          <p className="text-sm text-[var(--text-secondary)]">Commissioner access only</p>
          <p className="text-xs text-[var(--text-muted)] mt-1">You do not have permission to view this page.</p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
      >
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--accent-blue)]/10">
            <Shield className="h-5 w-5 text-[var(--accent-blue)]" />
          </div>
          <div>
            <h1 className="font-display text-2xl">Commissioner Tools</h1>
            <p className="text-sm text-[var(--text-secondary)]">
              Manage your league settings, members, and trades
            </p>
          </div>
        </div>
      </motion.div>

      {/* Tabs */}
      <Tabs defaultValue="settings">
        <TabsList>
          <TabsTrigger value="settings">
            <Settings className="mr-1 h-3.5 w-3.5" /> Settings
          </TabsTrigger>
          <TabsTrigger value="members">
            <Users className="mr-1 h-3.5 w-3.5" /> Members
          </TabsTrigger>
          <TabsTrigger value="trades">
            <ArrowLeftRight className="mr-1 h-3.5 w-3.5" /> Trade Review
          </TabsTrigger>
          <TabsTrigger value="submissions">
            <Clock className="mr-1 h-3.5 w-3.5" /> Submissions
          </TabsTrigger>
        </TabsList>

        <TabsContent value="settings" className="mt-4 space-y-4">
          <SettingsTab />
          <InvitesSection />
        </TabsContent>

        <TabsContent value="members" className="mt-4">
          <MembersTab />
        </TabsContent>

        <TabsContent value="trades" className="mt-4">
          <TradeReviewTab />
        </TabsContent>

        <TabsContent value="submissions" className="mt-4">
          <SubmissionsTab />
        </TabsContent>
      </Tabs>
    </div>
  );
}
