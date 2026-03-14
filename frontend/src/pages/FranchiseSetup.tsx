import { useState, useEffect, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { TeamBadge } from '@/components/TeamBadge';
import { franchiseApi } from '@/api/client';
import type { SetupTeam } from '@/api/client';
import { toast } from 'sonner';
import {
  Loader2, ChevronRight, ChevronLeft, Trophy, Users, Upload, Check, Shield,
  Settings2, Pencil,
} from 'lucide-react';

// ─── Defaults ────────────────────────────────────────────────────────────────

const DEFAULT_CONF_NAMES: Record<string, string> = {
  AC: 'Atlantic Conference',
  PC: 'Pacific Conference',
};

const TEAM_COUNT_OPTIONS = [4, 6, 8, 10, 12, 14, 16, 20, 24, 28, 32];

function getDefaultDivisionNames(count: number): string[] {
  if (count <= 1) return ['Division'];
  if (count === 2) return ['North', 'South'];
  if (count === 3) return ['North', 'South', 'Central'];
  return ['North', 'South', 'East', 'West'];
}

function getDefaultConferenceNames(count: number): string[] {
  if (count <= 1) return ['League'];
  if (count === 2) return ['Atlantic Conference', 'Pacific Conference'];
  return Array.from({ length: count }, (_, i) => `Conference ${i + 1}`);
}

// Given a team count, figure out a sensible structure
function getAutoStructure(teamCount: number): { conferences: number; divisionsPerConf: number } {
  if (teamCount <= 6) return { conferences: 1, divisionsPerConf: teamCount <= 4 ? 1 : 2 };
  if (teamCount <= 10) return { conferences: 2, divisionsPerConf: 1 };
  if (teamCount <= 16) return { conferences: 2, divisionsPerConf: 2 };
  if (teamCount <= 24) return { conferences: 2, divisionsPerConf: 3 };
  return { conferences: 2, divisionsPerConf: 4 };
}

// ─── Step indicator ──────────────────────────────────────────────────────────

function StepIndicator({ current, total }: { current: number; total: number }) {
  const labels = ['League Info', 'Structure', 'Pick Your Team', 'Rosters'];
  return (
    <div className="flex items-center gap-2 mb-8">
      {Array.from({ length: total }, (_, i) => {
        const s = i + 1;
        const isActive = s === current;
        const isDone = s < current;
        return (
          <div key={s} className="flex items-center gap-2">
            {i > 0 && <div className={`h-px w-8 ${isDone ? 'bg-green-500' : 'bg-white/10'}`} />}
            <div className={`flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold transition-all ${
              isActive ? 'bg-[var(--accent-blue)] text-white' :
              isDone ? 'bg-green-500/20 text-green-400 border border-green-500/30' :
              'bg-white/5 text-[var(--text-muted)] border border-white/10'
            }`}>
              {isDone ? <Check className="h-3.5 w-3.5" /> : s}
            </div>
            <span className={`hidden sm:inline text-xs font-medium ${
              isActive ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'
            }`}>
              {labels[i]}
            </span>
          </div>
        );
      })}
    </div>
  );
}

// ─── Editable label ──────────────────────────────────────────────────────────

function EditableLabel({ value, onChange, className = '' }: { value: string; onChange: (v: string) => void; className?: string }) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value);

  if (editing) {
    return (
      <Input
        autoFocus
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={() => { onChange(draft); setEditing(false); }}
        onKeyDown={(e) => { if (e.key === 'Enter') { onChange(draft); setEditing(false); } }}
        className={`h-7 px-2 text-sm ${className}`}
      />
    );
  }

  return (
    <button
      onClick={() => { setDraft(value); setEditing(true); }}
      className={`group inline-flex items-center gap-1.5 rounded px-1 -mx-1 hover:bg-white/5 transition-colors ${className}`}
    >
      <span>{value}</span>
      <Pencil className="h-3 w-3 text-[var(--text-muted)] opacity-0 group-hover:opacity-100 transition-opacity" />
    </button>
  );
}

// ─── Main Setup Page ─────────────────────────────────────────────────────────

