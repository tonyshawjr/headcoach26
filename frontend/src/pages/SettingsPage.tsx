import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter, DialogTrigger, DialogClose,
} from '@/components/ui/dialog';
import { Key, Check, AlertCircle, Sparkles, ArrowRightLeft, History, RotateCcw, Users, Loader2 } from 'lucide-react';
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
          // Reload to refresh all team-dependent data
          setTimeout(() => window.location.href = '/', 500);
        },
        onError: (err) => toast.error(err.message),
      },
    );
  }

  return (
    <Dialog onOpenChange={() => { setStep('select'); setSelected(null); }}>
      <DialogTrigger className="inline-flex items-center justify-center gap-1.5 rounded-md border border-[var(--border)] bg-transparent px-3 py-1.5 text-sm font-medium text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)] hover:text-[var(--text-primary)] transition-colors">
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
              <DialogClose className="inline-flex items-center justify-center rounded-md border border-[var(--border)] bg-transparent px-4 py-2 text-sm font-medium text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)] hover:text-[var(--text-primary)] transition-colors">
                Cancel
              </DialogClose>
              <Button
                disabled={!selected || (mode === 'retire' && !newCoachName.trim())}
                onClick={() => setStep('confirm')}
              >
                Continue
              </Button>
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
              <Button variant="outline" onClick={() => setStep('select')}>Back</Button>
              <Button
                onClick={handleConfirm}
                disabled={switchMut.isPending}
                className="bg-red-600 hover:bg-red-700"
              >
                {switchMut.isPending ? 'Switching...' : 'Confirm Switch'}
              </Button>
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
    <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
      <CardHeader>
        <CardTitle className="font-display text-base flex items-center gap-2">
          <History className="h-4 w-4 text-[var(--accent-blue)]" />
          Coaching History
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-2">
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
              <p className="text-sm font-mono">{h.wins}-{h.losses}{h.ties > 0 ? `-${h.ties}` : ''}</p>
              <Badge variant="outline" className="text-[9px]">
                {h.departure_reason === 'retire' ? 'Retired' : 'Released'}
              </Badge>
            </div>
          </div>
        ))}
      </CardContent>
    </Card>
  );
}

