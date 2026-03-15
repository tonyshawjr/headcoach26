import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import {
  PageLayout, PageHeader, Section, ActionButton, StatRow,
} from '@/components/ui/sports-ui';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter, DialogTrigger, DialogClose,
} from '@/components/ui/dialog';
import { Check, AlertCircle, Sparkles, ArrowRightLeft, RotateCcw, Users, Settings } from 'lucide-react';
import { useAiStatus, useConfigureAi, useAvailableTeams, useSwitchTeam, useCareerHistory, useSession } from '@/hooks/useApi';
import { TeamBadge } from '@/components/TeamBadge';
import { toast } from 'sonner';
import { franchiseApi } from '@/api/client';
import type { AvailableTeam } from '@/api/client';

function SwitchTeamDialog() {
  const { data: teams, isLoading } = useAvailableTeams();
  const switchMut = useSwitchTeam();
  const { refetch: refetchSession } = useSession();
  const [selected, setSelected] = useState<AvailableTeam | null>(null);
  const [mode, setMode] = useState<'request_release' | 'retire'>('request_release');
  const [newCoachName, setNewCoachName] = useState('');
  const [step, setStep] = useState<'select' | 'confirm'>('select');

  function handleConfirm() {
    if (!selected) return;
    switchMut.mutate(
      { teamId: selected.id, mode, newCoachName: mode === 'retire' ? newCoachName : undefined },
      {
        onSuccess: () => {
          toast.success(`Switched to ${selected.city} ${selected.name}`);
          refetchSession();
          setTimeout(() => window.location.href = '/', 500);
        },
        onError: (err) => toast.error(err.message),
      },
    );
  }

  return (
    <Dialog onOpenChange={() => { setStep('select'); setSelected(null); }}>
      <DialogTrigger className="inline-flex items-center justify-center gap-1.5 rounded-md border-2 border-[var(--border)] bg-[var(--bg-surface)] px-3 py-1.5 text-xs font-bold uppercase tracking-[0.08em] text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] hover:border-[var(--text-muted)] transition-all active:scale-[0.97]">
        <ArrowRightLeft className="h-3.5 w-3.5" />
        Switch Team
      </DialogTrigger>
      <DialogContent className="max-w-2xl max-h-[80vh] overflow-y-auto">
        {step === 'select' ? (
          <>
            <DialogHeader>
              <DialogTitle>Switch Team</DialogTitle>
              <DialogDescription>Select a team to take over. Your current team will get an AI coach.</DialogDescription>
            </DialogHeader>
            <div className="mt-4 space-y-4">
              {/* Mode selection */}
              <div className="flex gap-2">
                <button
                  onClick={() => setMode('request_release')}
                  className={`flex-1 rounded-lg border p-3 text-left text-sm transition-colors ${
                    mode === 'request_release'
                      ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10'
                      : 'border-[var(--border)] bg-[var(--bg-primary)]'
                  }`}
                >
                  <p className="font-semibold text-[var(--text-primary)]">Request Release</p>
                  <p className="text-xs text-[var(--text-secondary)]">Keep your coach, move to a new team</p>
                </button>
                <button
                  onClick={() => setMode('retire')}
                  className={`flex-1 rounded-lg border p-3 text-left text-sm transition-colors ${
                    mode === 'retire'
                      ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10'
                      : 'border-[var(--border)] bg-[var(--bg-primary)]'
                  }`}
                >
                  <p className="font-semibold text-[var(--text-primary)]">Retire & Start Fresh</p>
                  <p className="text-xs text-[var(--text-secondary)]">Create a new coach for the new team</p>
                </button>
              </div>

              {mode === 'retire' && (
                <div>
                  <label className="text-sm text-[var(--text-secondary)]">New Coach Name</label>
                  <Input
                    value={newCoachName}
                    onChange={(e) => setNewCoachName(e.target.value)}
                    placeholder="Enter coach name..."
                    className="mt-1"
                  />
                </div>
              )}

              {/* Team grid */}
              {isLoading ? (
                <div className="flex h-32 items-center justify-center text-sm text-[var(--text-secondary)]">Loading teams...</div>
              ) : (
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                  {(teams ?? []).map((t) => (
                    <button
                      key={t.id}
                      onClick={() => setSelected(t)}
                      className={`rounded-lg border p-3 text-left transition-colors ${
                        selected?.id === t.id
                          ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10'
                          : 'border-[var(--border)] bg-[var(--bg-primary)] hover:bg-[var(--bg-elevated)]'
                      }`}
                    >
                      <div className="flex items-center gap-2">
                        <TeamBadge
                          abbreviation={t.abbreviation}
                          primaryColor={t.primary_color}
                          secondaryColor={t.secondary_color}
                          size="sm"
                        />
                        <div className="min-w-0">
                          <p className="truncate text-sm font-medium text-[var(--text-primary)]">
                            {t.city} {t.name}
                          </p>
                          <p className="text-[10px] text-[var(--text-muted)]">
                            {t.wins}-{t.losses} | OVR {t.overall_rating}
                          </p>
                        </div>
                      </div>
                    </button>
                  ))}
                </div>
              )}
            </div>
            <DialogFooter className="mt-4">
              <DialogClose className="inline-flex items-center justify-center rounded-md px-5 py-2.5 text-sm font-bold uppercase tracking-[0.08em] text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] transition-all active:scale-[0.97]">
                Cancel
              </DialogClose>
              <ActionButton
                disabled={!selected || (mode === 'retire' && !newCoachName.trim())}
                onClick={() => setStep('confirm')}
              >
                Continue
              </ActionButton>
            </DialogFooter>
          </>
        ) : (
          <>
            <DialogHeader>
              <DialogTitle>Confirm Team Switch</DialogTitle>
              <DialogDescription>This action cannot be undone.</DialogDescription>
            </DialogHeader>
            <div className="mt-4 space-y-3 text-sm">
              <div className="rounded-lg border border-yellow-500/30 bg-yellow-500/5 p-4">
                <p className="font-semibold text-yellow-400">Are you sure?</p>
                <ul className="mt-2 space-y-1 text-[var(--text-secondary)]">
                  <li>Your current team will be assigned an AI coach</li>
                  <li>You will take over the <strong>{selected?.city} {selected?.name}</strong></li>
                  {mode === 'retire' && (
                    <li>Your current coach will be retired and a new coach "{newCoachName}" will be created</li>
                  )}
                </ul>
              </div>
            </div>
            <DialogFooter className="mt-4">
              <ActionButton variant="secondary" onClick={() => setStep('select')}>Back</ActionButton>
              <ActionButton
                onClick={handleConfirm}
                disabled={switchMut.isPending}
                variant="danger"
              >
                {switchMut.isPending ? 'Switching...' : 'Confirm Switch'}
              </ActionButton>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}

function CareerHistorySection() {
  const { data: history, isLoading } = useCareerHistory();

  if (isLoading || !history || history.length === 0) return null;

  return (
    <Section title="Coaching History" accentColor="var(--accent-blue)">
      <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
        <div className="h-[2px] w-full" style={{ background: 'linear-gradient(90deg, var(--accent-blue), transparent)' }} />
        <div className="p-4 space-y-2">
          {history.map((h) => (
            <div key={h.id} className="flex items-center justify-between rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-3">
              <div className="flex items-center gap-3">
                <TeamBadge
                  abbreviation={h.abbreviation}
                  primaryColor={h.primary_color}
                  secondaryColor={h.secondary_color}
                  size="sm"
                />
                <div>
                  <p className="text-sm font-medium">{h.city} {h.team_name}</p>
                  <p className="text-[10px] text-[var(--text-muted)]">
                    Season {h.start_season}{h.end_season && h.end_season !== h.start_season ? `-${h.end_season}` : ''}
                  </p>
                </div>
              </div>
              <div className="text-right">
                <p className="font-stat text-sm">{h.wins}-{h.losses}{h.ties > 0 ? `-${h.ties}` : ''}</p>
                <Badge variant="outline" className="text-[9px]">
                  {h.departure_reason === 'retire' ? 'Retired' : 'Released'}
                </Badge>
              </div>
            </div>
          ))}
        </div>
      </div>
    </Section>
  );
}

function GenerateFreeAgentsButton() {
  const { league } = useAuthStore();
  const [isProcessing, setIsProcessing] = useState(false);

  async function handleGenerate() {
    if (!league?.id) return;
    setIsProcessing(true);
    try {
      const res = await franchiseApi.generateFreeAgents(league.id, 150);
      toast.success(`Created ${res.free_agents_created} free agents`);
    } catch (err: any) {
      toast.error(err.message || 'Failed to generate free agents');
    } finally {
      setIsProcessing(false);
    }
  }

  return (
    <ActionButton variant="secondary" size="sm" onClick={handleGenerate} disabled={isProcessing} icon={Users}>
      {isProcessing ? 'Generating...' : 'Generate Free Agents'}
    </ActionButton>
  );
}

export default function SettingsPage() {
  const { coach, team, league, user } = useAuthStore();
  const { data: aiStatus } = useAiStatus();
  const configureMut = useConfigureAi();
  const [apiKey, setApiKey] = useState('');

  const aiConfigured = aiStatus?.configured ?? false;

  return (
    <PageLayout className="mx-auto max-w-2xl">
      <PageHeader
        title="Settings"
        icon={Settings}
        accentColor="var(--accent-blue)"
        subtitle="Manage your account, coach, and league configuration"
      />

      {/* Account */}
      <Section title="Account" accentColor="var(--accent-blue)">
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
          <div className="h-[2px] w-full" style={{ background: 'linear-gradient(90deg, var(--accent-blue), transparent)' }} />
          <div className="px-5 py-4 space-y-1">
            <StatRow label="Username" value={user?.username ?? ''} />
            <StatRow label="Email" value={user?.email ?? ''} />
            <div className="flex items-center justify-between gap-2 py-1">
              <span className="text-xs text-[var(--text-secondary)]">Role</span>
              <Badge variant="outline">{user?.is_admin ? 'Admin' : 'User'}</Badge>
            </div>
          </div>
        </div>
      </Section>

      {/* Coach Profile */}
      <Section title="Coach Profile" accentColor="var(--accent-blue)">
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
          <div className="h-[2px] w-full" style={{ background: 'linear-gradient(90deg, var(--accent-blue), transparent)' }} />
          <div className="px-5 py-3 border-b border-[var(--border)] flex items-center justify-end">
            <SwitchTeamDialog />
          </div>
          <div className="px-5 py-4 space-y-1">
            <StatRow label="Name" value={coach?.name ?? ''} />
            <StatRow label="Archetype" value={coach?.archetype ?? 'None'} />
            <StatRow label="Influence" value={coach?.influence ?? 0} />
            <StatRow label="Job Security" value={coach?.job_security ?? 0} />
            <StatRow label="Media Rating" value={coach?.media_rating ?? 0} />
            <StatRow label="Contract" value={`${coach?.contract_years ?? 0} years`} />
          </div>
        </div>
      </Section>

      {/* League */}
      <Section title="League" accentColor="var(--accent-blue)">
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
          <div className="h-[2px] w-full" style={{ background: 'linear-gradient(90deg, var(--accent-blue), transparent)' }} />
          <div className="px-5 py-4 space-y-1">
            <StatRow label="League" value={league?.name ?? ''} />
            <StatRow label="Season" value={league?.season_year ?? ''} />
            <div className="flex items-center justify-between gap-2 py-1">
              <span className="text-xs text-[var(--text-secondary)]">Phase</span>
              <Badge variant="outline">{league?.phase}</Badge>
            </div>
            <StatRow label="Team" value={`${team?.abbreviation} - ${team?.city} ${team?.name}`} />
          </div>
        </div>
      </Section>

      {/* Coaching History */}
      <CareerHistorySection />

      {/* Franchise Management */}
      <Section title="Franchise Management" accentColor="var(--accent-red)">
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
          <div className="h-[2px] w-full" style={{ background: 'linear-gradient(90deg, var(--accent-red), transparent)' }} />
          <div className="px-5 py-4 space-y-4 text-sm">
            <p className="text-[var(--text-secondary)]">
              Start a new franchise from scratch or generate additional free agents for the current league.
            </p>
            <div className="flex flex-wrap gap-2">
              <Link to="/franchise-setup">
                <ActionButton variant="danger" size="sm" icon={RotateCcw}>
                  New Franchise
                </ActionButton>
              </Link>
              <GenerateFreeAgentsButton />
            </div>
          </div>
        </div>
      </Section>

      {/* AI Configuration */}
      <Section title="AI Configuration" accentColor="var(--accent-gold)">
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
          <div className="h-[2px] w-full" style={{ background: 'linear-gradient(90deg, var(--accent-gold), transparent)' }} />
          <div className="px-5 py-3 border-b border-[var(--border)] flex items-center justify-end">
            <Badge
              className={
                aiConfigured
                  ? 'border-green-500/30 bg-green-500/10 text-green-400'
                  : 'border-red-500/30 bg-red-500/10 text-red-400'
              }
            >
              {aiConfigured ? (
                <><Check className="mr-1 h-3 w-3" /> Configured</>
              ) : (
                <><AlertCircle className="mr-1 h-3 w-3" /> Not Configured</>
              )}
            </Badge>
          </div>
          <div className="px-5 py-4 space-y-4 text-sm">
            <p className="text-[var(--text-secondary)]">
              {aiConfigured
                ? 'Your AI API key is configured. You can update it below or visit AI Studio to generate content.'
                : 'Enter your OpenAI API key to enable AI-powered content generation features.'}
            </p>
            <div className="flex gap-2">
              <Input
                type="password"
                placeholder={aiConfigured ? 'Enter new API key to update...' : 'sk-...'}
                value={apiKey}
                onChange={(e) => setApiKey(e.target.value)}
                className="flex-1"
              />
              <ActionButton
                onClick={() => {
                  if (apiKey.trim()) {
                    configureMut.mutate(apiKey.trim());
                    setApiKey('');
                  }
                }}
                disabled={!apiKey.trim() || configureMut.isPending}
                size="sm"
              >
                {configureMut.isPending ? 'Saving...' : 'Save'}
              </ActionButton>
            </div>
            <div className="pt-1">
              <Link
                to="/ai-studio"
                className="inline-flex items-center gap-1.5 text-sm text-[var(--accent-blue)] hover:underline"
              >
                <Sparkles className="h-3.5 w-3.5" />
                Open AI Studio
              </Link>
            </div>
          </div>
        </div>
      </Section>
    </PageLayout>
  );
}