export default function FranchiseSetup() {
  const navigate = useNavigate();
  const [step, setStep] = useState(1);
  const [isProcessing, setIsProcessing] = useState(false);

  // Step 1
  const [leagueName, setLeagueName] = useState('Head Coach 26');
  const [coachName, setCoachName] = useState('');

  // Step 2: Structure
  const [allTeams, setAllTeams] = useState<SetupTeam[]>([]);
  const [loadingConfig, setLoadingConfig] = useState(true);
  const [showCustomize, setShowCustomize] = useState(false);
  const [teamCount, setTeamCount] = useState(32);
  const [numConferences, setNumConferences] = useState(2);
  const [divsPerConf, setDivsPerConf] = useState(4);
  const [confNames, setConfNames] = useState<string[]>(['Atlantic Conference', 'Pacific Conference']);
  const [divNames, setDivNames] = useState<string[]>(['North', 'South', 'East', 'West']);

  // Step 3
  const [selectedTeamIdx, setSelectedTeamIdx] = useState<number | null>(null);

  // Step 4
  const [rosterMode, setRosterMode] = useState<'generate' | 'import'>('generate');
  const [newLeagueId, setNewLeagueId] = useState<number | null>(null);
  const [setupComplete, setSetupComplete] = useState(false);

  // Load config
  useEffect(() => {
    franchiseApi.teamsConfig()
      .then((data) => setAllTeams(data.teams))
      .catch(() => toast.error('Failed to load teams configuration'))
      .finally(() => setLoadingConfig(false));
  }, []);

  // When team count changes, auto-adjust structure
  useEffect(() => {
    const auto = getAutoStructure(teamCount);
    setNumConferences(auto.conferences);
    setDivsPerConf(auto.divisionsPerConf);
    setConfNames(getDefaultConferenceNames(auto.conferences));
    setDivNames(getDefaultDivisionNames(auto.divisionsPerConf));
    setSelectedTeamIdx(null);
  }, [teamCount]);

  // Build the active teams list and structure from customization
  const activeTeams = useMemo(() => allTeams.slice(0, teamCount), [allTeams, teamCount]);

  const customStructure = useMemo(() => {
    const structure: Record<string, Record<string, SetupTeam[]>> = {};
    const teamsPerDiv = Math.ceil(teamCount / (numConferences * divsPerConf));
    let teamIdx = 0;

    for (let c = 0; c < numConferences; c++) {
      const cName = confNames[c] || `Conference ${c + 1}`;
      structure[cName] = {};
      for (let d = 0; d < divsPerConf; d++) {
        const dName = divNames[d] || `Division ${d + 1}`;
        structure[cName][dName] = [];
        for (let t = 0; t < teamsPerDiv && teamIdx < teamCount; t++) {
          if (activeTeams[teamIdx]) {
            structure[cName][dName].push(activeTeams[teamIdx]);
          }
          teamIdx++;
        }
      }
    }

    // Distribute any leftover teams into the last division
    while (teamIdx < teamCount && activeTeams[teamIdx]) {
      const lastConf = Object.keys(structure).at(-1)!;
      const lastDiv = Object.keys(structure[lastConf]).at(-1)!;
      structure[lastConf][lastDiv].push(activeTeams[teamIdx]);
      teamIdx++;
    }

    return structure;
  }, [activeTeams, teamCount, numConferences, divsPerConf, confNames, divNames]);

  const teamsPerDivision = useMemo(() => {
    const total = numConferences * divsPerConf;
    if (total === 0) return 0;
    return Math.ceil(teamCount / total);
  }, [teamCount, numConferences, divsPerConf]);

  // ─── Actions ───────────────────────────────────────────────────────────────

  async function handleCreateLeague() {
    if (!selectedTeamIdx || !coachName.trim()) return;
    setIsProcessing(true);
    try {
      const res = await franchiseApi.restart(leagueName, teamCount, selectedTeamIdx, coachName.trim());
      setNewLeagueId(res.league_id);
      setStep(4);
    } catch (err: any) {
      toast.error(err.message || 'Failed to create league');
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
      setSetupComplete(true);
    } catch (err: any) {
      toast.error(err.message || 'Failed to generate roster');
    } finally {
      setIsProcessing(false);
    }
  }

  if (loadingConfig) {
    return (
      <div className="flex h-96 items-center justify-center">
        <div className="flex flex-col items-center gap-3">
          <Loader2 className="h-8 w-8 animate-spin text-[var(--accent-blue)]" />
          <p className="text-sm text-[var(--text-secondary)]">Loading setup...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-4xl py-8">
      <div className="mb-2 text-center">
        <h1 className="font-display text-4xl tracking-tight">New Franchise</h1>
        <p className="mt-2 text-sm text-[var(--text-secondary)]">
          Set up your league, pick your team, and build your roster.
        </p>
      </div>

      <div className="mt-8">
        <StepIndicator current={setupComplete ? 5 : step} total={4} />
      </div>

      {/* ═══ STEP 1: League Info & Coach Name ═══ */}
      {step === 1 && (
        <div className="rounded-xl bg-[var(--bg-surface)] p-8">
          <div className="flex items-center gap-3 mb-6">
            <Trophy className="h-6 w-6 text-[var(--accent-gold)]" />
            <h2 className="font-display text-2xl">League & Coach</h2>
          </div>
          <div className="space-y-6 max-w-lg">
            <div>
              <label className="text-sm font-medium text-[var(--text-secondary)]">League Name</label>
              <Input value={leagueName} onChange={(e) => setLeagueName(e.target.value)} placeholder="Head Coach 26" className="mt-2 text-lg" />
              <p className="mt-1 text-xs text-[var(--text-muted)]">The name of your league.</p>
            </div>
            <div>
              <label className="text-sm font-medium text-[var(--text-secondary)]">Your Coach Name</label>
              <Input value={coachName} onChange={(e) => setCoachName(e.target.value)} placeholder="Enter your coach name..." className="mt-2 text-lg" />
              <p className="mt-1 text-xs text-[var(--text-muted)]">This is you. Choose wisely.</p>
            </div>
          </div>
          <div className="mt-8 flex justify-end">
            <Button disabled={!leagueName.trim() || !coachName.trim()} onClick={() => setStep(2)} className="gap-2">
              Next: League Structure <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      )}

      {/* ═══ STEP 2: League Structure ═══ */}
      {step === 2 && (
        <div className="rounded-xl bg-[var(--bg-surface)] p-8">
          <div className="flex items-center justify-between mb-2">
            <div className="flex items-center gap-3">
              <Shield className="h-6 w-6 text-[var(--accent-blue)]" />
              <h2 className="font-display text-2xl">League Structure</h2>
            </div>
            <button
              onClick={() => setShowCustomize(!showCustomize)}
              className={`inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition-all ${
                showCustomize
                  ? 'border-[var(--accent-blue)]/30 bg-[var(--accent-blue)]/10 text-[var(--accent-blue)]'
                  : 'border-white/10 text-[var(--text-secondary)] hover:bg-white/5'
              }`}
            >
              <Settings2 className="h-3.5 w-3.5" />
              Customize
            </button>
          </div>
          <p className="mb-6 text-sm text-[var(--text-secondary)]">
            {teamCount} teams in {numConferences} conference{numConferences !== 1 ? 's' : ''} with {divsPerConf} division{divsPerConf !== 1 ? 's' : ''} each
            {teamsPerDivision > 0 ? ` — ${teamsPerDivision} teams per division` : ''}.
            {!showCustomize && ' Click Customize to change.'}
          </p>

          {/* ── Customization Panel ── */}
          {showCustomize && (
            <div className="mb-6 rounded-lg border border-[var(--accent-blue)]/10 bg-[var(--accent-blue)]/[0.03] p-5 space-y-5">
              {/* Team Count */}
              <div>
                <label className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Number of Teams</label>
                <div className="mt-2 flex flex-wrap gap-2">
                  {TEAM_COUNT_OPTIONS.map((n) => (
                    <button
                      key={n}
                      onClick={() => setTeamCount(n)}
                      className={`rounded-md border px-3 py-1.5 text-sm font-medium transition-all ${
                        teamCount === n
                          ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10 text-[var(--accent-blue)]'
                          : 'border-white/10 text-[var(--text-secondary)] hover:bg-white/5'
                      }`}
                    >
                      {n}
                    </button>
                  ))}
                </div>
              </div>

              {/* Conferences */}
              <div>
                <label className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Conferences</label>
                <div className="mt-2 flex gap-2">
                  {[1, 2, 3, 4].map((n) => (
                    <button
                      key={n}
                      onClick={() => {
                        setNumConferences(n);
                        setConfNames(getDefaultConferenceNames(n));
                      }}
                      className={`rounded-md border px-3 py-1.5 text-sm font-medium transition-all ${
                        numConferences === n
                          ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10 text-[var(--accent-blue)]'
                          : 'border-white/10 text-[var(--text-secondary)] hover:bg-white/5'
                      }`}
                    >
                      {n}
                    </button>
                  ))}
                </div>
                {/* Editable conference names */}
                <div className="mt-3 flex flex-wrap gap-3">
                  {confNames.map((name, i) => (
                    <div key={i} className="flex items-center gap-1.5">
                      <span className="text-[10px] text-[var(--text-muted)]">#{i + 1}</span>
                      <EditableLabel
                        value={name}
                        onChange={(v) => { const copy = [...confNames]; copy[i] = v; setConfNames(copy); }}
                        className="text-sm font-medium"
                      />
                    </div>
                  ))}
                </div>
              </div>

              {/* Divisions per conference */}
              <div>
                <label className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Divisions per Conference</label>
                <div className="mt-2 flex gap-2">
                  {[1, 2, 3, 4].map((n) => (
                    <button
                      key={n}
                      onClick={() => {
                        setDivsPerConf(n);
                        setDivNames(getDefaultDivisionNames(n));
                      }}
                      className={`rounded-md border px-3 py-1.5 text-sm font-medium transition-all ${
                        divsPerConf === n
                          ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10 text-[var(--accent-blue)]'
                          : 'border-white/10 text-[var(--text-secondary)] hover:bg-white/5'
                      }`}
                    >
                      {n}
                    </button>
                  ))}
                </div>
                {/* Editable division names */}
                <div className="mt-3 flex flex-wrap gap-3">
                  {divNames.map((name, i) => (
                    <div key={i} className="flex items-center gap-1.5">
                      <span className="text-[10px] text-[var(--text-muted)]">#{i + 1}</span>
                      <EditableLabel
                        value={name}
                        onChange={(v) => { const copy = [...divNames]; copy[i] = v; setDivNames(copy); }}
                        className="text-sm font-medium"
                      />
                    </div>
                  ))}
                </div>
              </div>

              {/* Summary */}
              <div className="rounded-md bg-black/20 p-3 text-xs text-[var(--text-secondary)]">
                {teamCount} teams / {numConferences} conference{numConferences !== 1 ? 's' : ''} / {divsPerConf} division{divsPerConf !== 1 ? 's' : ''} per conference = {numConferences * divsPerConf} total divisions, ~{teamsPerDivision} teams each
              </div>
            </div>
          )}

          {/* ── Structure Preview ── */}
          <div className={`grid gap-6 ${numConferences >= 3 ? 'lg:grid-cols-2' : numConferences === 2 ? 'lg:grid-cols-2' : ''}`}>
            {Object.entries(customStructure).map(([confName, divisions]) => (
              <div key={confName} className="rounded-lg border border-white/5 bg-[var(--bg-elevated)] p-5">
                <h3 className="font-display text-lg mb-4">
                  {confName}
                </h3>
                <div className="space-y-4">
                  {Object.entries(divisions).map(([divName, divTeams]) => (
                    <div key={divName}>
                      <h4 className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] mb-2">
                        {divName}
                      </h4>
                      {divTeams.length > 0 ? (
                        <div className="grid grid-cols-2 gap-1.5">
                          {divTeams.map((t) => (
                            <div key={t.abbreviation} className="flex items-center gap-2 rounded-md bg-[var(--bg-primary)] p-2">
                              <TeamBadge abbreviation={t.abbreviation} primaryColor={t.primary_color} secondaryColor={t.secondary_color} size="sm" />
                              <p className="truncate text-xs font-medium">{t.city} {t.name}</p>
                            </div>
                          ))}
                        </div>
                      ) : (
                        <p className="text-xs text-[var(--text-muted)] italic">No teams</p>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>

          <div className="mt-8 flex justify-between">
            <Button variant="outline" onClick={() => setStep(1)} className="gap-2">
              <ChevronLeft className="h-4 w-4" /> Back
            </Button>
            <Button onClick={() => setStep(3)} className="gap-2">
              Next: Pick Your Team <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      )}

      {/* ═══ STEP 3: Pick Your Team ═══ */}
      {step === 3 && (
        <div className="rounded-xl bg-[var(--bg-surface)] p-8">
          <div className="flex items-center gap-3 mb-2">
            <Users className="h-6 w-6 text-green-400" />
            <h2 className="font-display text-2xl">Pick Your Team</h2>
          </div>
          <p className="mb-6 text-sm text-[var(--text-secondary)]">
            Choose the team you'll coach. All other teams will be managed by AI.
          </p>

          <div className="space-y-6">
            {Object.entries(customStructure).map(([confName, divisions]) => (
              <div key={confName}>
                <h3 className="font-display text-sm uppercase tracking-wider text-[var(--text-muted)] mb-3">
                  {confName}
                </h3>
                <div className={`grid gap-4 ${divsPerConf >= 4 ? 'sm:grid-cols-2 lg:grid-cols-4' : divsPerConf === 3 ? 'sm:grid-cols-3' : divsPerConf === 2 ? 'sm:grid-cols-2' : ''}`}>
                  {Object.entries(divisions).map(([divName, divTeams]) => (
                    <div key={divName}>
                      <p className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)] mb-2">
                        {divName}
                      </p>
                      <div className="space-y-1.5">
                        {divTeams.map((t) => {
                          const isSelected = selectedTeamIdx === t.index;
                          return (
                            <button
                              key={t.index}
                              onClick={() => setSelectedTeamIdx(t.index)}
                              className={`flex w-full items-center gap-2.5 rounded-lg border p-3 text-left transition-all ${
                                isSelected
                                  ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10 ring-1 ring-[var(--accent-blue)]/30'
                                  : 'border-white/5 bg-[var(--bg-elevated)] hover:bg-[var(--bg-elevated)]/80 hover:border-white/10'
                              }`}
                            >
                              <TeamBadge abbreviation={t.abbreviation} primaryColor={t.primary_color} secondaryColor={t.secondary_color} size="sm" />
                              <div className="min-w-0 flex-1">
                                <p className={`truncate text-sm font-medium ${isSelected ? 'text-[var(--accent-blue)]' : ''}`}>
                                  {t.city}
                                </p>
                                <p className={`truncate text-xs ${isSelected ? 'text-[var(--accent-blue)]/70' : 'text-[var(--text-muted)]'}`}>
                                  {t.name}
                                </p>
                              </div>
                              {isSelected && <Check className="h-4 w-4 text-[var(--accent-blue)] shrink-0" />}
                            </button>
                          );
                        })}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>

          {/* Selected summary */}
          {selectedTeamIdx && (() => {
            const t = activeTeams.find(t => t.index === selectedTeamIdx);
            if (!t) return null;
            // Find which conference/division this team is in
            let teamConf = '';
            let teamDiv = '';
            for (const [cName, divs] of Object.entries(customStructure)) {
              for (const [dName, teams] of Object.entries(divs)) {
                if (teams.some(tm => tm.index === selectedTeamIdx)) {
                  teamConf = cName;
                  teamDiv = dName;
                }
              }
            }
            return (
              <div className="mt-6 rounded-lg border border-[var(--accent-blue)]/20 bg-[var(--accent-blue)]/5 p-4">
                <div className="flex items-center gap-4">
                  <TeamBadge abbreviation={t.abbreviation} primaryColor={t.primary_color} secondaryColor={t.secondary_color} size="lg" />
                  <div>
                    <p className="font-display text-xl">{t.city} {t.name}</p>
                    <p className="text-sm text-[var(--text-secondary)]">{teamConf} — {teamDiv}</p>
                    <p className="text-xs text-[var(--text-muted)] mt-0.5">Coach {coachName} reporting for duty</p>
                  </div>
                </div>
              </div>
            );
          })()}

          <div className="mt-8 flex justify-between">
            <Button variant="outline" onClick={() => setStep(2)} className="gap-2">
              <ChevronLeft className="h-4 w-4" /> Back
            </Button>
            <Button disabled={!selectedTeamIdx || isProcessing} onClick={handleCreateLeague} className="gap-2">
              {isProcessing ? (
                <><Loader2 className="h-4 w-4 animate-spin" /> Creating League...</>
              ) : (
                <>Create League <ChevronRight className="h-4 w-4" /></>
              )}
            </Button>
          </div>
        </div>
      )}

      {/* ═══ STEP 4: Roster Setup ═══ */}
      {step === 4 && !setupComplete && (
        <div className="rounded-xl bg-[var(--bg-surface)] p-8">
          <div className="flex items-center gap-3 mb-2">
            <Users className="h-6 w-6 text-purple-400" />
            <h2 className="font-display text-2xl">Build Your Rosters</h2>
          </div>
          <p className="mb-6 text-sm text-[var(--text-secondary)]">
            Your league and {teamCount} teams are created. Now choose how to fill the rosters.
          </p>

          <div className="grid gap-4 sm:grid-cols-2">
            <button
              onClick={() => setRosterMode('generate')}
              className={`rounded-xl border p-6 text-left transition-all ${
                rosterMode === 'generate'
                  ? 'border-green-500/30 bg-green-500/5 ring-1 ring-green-500/20'
                  : 'border-white/5 bg-[var(--bg-elevated)] hover:border-white/10'
              }`}
            >
              <div className="flex items-center gap-3 mb-3">
                <div className={`flex h-10 w-10 items-center justify-center rounded-lg ${
                  rosterMode === 'generate' ? 'bg-green-500/10' : 'bg-white/5'
                }`}>
                  <Users className={`h-5 w-5 ${rosterMode === 'generate' ? 'text-green-400' : 'text-[var(--text-muted)]'}`} />
                </div>
                <h3 className={`font-display text-lg ${rosterMode === 'generate' ? 'text-green-400' : ''}`}>
                  Generate Random Rosters
                </h3>
              </div>
              <p className="text-sm text-[var(--text-secondary)] leading-relaxed">
                Create unique fictional players for all teams with randomized ratings, blueprints, edges, and instincts.
                Also generates a pool of 150 free agents. Ready to play immediately.
              </p>
              <div className="mt-4 flex flex-wrap gap-2">
                <Badge variant="outline" className="text-[10px]">~53 players per team</Badge>
                <Badge variant="outline" className="text-[10px]">150 free agents</Badge>
                <Badge variant="outline" className="text-[10px]">Full depth charts</Badge>
              </div>
            </button>

            <button
              onClick={() => setRosterMode('import')}
              className={`rounded-xl border p-6 text-left transition-all ${
                rosterMode === 'import'
                  ? 'border-blue-500/30 bg-blue-500/5 ring-1 ring-blue-500/20'
                  : 'border-white/5 bg-[var(--bg-elevated)] hover:border-white/10'
              }`}
            >
              <div className="flex items-center gap-3 mb-3">
                <div className={`flex h-10 w-10 items-center justify-center rounded-lg ${
                  rosterMode === 'import' ? 'bg-blue-500/10' : 'bg-white/5'
                }`}>
                  <Upload className={`h-5 w-5 ${rosterMode === 'import' ? 'text-blue-400' : 'text-[var(--text-muted)]'}`} />
                </div>
                <h3 className={`font-display text-lg ${rosterMode === 'import' ? 'text-blue-400' : ''}`}>
                  Import Roster CSV
                </h3>
              </div>
              <p className="text-sm text-[var(--text-secondary)] leading-relaxed">
                Import real player data from a Madden 26 roster export. All ability names are automatically converted.
                Free agents will be generated after import.
              </p>
              <div className="mt-4 flex flex-wrap gap-2">
                <Badge variant="outline" className="text-[10px]">Real player data</Badge>
                <Badge variant="outline" className="text-[10px]">Auto name conversion</Badge>
                <Badge variant="outline" className="text-[10px]">+ Free agents after</Badge>
              </div>
            </button>
          </div>

          <div className="mt-8 flex justify-end">
            {rosterMode === 'generate' ? (
              <Button onClick={handleGenerateRoster} disabled={isProcessing} className="gap-2">
                {isProcessing ? (
                  <><Loader2 className="h-4 w-4 animate-spin" /> Generating Players...</>
                ) : (
                  <>Generate All Rosters <ChevronRight className="h-4 w-4" /></>
                )}
              </Button>
            ) : (
              <Button onClick={() => navigate('/roster-import')} className="gap-2">
                Go to Roster Import <ChevronRight className="h-4 w-4" />
              </Button>
            )}
          </div>
        </div>
      )}

      {/* ═══ DONE ═══ */}
      {setupComplete && (
        <div className="rounded-xl bg-[var(--bg-surface)] p-8 text-center">
          <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-500/10 mb-4">
            <Check className="h-8 w-8 text-green-400" />
          </div>
          <h2 className="font-display text-3xl text-green-400">Franchise Ready</h2>
          <p className="mt-2 text-sm text-[var(--text-secondary)]">
            Your league, teams, rosters, and free agent pool are all set. Time to coach.
          </p>
          <Button onClick={() => { window.location.href = '/'; }} className="mt-6 gap-2">
            <Trophy className="h-4 w-4" /> Start Coaching
          </Button>
        </div>
      )}
    </div>
  );
}