function _UNUSED_RestartFranchiseDialog() {
  const { data: teams, isLoading: teamsLoading } = useAvailableTeams();
  const [step, setStep] = useState<'warning' | 'setup' | 'roster' | 'done'>('warning');
  const [leagueName, setLeagueName] = useState('Head Coach 26');
  const [coachName, setCoachName] = useState('');
  const [selectedTeamIdx, setSelectedTeamIdx] = useState<number | null>(null);
  const [rosterMode, setRosterMode] = useState<'generate' | 'import'>('generate');
  const [isProcessing, setIsProcessing] = useState(false);
  const [newLeagueId, setNewLeagueId] = useState<number | null>(null);
  const [confirmText, setConfirmText] = useState('');

  async function handleRestart() {
    if (!selectedTeamIdx || !coachName.trim()) return;
    setIsProcessing(true);
    try {
      const res = await franchiseApi.restart(leagueName, 32, selectedTeamIdx, coachName.trim());
      setNewLeagueId(res.league_id);
      setStep('roster');
    } catch (err: any) {
      toast.error(err.message || 'Failed to restart franchise');
    } finally {
      setIsProcessing(false);
    }
  }

  async function handleGenerateRoster() {
    if (!newLeagueId) return;
    setIsProcessing(true);
    try {
      const res = await franchiseApi.generateRoster(newLeagueId, 150);
      toast.success(`Created ${res.players_created} players and ${res.free_agents_created} free agents`);
      setStep('done');
    } catch (err: any) {
      toast.error(err.message || 'Failed to generate roster');
    } finally {
      setIsProcessing(false);
    }
  }

  async function handleGenerateFreeAgents() {
    if (!newLeagueId) return;
    setIsProcessing(true);
    try {
      const res = await franchiseApi.generateFreeAgents(newLeagueId, 150);
      toast.success(`Created ${res.free_agents_created} free agents`);
    } catch (err: any) {
      toast.error(err.message || 'Failed to generate free agents');
    } finally {
      setIsProcessing(false);
    }
  }

  function handleDone() {
    window.location.href = '/';
  }

  return (
    <Dialog onOpenChange={() => { setStep('warning'); setConfirmText(''); setSelectedTeamIdx(null); }}>
      <DialogTrigger className="inline-flex items-center justify-center gap-1.5 rounded-md border border-red-500/30 bg-red-500/5 px-3 py-1.5 text-sm font-medium text-red-400 hover:bg-red-500/10 transition-colors">
        <RotateCcw className="h-3.5 w-3.5" />
        Restart Franchise
      </DialogTrigger>
      <DialogContent className="max-w-2xl max-h-[80vh] overflow-y-auto">
        {step === 'warning' && (
          <>
            <DialogHeader>
              <DialogTitle className="text-red-400">Restart Franchise</DialogTitle>
              <DialogDescription>This will permanently delete all game data and start fresh.</DialogDescription>
            </DialogHeader>
            <div className="mt-4 space-y-4">
              <div className="rounded-lg border border-red-500/30 bg-red-500/5 p-4">
                <p className="font-semibold text-red-400">This will delete:</p>
                <ul className="mt-2 space-y-1 text-sm text-[var(--text-secondary)]">
                  <li>All teams, players, and rosters</li>
                  <li>All game results and stats</li>
                  <li>All trades, contracts, and free agency data</li>
                  <li>All draft classes and coaching history</li>
                  <li>Your coach profile and legacy score</li>
                </ul>
                <p className="mt-3 text-sm text-[var(--text-secondary)]">
                  Your user account will be preserved. You will set up a new franchise from scratch.
                </p>
              </div>
              <div>
                <label className="text-sm text-[var(--text-secondary)]">Type <strong>RESTART</strong> to confirm</label>
                <Input
                  value={confirmText}
                  onChange={(e) => setConfirmText(e.target.value)}
                  placeholder="RESTART"
                  className="mt-1"
                />
              </div>
            </div>
            <DialogFooter className="mt-4">
              <DialogClose className="inline-flex items-center justify-center rounded-md border border-[var(--border)] bg-transparent px-4 py-2 text-sm font-medium text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)] hover:text-[var(--text-primary)] transition-colors">
                Cancel
              </DialogClose>
              <Button
                disabled={confirmText !== 'RESTART'}
                onClick={() => setStep('setup')}
                className="bg-red-600 hover:bg-red-700"
              >
                Continue
              </Button>
            </DialogFooter>
          </>
        )}

        {step === 'setup' && (
          <>
            <DialogHeader>
              <DialogTitle>New Franchise Setup</DialogTitle>
              <DialogDescription>Configure your new league and pick your team.</DialogDescription>
            </DialogHeader>
            <div className="mt-4 space-y-4">
              <div>
                <label className="text-sm text-[var(--text-secondary)]">League Name</label>
                <Input
                  value={leagueName}
                  onChange={(e) => setLeagueName(e.target.value)}
                  placeholder="Head Coach 26"
                  className="mt-1"
                />
              </div>
              <div>
                <label className="text-sm text-[var(--text-secondary)]">Your Coach Name</label>
                <Input
                  value={coachName}
                  onChange={(e) => setCoachName(e.target.value)}
                  placeholder="Enter your coach name..."
                  className="mt-1"
                />
              </div>
              <div>
                <label className="text-sm text-[var(--text-secondary)]">Pick Your Team</label>
                {teamsLoading ? (
                  <div className="mt-2 flex h-32 items-center justify-center text-sm text-[var(--text-secondary)]">Loading teams...</div>
                ) : (
                  <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3 max-h-64 overflow-y-auto">
                    {(teams ?? []).map((t) => (
                      <button
                        key={t.id}
                        onClick={() => setSelectedTeamIdx(t.id)}
                        className={`rounded-lg border p-3 text-left transition-colors ${
                          selectedTeamIdx === t.id
                            ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10'
                            : 'border-[var(--border)] bg-[var(--bg-primary)] hover:bg-[var(--bg-elevated)]'
                        }`}
                      >
                        <div className="flex items-center gap-2">
                          <TeamBadge abbreviation={t.abbreviation} primaryColor={t.primary_color} secondaryColor={t.secondary_color} size="sm" />
                          <div className="min-w-0">
                            <p className="truncate text-sm font-medium text-[var(--text-primary)]">{t.city} {t.name}</p>
                          </div>
                        </div>
                      </button>
                    ))}
                  </div>
                )}
              </div>
            </div>
            <DialogFooter className="mt-4">
              <Button variant="outline" onClick={() => setStep('warning')}>Back</Button>
              <Button
                disabled={!selectedTeamIdx || !coachName.trim() || !leagueName.trim() || isProcessing}
                onClick={handleRestart}
              >
                {isProcessing ? (
                  <><Loader2 className="mr-2 h-4 w-4 animate-spin" /> Creating League...</>
                ) : (
                  'Create League & Teams'
                )}
              </Button>
            </DialogFooter>
          </>
        )}

        {step === 'roster' && (
          <>
            <DialogHeader>
              <DialogTitle>Roster Setup</DialogTitle>
              <DialogDescription>Your league and teams are ready. Now choose how to populate rosters.</DialogDescription>
            </DialogHeader>
            <div className="mt-4 space-y-4">
              <div className="flex gap-2">
                <button
                  onClick={() => setRosterMode('generate')}
                  className={`flex-1 rounded-lg border p-4 text-left transition-colors ${
                    rosterMode === 'generate'
                      ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10'
                      : 'border-[var(--border)] bg-[var(--bg-primary)]'
                  }`}
                >
                  <div className="flex items-center gap-2 mb-1">
                    <Users className="h-4 w-4" />
                    <p className="font-semibold text-[var(--text-primary)]">Generate Random Rosters</p>
                  </div>
                  <p className="text-xs text-[var(--text-secondary)]">Create fictional players for all teams plus a free agent pool. Ready to play immediately.</p>
                </button>
                <button
                  onClick={() => setRosterMode('import')}
                  className={`flex-1 rounded-lg border p-4 text-left transition-colors ${
                    rosterMode === 'import'
                      ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10'
                      : 'border-[var(--border)] bg-[var(--bg-primary)]'
                  }`}
                >
                  <div className="flex items-center gap-2 mb-1">
                    <Sparkles className="h-4 w-4" />
                    <p className="font-semibold text-[var(--text-primary)]">Import Roster CSV</p>
                  </div>
                  <p className="text-xs text-[var(--text-secondary)]">Import from a Madden 26 roster export. You can generate free agents after.</p>
                </button>
              </div>

              {rosterMode === 'generate' && (
                <div className="rounded-lg border border-green-500/20 bg-green-500/5 p-4">
                  <p className="text-sm text-[var(--text-secondary)]">
                    This will generate full rosters (~53 players per team) for all 32 teams plus 150 free agents.
                    All players will have randomized ratings, blueprints, edges, and instincts.
                  </p>
                </div>
              )}

              {rosterMode === 'import' && (
                <div className="rounded-lg border border-blue-500/20 bg-blue-500/5 p-4">
                  <p className="text-sm text-[var(--text-secondary)]">
                    After closing this dialog, go to <strong>Roster Import</strong> to upload your CSV file.
                    Then come back to Settings to generate a free agent pool if needed.
                  </p>
                </div>
              )}
            </div>
            <DialogFooter className="mt-4">
              {rosterMode === 'generate' ? (
                <Button onClick={handleGenerateRoster} disabled={isProcessing}>
                  {isProcessing ? (
                    <><Loader2 className="mr-2 h-4 w-4 animate-spin" /> Generating Players...</>
                  ) : (
                    'Generate All Rosters'
                  )}
                </Button>
              ) : (
                <Button onClick={handleDone}>
                  Go to Roster Import
                </Button>
              )}
            </DialogFooter>
          </>
        )}

        {step === 'done' && (
          <>
            <DialogHeader>
              <DialogTitle className="text-green-400">Franchise Ready!</DialogTitle>
              <DialogDescription>Your new franchise has been set up successfully.</DialogDescription>
            </DialogHeader>
            <div className="mt-4">
              <div className="rounded-lg border border-green-500/20 bg-green-500/5 p-4 text-center">
                <p className="text-lg font-display text-green-400">All Set</p>
                <p className="mt-1 text-sm text-[var(--text-secondary)]">
                  Your league, teams, rosters, and free agent pool are ready. Time to coach.
                </p>
              </div>
            </div>
            <DialogFooter className="mt-4">
              <Button onClick={handleDone}>Start Coaching</Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
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
    <Button variant="outline" size="sm" onClick={handleGenerate} disabled={isProcessing}>
      {isProcessing ? (
        <><Loader2 className="mr-2 h-3.5 w-3.5 animate-spin" /> Generating...</>
      ) : (
        <><Users className="mr-1.5 h-3.5 w-3.5" /> Generate Free Agents</>
      )}
    </Button>
  );
}

export default function SettingsPage() {
  const { coach, team, league, user } = useAuthStore();
  const { data: aiStatus } = useAiStatus();
  const configureMut = useConfigureAi();
  const [apiKey, setApiKey] = useState('');

  const aiConfigured = aiStatus?.configured ?? false;

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <h1 className="font-display text-2xl">Settings</h1>

      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardHeader>
          <CardTitle className="font-display text-base">Account</CardTitle>
        </CardHeader>
        <CardContent className="space-y-2 text-sm">
          <div className="flex justify-between">
            <span className="text-[var(--text-secondary)]">Username</span>
            <span>{user?.username}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-[var(--text-secondary)]">Email</span>
            <span>{user?.email}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-[var(--text-secondary)]">Role</span>
            <Badge variant="outline">{user?.is_admin ? 'Admin' : 'User'}</Badge>
          </div>
        </CardContent>
      </Card>

      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardHeader>
          <div className="flex items-center justify-between">
            <CardTitle className="font-display text-base">Coach Profile</CardTitle>
            <SwitchTeamDialog />
          </div>
        </CardHeader>
        <CardContent className="space-y-2 text-sm">
          <div className="flex justify-between">
            <span className="text-[var(--text-secondary)]">Name</span>
            <span>{coach?.name}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-[var(--text-secondary)]">Archetype</span>
            <span>{coach?.archetype ?? 'None'}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-[var(--text-secondary)]">Influence</span>
            <span>{coach?.influence}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-[var(--text-secondary)]">Job Security</span>
            <span>{coach?.job_security}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-[var(--text-secondary)]">Media Rating</span>
            <span>{coach?.media_rating}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-[var(--text-secondary)]">Contract</span>
            <span>{coach?.contract_years} years</span>
          </div>
        </CardContent>
      </Card>

      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardHeader>
          <CardTitle className="font-display text-base">League</CardTitle>
        </CardHeader>
        <CardContent className="space-y-2 text-sm">
          <div className="flex justify-between">
            <span className="text-[var(--text-secondary)]">League</span>
            <span>{league?.name}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-[var(--text-secondary)]">Season</span>
            <span>{league?.season_year}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-[var(--text-secondary)]">Phase</span>
            <Badge variant="outline">{league?.phase}</Badge>
          </div>
          <div className="flex justify-between">
            <span className="text-[var(--text-secondary)]">Team</span>
            <span>{team?.abbreviation} - {team?.city} {team?.name}</span>
          </div>
        </CardContent>
      </Card>

      {/* Coaching History */}
      <CareerHistorySection />

      {/* Franchise Management */}
      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardHeader>
          <CardTitle className="font-display text-base flex items-center gap-2">
            <RotateCcw className="h-4 w-4 text-[var(--accent-red)]" />
            Franchise Management
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4 text-sm">
          <p className="text-[var(--text-secondary)]">
            Start a new franchise from scratch or generate additional free agents for the current league.
          </p>
          <div className="flex flex-wrap gap-2">
            <Link
              to="/franchise-setup"
              className="inline-flex items-center justify-center gap-1.5 rounded-md border border-red-500/30 bg-red-500/5 px-3 py-1.5 text-sm font-medium text-red-400 hover:bg-red-500/10 transition-colors"
            >
              <RotateCcw className="h-3.5 w-3.5" />
              New Franchise
            </Link>
            <GenerateFreeAgentsButton />
          </div>
        </CardContent>
      </Card>

      {/* AI Configuration */}
      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardHeader>
          <div className="flex items-center justify-between">
            <CardTitle className="font-display text-base flex items-center gap-2">
              <Key className="h-4 w-4 text-[var(--accent-gold)]" />
              AI Configuration
            </CardTitle>
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
        </CardHeader>
        <CardContent className="space-y-4 text-sm">
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
            <Button
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
            </Button>
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
        </CardContent>
      </Card>
    </div>
  );
}
