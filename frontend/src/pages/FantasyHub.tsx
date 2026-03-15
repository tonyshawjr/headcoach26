import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { fantasyApi, type FantasyLeagueSummary } from '@/api/client';
import { useAuthStore } from '@/stores/authStore';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/empty-state';
import { Trophy, Plus, Users, Zap, Crown, Lock } from 'lucide-react';
import { toast } from 'sonner';

const scoringLabels: Record<string, string> = {
  standard: 'Standard',
  ppr: 'PPR',
  half_ppr: 'Half PPR',
  custom: 'Custom',
};

const statusColors: Record<string, string> = {
  setup: 'bg-yellow-500/20 text-yellow-400',
  active: 'bg-green-500/20 text-green-400',
  playoffs: 'bg-purple-500/20 text-purple-400',
  complete: 'bg-gray-500/20 text-gray-400',
};

export default function FantasyHub() {
  const navigate = useNavigate();
  const league = useAuthStore((s) => s.league);
  const [showCreate, setShowCreate] = useState(false);
  const [showJoin, setShowJoin] = useState(false);

  const currentWeek = league?.current_week ?? 99;
  const canCreateOrJoin = currentWeek <= 2;

  const { data: leagues, isLoading } = useQuery({
    queryKey: ['fantasy-leagues'],
    queryFn: () => fantasyApi.leagues(),
  });

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="font-display text-2xl">Fantasy Football</h1>
          <p className="mt-1 text-sm text-[var(--text-secondary)]">
            {canCreateOrJoin
              ? 'Create or join fantasy leagues and compete against AI managers'
              : 'Fantasy league creation closes after Week 2 of the season'}
          </p>
        </div>
        <div className="flex gap-2">
          {canCreateOrJoin ? (
            <>
              <button
                onClick={() => setShowJoin(true)}
                className="flex items-center gap-2 rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] px-4 py-2 text-sm font-medium text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] transition-colors"
              >
                <Users className="h-4 w-4" />
                Join League
              </button>
              <button
                onClick={() => setShowCreate(true)}
                className="flex items-center gap-2 rounded-lg bg-[var(--accent-blue)] px-4 py-2 text-sm font-medium text-white hover:bg-[var(--accent-blue)]/90 transition-colors"
              >
                <Plus className="h-4 w-4" />
                Create League
              </button>
            </>
          ) : (
            <div className="flex items-center gap-2 rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] px-4 py-2 text-sm text-[var(--text-secondary)]">
              <Lock className="h-4 w-4" />
              Registration closed (Week {currentWeek})
            </div>
          )}
        </div>
      </div>

      {isLoading ? (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {[1, 2, 3].map((i) => (
            <Card key={i} className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardContent className="p-6">
                <div className="animate-pulse space-y-3">
                  <div className="h-5 w-32 rounded bg-[var(--bg-elevated)]" />
                  <div className="h-3 w-full rounded bg-[var(--bg-elevated)]" />
                  <div className="h-3 w-2/3 rounded bg-[var(--bg-elevated)]" />
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      ) : !leagues || leagues.length === 0 ? (
        <EmptyState
          icon={Trophy}
          title="No Fantasy Leagues Yet"
          description="Create your first fantasy football league to start competing!"
        />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {leagues.map((league) => (
            <LeagueCard key={league.id} league={league} onClick={() => navigate(`/fantasy/${league.id}`)} />
          ))}
        </div>
      )}

      {showCreate && <CreateLeagueModal onClose={() => setShowCreate(false)} />}
      {showJoin && <JoinLeagueModal onClose={() => setShowJoin(false)} />}
    </div>
  );
}

function LeagueCard({ league, onClick }: { league: FantasyLeagueSummary; onClick: () => void }) {
  return (
    <Card
      className="border-[var(--border)] bg-[var(--bg-surface)] cursor-pointer hover:border-[var(--accent-blue)]/40 transition-all"
      onClick={onClick}
    >
      <CardHeader className="pb-2">
        <div className="flex items-center justify-between">
          <CardTitle className="text-base">{league.name}</CardTitle>
          <Badge className={statusColors[league.status] || statusColors.setup}>
            {league.status}
          </Badge>
        </div>
      </CardHeader>
      <CardContent>
        <div className="flex items-center gap-4 text-sm text-[var(--text-secondary)]">
          <div className="flex items-center gap-1">
            <Users className="h-3.5 w-3.5" />
            {league.num_teams} teams
          </div>
          <div className="flex items-center gap-1">
            <Zap className="h-3.5 w-3.5" />
            {scoringLabels[league.scoring_type] || league.scoring_type}
          </div>
          <div className="flex items-center gap-1">
            <Crown className="h-3.5 w-3.5" />
            {league.human_count} human{league.human_count !== 1 ? 's' : ''}
          </div>
        </div>
        {league.is_member && (
          <Badge className="mt-3 bg-[var(--accent-blue)]/20 text-[var(--accent-blue)]">
            You're in this league
          </Badge>
        )}
      </CardContent>
    </Card>
  );
}

function CreateLeagueModal({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const [form, setForm] = useState({
    name: '',
    num_teams: 10,
    max_humans: 1,
    scoring_type: 'ppr',
    playoff_start_week: 14,
    num_playoff_teams: 4,
    draft_type: 'snake',
    draft_rounds: 15,
    waiver_type: 'priority',
    faab_budget: 100,
    team_name: '',
    owner_name: '',
  });

  const createMutation = useMutation({
    mutationFn: () => fantasyApi.createLeague(form),
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['fantasy-leagues'] });
      toast.success(`League created! Invite code: ${data.invite_code}`);
      onClose();
      navigate(`/fantasy/${data.id}`);
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const set = (key: string, value: string | number) => setForm((f) => ({ ...f, [key]: value }));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" onClick={onClose}>
      <div
        className="w-full max-w-lg rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-6 shadow-2xl max-h-[85vh] overflow-y-auto"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 className="font-display text-xl mb-4">Create Fantasy League</h2>

        <div className="space-y-4">
          <Field label="League Name">
            <input
              className="input-field"
              placeholder="My Fantasy League"
              value={form.name}
              onChange={(e) => set('name', e.target.value)}
            />
          </Field>

          <Field label="Your Team Name">
            <input
              className="input-field"
              placeholder="Touchdown Titans"
              value={form.team_name}
              onChange={(e) => set('team_name', e.target.value)}
            />
          </Field>

          <Field label="Your Name">
            <input
              className="input-field"
              placeholder="Your display name"
              value={form.owner_name}
              onChange={(e) => set('owner_name', e.target.value)}
            />
          </Field>

          <div className="grid grid-cols-2 gap-4">
            <Field label="Total Teams">
              <select
                className="input-field"
                value={form.num_teams}
                onChange={(e) => set('num_teams', Number(e.target.value))}
              >
                {[4, 6, 8, 10, 12, 14, 16, 18, 20].map((n) => (
                  <option key={n} value={n}>{n} teams</option>
                ))}
              </select>
            </Field>

            <Field label="Human Slots">
              <select
                className="input-field"
                value={form.max_humans}
                onChange={(e) => set('max_humans', Number(e.target.value))}
              >
                {Array.from({ length: form.num_teams }, (_, i) => i + 1).map((n) => (
                  <option key={n} value={n}>{n} human{n > 1 ? 's' : ''}</option>
                ))}
              </select>
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <Field label="Scoring">
              <select className="input-field" value={form.scoring_type} onChange={(e) => set('scoring_type', e.target.value)}>
                <option value="standard">Standard</option>
                <option value="ppr">PPR</option>
                <option value="half_ppr">Half PPR</option>
              </select>
            </Field>

            <Field label="Draft Type">
              <select className="input-field" value={form.draft_type} onChange={(e) => set('draft_type', e.target.value)}>
                <option value="snake">Snake Draft</option>
              </select>
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <Field label="Playoff Start Week">
              <select
                className="input-field"
                value={form.playoff_start_week}
                onChange={(e) => set('playoff_start_week', Number(e.target.value))}
              >
                {[10, 11, 12, 13, 14, 15].map((w) => (
                  <option key={w} value={w}>Week {w}</option>
                ))}
              </select>
            </Field>

            <Field label="Playoff Teams">
              <select
                className="input-field"
                value={form.num_playoff_teams}
                onChange={(e) => set('num_playoff_teams', Number(e.target.value))}
              >
                {[2, 4, 6, 8].filter((n) => n <= form.num_teams).map((n) => (
                  <option key={n} value={n}>{n} teams</option>
                ))}
              </select>
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <Field label="Waivers">
              <select className="input-field" value={form.waiver_type} onChange={(e) => set('waiver_type', e.target.value)}>
                <option value="priority">Waiver Priority</option>
                <option value="faab">FAAB Bidding</option>
              </select>
            </Field>

            <Field label="Draft Rounds">
              <select
                className="input-field"
                value={form.draft_rounds}
                onChange={(e) => set('draft_rounds', Number(e.target.value))}
              >
                {[10, 12, 15, 18, 20].map((n) => (
                  <option key={n} value={n}>{n} rounds</option>
                ))}
              </select>
            </Field>
          </div>

          {form.waiver_type === 'faab' && (
            <Field label="FAAB Budget">
              <input
                type="number"
                className="input-field"
                value={form.faab_budget}
                onChange={(e) => set('faab_budget', Number(e.target.value))}
              />
            </Field>
          )}

          <div className="rounded-lg bg-[var(--bg-elevated)] p-3 text-sm text-[var(--text-secondary)]">
            <p>
              <strong>{form.num_teams - form.max_humans}</strong> AI manager{form.num_teams - form.max_humans !== 1 ? 's' : ''} will
              be created with unique personalities. They'll draft, set lineups, make trades, and work the waiver wire automatically.
            </p>
          </div>
        </div>

        <div className="mt-6 flex justify-end gap-3">
          <button
            onClick={onClose}
            className="rounded-lg border border-[var(--border)] px-4 py-2 text-sm font-medium text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)]"
          >
            Cancel
          </button>
          <button
            onClick={() => createMutation.mutate()}
            disabled={createMutation.isPending || !form.name || !form.team_name}
            className="rounded-lg bg-[var(--accent-blue)] px-6 py-2 text-sm font-medium text-white hover:bg-[var(--accent-blue)]/90 disabled:opacity-50"
          >
            {createMutation.isPending ? 'Creating...' : 'Create League'}
          </button>
        </div>
      </div>
    </div>
  );
}

function JoinLeagueModal({ onClose }: { onClose: () => void }) {
  const [inviteCode, setInviteCode] = useState('');
  const [teamName, setTeamName] = useState('');
  const [ownerName, setOwnerName] = useState('');

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" onClick={onClose}>
      <div
        className="w-full max-w-md rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-6 shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 className="font-display text-xl mb-4">Join Fantasy League</h2>
        <div className="space-y-4">
          <Field label="Invite Code">
            <input
              className="input-field"
              placeholder="Enter invite code"
              value={inviteCode}
              onChange={(e) => setInviteCode(e.target.value.toUpperCase())}
            />
          </Field>
          <Field label="Your Team Name">
            <input className="input-field" placeholder="Team name" value={teamName} onChange={(e) => setTeamName(e.target.value)} />
          </Field>
          <Field label="Your Name">
            <input className="input-field" placeholder="Display name" value={ownerName} onChange={(e) => setOwnerName(e.target.value)} />
          </Field>
        </div>
        <div className="mt-6 flex justify-end gap-3">
          <button onClick={onClose} className="rounded-lg border border-[var(--border)] px-4 py-2 text-sm font-medium text-[var(--text-secondary)]">
            Cancel
          </button>
          <button
            disabled={!inviteCode || !teamName}
            className="rounded-lg bg-[var(--accent-blue)] px-6 py-2 text-sm font-medium text-white hover:bg-[var(--accent-blue)]/90 disabled:opacity-50"
          >
            Join League
          </button>
        </div>
      </div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1 block text-xs font-semibold uppercase tracking-wider text-[var(--text-secondary)]">{label}</span>
      {children}
    </label>
  );
}
