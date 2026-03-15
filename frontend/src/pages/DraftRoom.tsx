import { useState, useEffect, useMemo, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useDraftClass, useDraftBoard, useMyDraftPicks, useScoutProspect, useDraftPick, useDraftState, useRoster } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { TeamLogo } from '@/components/TeamLogo';
import {
  PageLayout, PageHeader, Section, StatCard, DataTable,
  ActionButton, EmptyBlock, RatingBadge, SidePanel,
} from '@/components/ui/sports-ui';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { motion, AnimatePresence } from 'framer-motion';
import {
  GraduationCap, Search, Check, Target, ClipboardList, Trophy, Radio,
  ChevronDown, ChevronUp, Star, Zap, Eye, Timer, ArrowRight,
  ChevronLeft, ChevronRight,
} from 'lucide-react';
import { toast } from 'sonner';
import type { DraftProspect, DraftPick, ScoutResult, DraftStatePick } from '@/api/client';

// ─── Constants ───────────────────────────────────────────
const PICK_TIMER_SECONDS = 300;
const allPositions = ['QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C', 'DE', 'DT', 'LB', 'CB', 'S', 'K', 'P', 'LS'];
const posColors: Record<string, string> = {
  QB: '#e11d48', RB: '#16a34a', WR: '#2563eb', TE: '#7c3aed',
  OT: '#ca8a04', OG: '#ca8a04', C: '#ca8a04',
  DE: '#ea580c', DT: '#ea580c', LB: '#dc2626',
  CB: '#0891b2', S: '#0891b2',
  K: '#6b7280', P: '#6b7280', LS: '#6b7280',
};

// ─── Shared: Scout Report Dialog ─────────────────────────
function ScoutReportDialog({ result, prospect, open, onClose, variant = 'default' }: {
  result: ScoutResult | null;
  prospect: DraftProspect | null;
  open: boolean;
  onClose: () => void;
  variant?: 'default' | 'dark';
}) {
  if (!result || !prospect) return null;
  const strengths = result.strengths ?? [];
  const weaknesses = result.weaknesses ?? [];
  const isDark = variant === 'dark';

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className={`max-w-lg ${isDark ? 'bg-[#0f1320] border-white/10 text-white' : 'bg-[var(--bg-surface)] border-[var(--border)]'}`}>
        <DialogHeader>
          <DialogTitle className={`font-display text-xl ${isDark ? 'text-white' : ''}`}>
            Scout Report: {prospect.first_name} {prospect.last_name}
          </DialogTitle>
        </DialogHeader>
        <div className="space-y-4">
          <div className={`flex items-center gap-4 rounded-lg p-4 ${isDark ? 'bg-white/5 border border-white/10' : 'bg-[var(--bg-primary)] border border-[var(--border)]'}`}>
            <div>
              <p className={`font-semibold ${isDark ? 'text-white' : ''}`}>{prospect.first_name} {prospect.last_name}</p>
              <p className={`text-xs ${isDark ? 'text-white/50' : 'text-[var(--text-secondary)]'}`}>
                {prospect.position} | {prospect.college} | Age {prospect.age}
              </p>
            </div>
            <div className="ml-auto text-right">
              <p className={`text-xs ${isDark ? 'text-white/40' : 'text-[var(--text-muted)]'}`}>Projected</p>
              <p className={`font-display text-lg ${isDark ? 'text-emerald-400' : 'text-[var(--accent-blue)]'}`}>Round {prospect.projected_round}</p>
            </div>
          </div>
          {isDark ? (
            <div className="grid grid-cols-2 gap-3">
              <div className="bg-white/5 rounded-lg p-3 border border-white/5">
                <p className="text-[10px] text-white/40 uppercase">OVR Range</p>
                <p className="font-mono text-lg text-white">{result.overall_range_low ?? '?'}-{result.overall_range_high ?? '?'}</p>
              </div>
              <div className="bg-white/5 rounded-lg p-3 border border-white/5">
                <p className="text-[10px] text-white/40 uppercase">Scouted OVR</p>
                <p className="font-mono text-lg text-emerald-400">{result.scouted_overall ?? '?'}</p>
              </div>
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-4">
              <StatCard label="OVR Range" value={`${result.overall_range_low ?? '?'}-${result.overall_range_high ?? '?'}`} />
              <StatCard label="Scouted OVR" value={result.scouted_overall ?? '?'} accentColor="var(--accent-blue)" />
            </div>
          )}
          {strengths.length > 0 && (
            <div>
              <p className="text-xs font-medium text-green-400 mb-1">Strengths</p>
              <ul className="space-y-1">
                {strengths.map((s: string, i: number) => (
                  <li key={i} className={`flex items-center gap-2 text-sm ${isDark ? 'text-white/70' : 'text-[var(--text-secondary)]'}`}>
                    <Check className="h-3 w-3 text-green-400 shrink-0" /> {s}
                  </li>
                ))}
              </ul>
            </div>
          )}
          {weaknesses.length > 0 && (
            <div>
              <p className="text-xs font-medium text-red-400 mb-1">Weaknesses</p>
              <ul className="space-y-1">
                {weaknesses.map((w: string, i: number) => (
                  <li key={i} className={`flex items-center gap-2 text-sm ${isDark ? 'text-white/70' : 'text-[var(--text-secondary)]'}`}>
                    <Target className="h-3 w-3 text-red-400 shrink-0" /> {w}
                  </li>
                ))}
              </ul>
            </div>
          )}
          {strengths.length === 0 && weaknesses.length === 0 && (
            <p className={`text-sm text-center py-4 ${isDark ? 'text-white/30' : 'text-[var(--text-muted)]'}`}>
              Scouting data is limited. Continue scouting for more detail.
            </p>
          )}
        </div>
        <DialogFooter>
          {isDark ? (
            <button onClick={onClose} className="px-4 py-2 rounded bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition-all">Close</button>
          ) : (
            <ActionButton variant="secondary" onClick={onClose}>Close</ActionButton>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ═══════════════════════════════════════════════════════════
// SCOUTING BOARD — default year-round view
// ═══════════════════════════════════════════════════════════
function ScoutingBoard({ onEnterLiveDraft, draftIsLive }: {
  onEnterLiveDraft: () => void;
  draftIsLive: boolean;
}) {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const team = useAuthStore((s) => s.team);
  const [posFilter, setPosFilter] = useState('all');
  const [selectedProspect, setSelectedProspect] = useState<DraftProspect | null>(null);
  const [selectedPick, setSelectedPick] = useState<DraftPick | null>(null);
  const [scoutReport, setScoutReport] = useState<{ result: ScoutResult; prospect: DraftProspect } | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);

  const { data: budgetData } = useQuery({
    queryKey: ['scoutingBudget'],
    queryFn: () => api.get<{ used_this_week: number; remaining: number; per_week: number; total_scouted: number }>('/draft/budget'),
  });
  const queryPos = posFilter === 'all' ? undefined : posFilter;
  const { data: draftClass } = useDraftClass();
  const { data: board, isLoading } = useDraftBoard(queryPos);
  const { data: myPicks } = useMyDraftPicks();
  const { data: roster } = useRoster(team?.id);
  const scoutMut = useScoutProspect();
  const draftMut = useDraftPick();

  const availablePicks = (myPicks ?? []).filter((p) => !p.is_used);
  const scoutsRemaining = budgetData?.remaining ?? 0;
  const budgetExhausted = scoutsRemaining <= 0;

  const starterMap = useMemo(() => {
    const map: Record<string, { name: string; ovr: number }> = {};
    (roster?.active ?? []).forEach((p: any) => {
      if (!map[p.position] || p.overall_rating > map[p.position].ovr) {
        map[p.position] = { name: `${p.first_name} ${p.last_name}`, ovr: p.overall_rating };
      }
    });
    return map;
  }, [roster]);

  function handleScout(prospect: DraftProspect) {
    scoutMut.mutate(prospect.id, {
      onSuccess: (result) => {
        setScoutReport({ result, prospect });
        const remaining = result?.scouts_remaining ?? 0;
        toast.success(`Scouted ${prospect.first_name} ${prospect.last_name} — ${remaining} reports remaining this week`);
        qc.invalidateQueries({ queryKey: ['scoutingBudget'] });
      },
      onError: (err) => toast.error(err.message),
    });
  }

  function handleDraft() {
    if (!selectedPick || !selectedProspect) return;
    draftMut.mutate(
      { pickId: selectedPick.id, prospectId: selectedProspect.id },
      {
        onSuccess: (result) => {
          toast.success(result.message || `Drafted ${selectedProspect.first_name} ${selectedProspect.last_name}!`);
          setConfirmOpen(false);
          setSelectedProspect(null);
          setSelectedPick(null);
        },
        onError: (err) => toast.error(err.message),
      },
    );
  }

  if (isLoading) {
    return (
      <PageLayout>
        <div className="flex h-64 items-center justify-center">
          <GraduationCap className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
          <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading draft board...</p>
        </div>
      </PageLayout>
    );
  }

  const boardData = board ?? [];
  const availableCount = boardData.filter((p) => !p.is_drafted).length;

  const draftColumns = [
    {
      key: 'rank', label: '#', width: 'w-10',
      render: (_row: DraftProspect, index: number) => (
        <span className="font-mono text-xs text-[var(--text-muted)]">{index + 1}</span>
      ),
    },
    {
      key: 'name', label: 'Name',
      render: (prospect: DraftProspect) => {
        const isDrafted = !!prospect.is_drafted;
        const starter = starterMap[prospect.position];
        return (
          <div>
            <div className="flex items-center gap-2">
              <span className="cursor-pointer hover:text-[var(--accent-blue)] transition-colors font-medium"
                onClick={(e) => { e.stopPropagation(); navigate(`/prospect/${prospect.id}`); }}>
                {prospect.first_name} {prospect.last_name}
              </span>
              {isDrafted && <span className="text-[8px] font-semibold bg-red-500/10 text-red-400 border border-red-500/30 rounded px-1.5 py-0.5">DRAFTED</span>}
              {prospect.scouted_overall && prospect.tier === 'Generational' && !isDrafted && (
                <span className="text-[8px] font-semibold bg-yellow-500/15 text-yellow-400 border border-yellow-500/25 rounded px-1.5 py-0.5">GENERATIONAL</span>
              )}
              {prospect.scouted_overall && prospect.tier === 'Blue Chip' && !isDrafted && (
                <span className="text-[8px] font-semibold bg-blue-500/15 text-blue-400 border border-blue-500/25 rounded px-1.5 py-0.5">BLUE CHIP</span>
              )}
              {prospect.injury_flag && <span className="text-[8px] font-semibold bg-red-500/10 text-red-400 border border-red-500/30 rounded px-1.5 py-0.5">INJ</span>}
            </div>
            {starter && <p className="text-[10px] text-[var(--text-muted)] mt-0.5">Your {prospect.position}: {starter.name} ({starter.ovr})</p>}
            {prospect.latest_performance && !isDrafted && (
              <p className="text-[10px] text-[var(--text-secondary)] mt-0.5 max-w-[300px] truncate" title={prospect.latest_performance}>{prospect.latest_performance}</p>
            )}
          </div>
        );
      },
    },
    {
      key: 'position', label: 'Pos',
      render: (prospect: DraftProspect) => (
        <span className="inline-flex items-center justify-center w-9 h-6 rounded text-[10px] font-bold uppercase bg-[var(--bg-elevated)] text-[var(--text-secondary)] border border-[var(--border)]">{prospect.position}</span>
      ),
    },
    { key: 'college', label: 'College', render: (p: DraftProspect) => <span className="text-sm text-[var(--text-secondary)]">{p.college}</span> },
    { key: 'age', label: 'Age', stat: true, render: (p: DraftProspect) => <span className="text-sm">{p.age}</span> },
    { key: 'projected_round', label: 'Proj', render: (p: DraftProspect) => <span className="font-mono text-xs font-semibold">Rd {p.projected_round}</span> },
    {
      key: 'stock_trend', label: 'Trend',
      render: (p: DraftProspect) => (
        <span className={`text-sm font-semibold ${p.stock_trend === 'rising' || p.stock_trend === 'up' ? 'text-green-400' : p.stock_trend === 'falling' || p.stock_trend === 'down' ? 'text-red-400' : 'text-[var(--text-muted)]'}`}>
          {p.stock_trend === 'rising' ? '^^' : p.stock_trend === 'up' ? '^' : p.stock_trend === 'falling' ? 'vv' : p.stock_trend === 'down' ? 'v' : '--'}
        </span>
      ),
    },
    {
      key: 'scouted', label: 'Scouted',
      render: (prospect: DraftProspect) => {
        if (prospect.is_drafted) return null;
        if (prospect.scouted_overall) {
          return (
            <div className="flex items-center gap-2">
              <RatingBadge rating={prospect.scouted_overall} size="sm" />
              {prospect.scouted && (
                <button className="text-[10px] text-[var(--text-muted)] hover:text-[var(--accent-blue)] transition-colors"
                  onClick={(e) => { e.stopPropagation(); handleScout(prospect); }}
                  disabled={scoutMut.isPending || budgetExhausted}>
                  <Search className="h-3 w-3 inline" />
                </button>
              )}
            </div>
          );
        }
        return (
          <button className="text-xs text-[var(--accent-blue)] hover:underline disabled:text-[var(--text-muted)] disabled:no-underline"
            onClick={(e) => { e.stopPropagation(); handleScout(prospect); }}
            disabled={scoutMut.isPending || budgetExhausted}>
            Scout
          </button>
        );
      },
    },
  ];

  return (
    <PageLayout>
      <PageHeader title="Draft Room" icon={GraduationCap}
        subtitle={draftClass ? `${draftClass.year} Draft Class — ${draftClass.total_prospects} prospects (${draftClass.strength})` : 'Scout and draft the next generation'} />

      {/* Live Draft Banner */}
      {draftIsLive && (
        <div className="mb-6">
          <button onClick={onEnterLiveDraft}
            className="w-full flex items-center justify-between gap-4 rounded-lg border border-red-500/30 bg-red-500/5 hover:bg-red-500/10 px-6 py-4 transition-all group">
            <div className="flex items-center gap-3">
              <div className="relative">
                <Radio className="h-5 w-5 text-red-500" />
                <span className="absolute -top-0.5 -right-0.5 h-2 w-2 rounded-full bg-red-500 animate-ping" />
              </div>
              <div className="text-left">
                <p className="font-display text-sm font-bold text-red-400">DRAFT IS LIVE</p>
                <p className="text-xs text-[var(--text-muted)]">Enter the live draft broadcast to make your picks</p>
              </div>
            </div>
            <span className="flex items-center gap-2 text-sm font-bold text-red-400 group-hover:text-red-300 transition-colors">
              Enter Live Draft <ArrowRight className="h-4 w-4" />
            </span>
          </button>
        </div>
      )}

      {/* Scouting Budget */}
      <Section title="Scouting Reports" accentColor={budgetExhausted ? 'var(--accent-red, #ef4444)' : 'var(--accent-blue)'}>
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <ClipboardList className="h-4 w-4 text-[var(--accent-blue)]" />
              <span className="font-display text-sm">Scouting Reports</span>
            </div>
            <span className={`font-mono text-sm font-semibold ${budgetExhausted ? 'text-red-400' : 'text-[var(--text-primary)]'}`}>
              {scoutsRemaining} of {budgetData?.per_week ?? 10} remaining this week
            </span>
          </div>
          <div className="mt-2 h-1.5 w-full rounded-full bg-[var(--bg-primary)] overflow-hidden">
            <div className={`h-full rounded-full transition-all duration-300 ${budgetExhausted ? 'bg-red-500' : scoutsRemaining <= 3 ? 'bg-yellow-500' : 'bg-[var(--accent-blue)]'}`}
              style={{ width: `${Math.min(100, ((budgetData?.used_this_week ?? 0) / (budgetData?.per_week ?? 10)) * 100)}%` }} />
          </div>
          {budgetExhausted && <p className="text-xs text-red-400 mt-1.5">All scouting reports used. {budgetData?.per_week ?? 10} new reports unlock next week.</p>}
          {!budgetExhausted && scoutsRemaining <= 3 && <p className="text-xs text-yellow-400 mt-1.5">{scoutsRemaining} report{scoutsRemaining === 1 ? '' : 's'} remaining — scout wisely</p>}
        </div>
      </Section>

      <div className="grid gap-6 lg:grid-cols-4 mt-6">
        <div className="lg:col-span-3 space-y-4">
          <div className="flex items-center gap-2">
            <Select value={posFilter} onValueChange={(v) => v && setPosFilter(v)}>
              <SelectTrigger className="w-32"><SelectValue placeholder="Position" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Positions</SelectItem>
                {allPositions.map((p) => <SelectItem key={p} value={p}>{p}</SelectItem>)}
              </SelectContent>
            </Select>
            <span className="text-xs text-[var(--text-muted)]">{availableCount} available</span>
          </div>

          {boardData.length === 0 ? (
            <EmptyBlock icon={GraduationCap} title="No prospects available"
              description={posFilter !== 'all' ? 'No prospects at this position. Try a different filter.' : 'Generate a draft class to start scouting.'}
              action={posFilter === 'all' ? {
                label: 'Generate Draft Class',
                onClick: async () => {
                  try {
                    await api.post('/draft/generate-prospects');
                    qc.invalidateQueries({ queryKey: ['draftBoard'] });
                    qc.invalidateQueries({ queryKey: ['draftClass'] });
                    qc.invalidateQueries({ queryKey: ['scoutingBudget'] });
                    toast.success('Draft class generated!');
                  } catch (e) { toast.error(e instanceof Error ? e.message : 'Failed to generate prospects'); }
                },
              } : undefined} />
          ) : (
            <DataTable columns={draftColumns} data={boardData} rowKey={(p) => p.id}
              accentColor="var(--accent-blue)" emptyMessage="No prospects available"
              onRowClick={(p) => { if (!p.is_drafted) setSelectedProspect(p); }} />
          )}
        </div>

        {/* Sidebar */}
        <div className="space-y-4">
          <SidePanel title="My Picks" accentColor="var(--accent-blue)">
            {availablePicks.length === 0 ? (
              <p className="text-sm text-[var(--text-muted)] py-4 text-center">No picks available</p>
            ) : (
              <div className="space-y-2">
                {availablePicks.map((pick) => {
                  const isSelected = selectedPick?.id === pick.id;
                  return (
                    <button key={pick.id} onClick={() => setSelectedPick(pick)}
                      className={`flex w-full items-center justify-between rounded-lg border px-3 py-2 text-left transition-colors ${isSelected ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10' : 'border-[var(--border)] bg-[var(--bg-primary)] hover:bg-[var(--bg-elevated)]'}`}>
                      <div>
                        <p className="font-display text-sm">Round {pick.round}{pick.via_team && <span className="ml-1.5 text-[10px] font-normal text-[var(--accent-gold)]">via {pick.via_team}</span>}</p>
                        <p className="text-xs text-[var(--text-muted)]">Pick #{pick.pick_number}</p>
                      </div>
                      {isSelected && <Check className="h-4 w-4 text-[var(--accent-blue)]" />}
                    </button>
                  );
                })}
              </div>
            )}
          </SidePanel>

          {selectedProspect && selectedPick && (
            <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.3 }}>
              <div className="rounded-lg border border-[var(--accent-blue)] bg-[var(--accent-blue)]/5 overflow-hidden">
                <div className="h-[2px] w-full bg-[var(--accent-blue)]" />
                <div className="p-4 space-y-3">
                  <div className="flex items-center gap-2">
                    <Trophy className="h-4 w-4 text-[var(--accent-gold)]" />
                    <p className="font-display text-sm">Ready to Draft</p>
                  </div>
                  <div className="rounded-lg bg-[var(--bg-primary)] border border-[var(--border)] p-3">
                    <p className="font-semibold text-sm">{selectedProspect.first_name} {selectedProspect.last_name}</p>
                    <p className="text-xs text-[var(--text-secondary)]">{selectedProspect.position} | {selectedProspect.college}</p>
                  </div>
                  <p className="text-xs text-[var(--text-muted)] text-center">with Round {selectedPick.round}, Pick #{selectedPick.pick_number}</p>
                  <ActionButton fullWidth icon={GraduationCap} onClick={() => setConfirmOpen(true)}>Draft Player</ActionButton>
                </div>
              </div>
            </motion.div>
          )}

          {draftClass && (
            <SidePanel title="Class Overview" accentColor="var(--accent-blue)">
              <div className="space-y-2">
                <div className="flex justify-between text-sm"><span className="text-[var(--text-muted)]">Year</span><span className="font-semibold">{draftClass.year}</span></div>
                <div className="flex justify-between text-sm"><span className="text-[var(--text-muted)]">Total Prospects</span><span className="font-semibold">{draftClass.total_prospects}</span></div>
                <div className="flex justify-between text-sm">
                  <span className="text-[var(--text-muted)]">Class Strength</span>
                  <span className="inline-flex items-center justify-center rounded text-[10px] font-bold uppercase bg-[var(--bg-elevated)] text-[var(--text-secondary)] border border-[var(--border)] px-1.5 py-0.5">{draftClass.strength}</span>
                </div>
                <div className="my-2 h-px bg-[var(--border)]" />
                <p className="text-xs font-medium text-[var(--text-muted)]">Top Positions</p>
                <div className="flex flex-wrap gap-1">
                  {Object.entries(draftClass.top_positions ?? {}).map(([pos, count]) => (
                    <span key={pos} className="inline-flex items-center justify-center rounded text-[10px] font-bold uppercase bg-[var(--bg-elevated)] text-[var(--text-secondary)] border border-[var(--border)] px-1.5 py-0.5">{pos}: {count as number}</span>
                  ))}
                </div>
              </div>
            </SidePanel>
          )}
        </div>
      </div>

      <ScoutReportDialog result={scoutReport?.result ?? null} prospect={scoutReport?.prospect ?? null}
        open={!!scoutReport} onClose={() => setScoutReport(null)} />

      <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <DialogContent className="bg-[var(--bg-surface)] border-[var(--border)]">
          <DialogHeader><DialogTitle className="font-display text-xl">Confirm Draft Pick</DialogTitle></DialogHeader>
          <div className="space-y-3">
            <p className="text-sm text-[var(--text-secondary)]">
              Are you sure you want to select <span className="font-semibold text-[var(--text-primary)]">{selectedProspect?.first_name} {selectedProspect?.last_name}</span> ({selectedProspect?.position}) with Round {selectedPick?.round}, Pick #{selectedPick?.pick_number}?
            </p>
            <p className="text-xs text-[var(--text-muted)]">This action cannot be undone.</p>
          </div>
          <DialogFooter>
            <ActionButton variant="secondary" onClick={() => setConfirmOpen(false)}>Cancel</ActionButton>
            <ActionButton icon={GraduationCap} onClick={handleDraft} disabled={draftMut.isPending}>
              {draftMut.isPending ? 'Drafting...' : 'Confirm Pick'}
            </ActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </PageLayout>
  );
}

// ═══════════════════════════════════════════════════════════
// LIVE DRAFT BROADCAST VIEW
// ═══════════════════════════════════════════════════════════

function usePickTimer(isOnClock: boolean) {
  const [seconds, setSeconds] = useState(PICK_TIMER_SECONDS);
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  useEffect(() => {
    if (isOnClock) {
      setSeconds(PICK_TIMER_SECONDS);
      intervalRef.current = setInterval(() => setSeconds((s) => (s > 0 ? s - 1 : 0)), 1000);
    } else {
      if (intervalRef.current) clearInterval(intervalRef.current);
    }
    return () => { if (intervalRef.current) clearInterval(intervalRef.current); };
  }, [isOnClock]);
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return { display: `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}` };
}

function ProspectSpotlight({ prospect, pick, onDraft, onScout, scoutingDisabled, draftDisabled }: {
  prospect: DraftProspect | null; pick: DraftStatePick | null;
  onDraft: () => void; onScout: () => void; scoutingDisabled: boolean; draftDisabled: boolean;
}) {
  if (!prospect && !pick) {
    return (
      <div className="flex flex-col items-center justify-center h-full text-center px-8">
        <GraduationCap className="h-16 w-16 text-white/20 mb-4" />
        <h2 className="font-display text-2xl text-white/40 mb-2">Select a Prospect</h2>
        <p className="text-white/30 text-sm max-w-md">Click on any prospect from the draft board below, or wait for the AI to make their pick.</p>
      </div>
    );
  }

  if (pick && !prospect) {
    return (
      <div className="flex flex-col justify-center h-full px-8 lg:px-12">
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} key={pick.id}>
          <p className="text-white/60 font-display text-sm tracking-[0.3em] uppercase mb-2">Round {pick.round} &middot; Pick #{pick.pick_number}</p>
          <h1 className="font-display text-4xl lg:text-5xl text-white font-bold tracking-tight mb-1">{pick.prospect_name || 'Unknown'}</h1>
          <div className="flex items-center gap-3 mt-3 mb-6">
            {pick.prospect_position && <span className="px-3 py-1 rounded text-xs font-bold uppercase text-white" style={{ background: posColors[pick.prospect_position] || '#6b7280' }}>{pick.prospect_position}</span>}
            {pick.prospect_college && <span className="text-white/50 text-sm">{pick.prospect_college}</span>}
          </div>
          <div className="flex items-center gap-3">
            <TeamLogo abbreviation={pick.team_abbreviation} primaryColor={pick.team_primary_color} secondaryColor={pick.team_secondary_color} size="md" />
            <div>
              <p className="text-white/80 text-sm font-semibold">{pick.team_city} {pick.team_name}</p>
              <p className="text-white/40 text-xs">Selected this player</p>
            </div>
          </div>
        </motion.div>
      </div>
    );
  }

  if (!prospect) return null;

  return (
    <div className="flex flex-col justify-center h-full px-8 lg:px-12">
      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} key={prospect.id}>
        <p className="text-white/60 font-display text-sm tracking-[0.3em] uppercase mb-2">{prospect.position} &middot; {prospect.college} &middot; Age {prospect.age}</p>
        <h1 className="font-display text-4xl lg:text-6xl text-white font-bold tracking-tight leading-none mb-2">{prospect.first_name}<br />{prospect.last_name}</h1>
        {prospect.scouted_overall && (
          <div className="flex items-center gap-4 mt-4 mb-2">
            <div className="bg-white/10 backdrop-blur rounded-lg px-4 py-2 border border-white/10">
              <p className="text-[10px] text-white/40 uppercase tracking-wider">Scouted OVR</p>
              <p className="font-display text-2xl text-white">{prospect.scouted_overall}</p>
            </div>
            {prospect.tier && (
              <div className="bg-white/10 backdrop-blur rounded-lg px-4 py-2 border border-white/10">
                <p className="text-[10px] text-white/40 uppercase tracking-wider">Tier</p>
                <p className={`font-display text-lg ${prospect.tier === 'Generational' ? 'text-yellow-400' : prospect.tier === 'Blue Chip' ? 'text-blue-400' : 'text-white'}`}>{prospect.tier}</p>
              </div>
            )}
            <div className="bg-white/10 backdrop-blur rounded-lg px-4 py-2 border border-white/10">
              <p className="text-[10px] text-white/40 uppercase tracking-wider">Projected</p>
              <p className="font-display text-lg text-white">Round {prospect.projected_round}</p>
            </div>
          </div>
        )}
        {prospect.latest_performance && <p className="text-white/50 text-sm mt-4 max-w-lg leading-relaxed">{prospect.latest_performance}</p>}
        <div className="flex items-center gap-3 mt-6">
          {!prospect.is_drafted && (
            <>
              <button onClick={onScout} disabled={scoutingDisabled}
                className="flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition-all disabled:opacity-30 disabled:cursor-not-allowed border border-white/10">
                <Eye className="h-4 w-4" /> {prospect.scouted ? 'Re-Scout' : 'Scout'}
              </button>
              <button onClick={onDraft} disabled={draftDisabled}
                className="flex items-center gap-2 px-6 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold transition-all disabled:opacity-30 disabled:cursor-not-allowed shadow-lg shadow-emerald-900/30">
                <GraduationCap className="h-4 w-4" /> Draft Player
              </button>
            </>
          )}
        </div>
      </motion.div>
    </div>
  );
}

function PickRow({ pick, isActive, isUserTeam, onClick }: {
  pick: DraftStatePick; isActive: boolean; isUserTeam: boolean; onClick: () => void;
}) {
  return (
    <button onClick={onClick}
      className={`w-full text-left transition-all duration-200 ${
        isActive ? 'bg-red-600/90 border-l-4 border-l-red-400'
        : pick.is_used ? 'bg-white/5 hover:bg-white/10 border-l-4 border-l-transparent'
        : isUserTeam ? 'bg-blue-900/30 hover:bg-blue-900/50 border-l-4 border-l-blue-500'
        : 'bg-transparent hover:bg-white/5 border-l-4 border-l-transparent'
      }`}>
      <div className="flex items-center gap-3 px-3 py-2.5">
        <span className={`font-mono text-sm font-bold w-6 text-center shrink-0 ${isActive ? 'text-white' : 'text-white/40'}`}>{pick.pick_number}</span>
        <TeamLogo abbreviation={pick.team_abbreviation} primaryColor={pick.team_primary_color} secondaryColor={pick.team_secondary_color} size="sm" />
        <div className="min-w-0 flex-1">
          <p className={`text-xs font-semibold uppercase tracking-wide truncate ${isActive ? 'text-white' : 'text-white/70'}`}>{pick.team_city} {pick.team_name}</p>
          {pick.is_used && pick.prospect_name ? (
            <div className="flex items-center gap-1.5">
              <span className="text-[11px] text-white/90 font-semibold truncate">{pick.prospect_name}</span>
              {pick.prospect_position && <span className="text-[9px] font-bold px-1 rounded" style={{ background: posColors[pick.prospect_position] || '#6b7280', color: '#fff' }}>{pick.prospect_position}</span>}
            </div>
          ) : isActive ? (
            <span className="text-[11px] text-white/90 font-bold animate-pulse">ON THE CLOCK</span>
          ) : <span className="text-[10px] text-white/30">—</span>}
        </div>
        {isUserTeam && !pick.is_used && !isActive && <span className="text-[9px] font-bold text-blue-400 bg-blue-500/20 rounded px-1.5 py-0.5 shrink-0">YOU</span>}
      </div>
    </button>
  );
}

function LiveDraftBoardPanel({ prospects, posFilter, onPosFilterChange, onSelectProspect, selectedId, onScout, scoutingDisabled }: {
  prospects: DraftProspect[]; posFilter: string; onPosFilterChange: (p: string) => void;
  onSelectProspect: (p: DraftProspect) => void; selectedId: number | null;
  onScout: (p: DraftProspect) => void; scoutingDisabled: boolean;
}) {
  const positions = ['ALL', 'QB', 'RB', 'WR', 'TE', 'OL', 'DL', 'LB', 'DB', 'K'];
  const posGroups: Record<string, string[]> = { ALL: [], OL: ['OT', 'OG', 'C'], DL: ['DE', 'DT'], DB: ['CB', 'S'] };
  const filtered = useMemo(() => {
    if (posFilter === 'ALL') return prospects.filter((p) => !p.is_drafted);
    const group = posGroups[posFilter];
    if (group?.length) return prospects.filter((p) => !p.is_drafted && group.includes(p.position));
    return prospects.filter((p) => !p.is_drafted && p.position === posFilter);
  }, [prospects, posFilter]);

  return (
    <div className="bg-[#0a0e17]/95 backdrop-blur border-t border-white/10">
      <div className="flex items-center gap-1 px-4 py-2 border-b border-white/5 overflow-x-auto">
        <span className="text-[10px] text-white/30 uppercase tracking-widest mr-2 shrink-0">Filter</span>
        {positions.map((pos) => (
          <button key={pos} onClick={() => onPosFilterChange(pos)}
            className={`px-3 py-1 rounded text-xs font-bold uppercase transition-all shrink-0 ${posFilter === pos ? 'bg-white/15 text-white' : 'text-white/40 hover:text-white/70 hover:bg-white/5'}`}>{pos}</button>
        ))}
        <span className="ml-auto text-[10px] text-white/25 shrink-0">{filtered.length} available</span>
      </div>
      <div className="max-h-[280px] overflow-y-auto">
        <table className="w-full">
          <thead>
            <tr className="text-[10px] text-white/30 uppercase tracking-widest border-b border-white/5">
              <th className="text-left py-2 px-4 w-8">#</th><th className="text-left py-2">Name</th>
              <th className="text-left py-2 w-14">Pos</th><th className="text-left py-2">College</th>
              <th className="text-center py-2 w-14">Age</th><th className="text-center py-2 w-14">Proj</th>
              <th className="text-center py-2 w-16">Trend</th><th className="text-center py-2 w-20">Scout</th>
              <th className="text-center py-2 w-16"></th>
            </tr>
          </thead>
          <tbody>
            {filtered.slice(0, 50).map((p, i) => (
              <tr key={p.id} onClick={() => onSelectProspect(p)}
                className={`cursor-pointer transition-colors border-b border-white/[0.03] ${selectedId === p.id ? 'bg-white/10' : 'hover:bg-white/5'}`}>
                <td className="py-2 px-4 font-mono text-xs text-white/30">{i + 1}</td>
                <td className="py-2">
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-semibold text-white">{p.first_name} {p.last_name}</span>
                    {p.tier === 'Generational' && <Star className="h-3 w-3 text-yellow-400 fill-yellow-400" />}
                    {p.tier === 'Blue Chip' && <Zap className="h-3 w-3 text-blue-400" />}
                    {p.injury_flag && <span className="text-[8px] font-bold text-red-400 bg-red-500/15 rounded px-1">INJ</span>}
                  </div>
                </td>
                <td className="py-2"><span className="text-[10px] font-bold px-1.5 py-0.5 rounded text-white" style={{ background: posColors[p.position] || '#6b7280' }}>{p.position}</span></td>
                <td className="py-2 text-sm text-white/50">{p.college}</td>
                <td className="py-2 text-sm text-white/50 text-center">{p.age}</td>
                <td className="py-2 text-center"><span className="font-mono text-xs text-white/60">Rd {p.projected_round}</span></td>
                <td className="py-2 text-center">
                  <span className={`text-xs font-bold ${p.stock_trend === 'rising' || p.stock_trend === 'up' ? 'text-emerald-400' : p.stock_trend === 'falling' || p.stock_trend === 'down' ? 'text-red-400' : 'text-white/30'}`}>
                    {p.stock_trend === 'rising' ? '▲▲' : p.stock_trend === 'up' ? '▲' : p.stock_trend === 'falling' ? '▼▼' : p.stock_trend === 'down' ? '▼' : '—'}
                  </span>
                </td>
                <td className="py-2 text-center">
                  {p.scouted_overall ? (
                    <span className={`font-mono text-sm font-bold ${p.scouted_overall >= 80 ? 'text-emerald-400' : p.scouted_overall >= 70 ? 'text-blue-400' : p.scouted_overall >= 60 ? 'text-yellow-400' : 'text-white/50'}`}>{p.scouted_overall}</span>
                  ) : <span className="text-[10px] text-white/20">—</span>}
                </td>
                <td className="py-2 text-center">
                  <button onClick={(e) => { e.stopPropagation(); onScout(p); }} disabled={scoutingDisabled && !p.scouted}
                    className="text-[10px] font-bold text-white/40 hover:text-white bg-white/5 hover:bg-white/10 rounded px-2 py-1 transition-all disabled:opacity-20 disabled:cursor-not-allowed">
                    <Eye className="h-3 w-3 inline" />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {filtered.length === 0 && <div className="text-center py-8 text-white/20 text-sm">No available prospects {posFilter !== 'ALL' ? `at ${posFilter}` : ''}</div>}
      </div>
    </div>
  );
}

function LiveDraft({ onExit }: { onExit: () => void }) {
  const qc = useQueryClient();
  const team = useAuthStore((s) => s.team);
  const [posFilter, setPosFilter] = useState('ALL');
  const [selectedProspect, setSelectedProspect] = useState<DraftProspect | null>(null);
  const [selectedPick, setSelectedPick] = useState<DraftPick | null>(null);
  const [highlightedTickerPick, setHighlightedTickerPick] = useState<DraftStatePick | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [scoutReport, setScoutReport] = useState<{ result: ScoutResult; prospect: DraftProspect } | null>(null);
  const [activeRound, setActiveRound] = useState(1);
  const [boardExpanded, setBoardExpanded] = useState(false);

  const { data: draftState } = useDraftState();
  const { data: draftClass } = useDraftClass();
  const { data: board } = useDraftBoard();
  const { data: myPicks } = useMyDraftPicks();
  const { data: budgetData } = useQuery({
    queryKey: ['scoutingBudget'],
    queryFn: () => api.get<{ used_this_week: number; remaining: number; per_week: number; total_scouted: number }>('/draft/budget'),
  });
  const scoutMut = useScoutProspect();
  const draftMut = useDraftPick();

  const scoutsRemaining = budgetData?.remaining ?? 0;
  const budgetExhausted = scoutsRemaining <= 0;
  const boardData = board ?? [];
  const currentPick = draftState?.current_pick ?? null;
  const isUserOnClock = currentPick && team ? currentPick.team_id === team.id : false;
  const timer = usePickTimer(!!isUserOnClock);
  const availablePicks = (myPicks ?? []).filter((p) => !p.is_used);
  const totalRounds = draftState?.total_rounds ?? 7;

  const roundPicks = useMemo(() => {
    if (!draftState?.picks) return [];
    return draftState.picks.filter((p) => p.round === activeRound);
  }, [draftState?.picks, activeRound]);

  useEffect(() => {
    if (isUserOnClock && availablePicks.length > 0 && !selectedPick) setSelectedPick(availablePicks[0]);
  }, [isUserOnClock, availablePicks.length]);

  useEffect(() => { if (draftState?.round) setActiveRound(draftState.round); }, [draftState?.round]);

  function handleScout(prospect: DraftProspect) {
    scoutMut.mutate(prospect.id, {
      onSuccess: (result) => { setScoutReport({ result, prospect }); toast.success(`Scouted ${prospect.first_name} ${prospect.last_name}`); qc.invalidateQueries({ queryKey: ['scoutingBudget'] }); },
      onError: (err) => toast.error(err.message),
    });
  }

  function handleDraft() {
    if (!selectedPick || !selectedProspect) return;
    draftMut.mutate({ pickId: selectedPick.id, prospectId: selectedProspect.id }, {
      onSuccess: (result) => {
        toast.success(result.message || `Drafted ${selectedProspect.first_name} ${selectedProspect.last_name}!`);
        setConfirmOpen(false); setSelectedProspect(null); setSelectedPick(null);
        qc.invalidateQueries({ queryKey: ['draftState'] });
      },
      onError: (err) => toast.error(err.message),
    });
  }

  async function handleAutoPick() {
    try {
      await api.post('/draft/auto-pick');
      toast.success('Auto-pick completed');
      qc.invalidateQueries({ queryKey: ['draftState'] });
      qc.invalidateQueries({ queryKey: ['draftBoard'] });
      qc.invalidateQueries({ queryKey: ['myDraftPicks'] });
    } catch (err) { toast.error(err instanceof Error ? err.message : 'Auto-pick failed'); }
  }

  return (
    <div className="fixed inset-0 flex flex-col bg-[#0a0e17] overflow-hidden z-50">
      {/* Stadium bg */}
      <div className="absolute inset-0 z-0">
        <img src="https://images.unsplash.com/photo-1566577739112-5180d4bf9390?w=1920&q=80" alt="" className="w-full h-full object-cover" />
        <div className="absolute inset-0 bg-gradient-to-r from-[#0a0e17] via-[#0a0e17]/85 to-[#0a0e17]/60" />
        <div className="absolute inset-0 bg-gradient-to-t from-[#0a0e17] via-transparent to-[#0a0e17]/40" />
      </div>

      {/* Top bar */}
      <div className="relative z-10 flex items-center justify-between px-4 py-2 bg-black/40 backdrop-blur border-b border-white/5">
        <div className="flex items-center gap-4">
          <button onClick={onExit} className="flex items-center gap-1 px-3 py-1.5 rounded bg-white/5 hover:bg-white/10 text-white/60 hover:text-white text-xs font-semibold transition-all">
            <ChevronLeft className="h-3 w-3" /> Scout Board
          </button>
          <h1 className="font-display text-sm font-bold text-white uppercase tracking-widest">{draftClass?.year ?? ''} Draft</h1>
          <div className="flex items-center gap-1 bg-white/5 rounded-lg p-0.5">
            <button onClick={() => setActiveRound(Math.max(1, activeRound - 1))} className="p-1 text-white/40 hover:text-white transition-colors" disabled={activeRound <= 1}><ChevronLeft className="h-3 w-3" /></button>
            <span className="px-3 py-1 text-xs font-bold text-white uppercase tracking-wider">Round {activeRound}</span>
            <button onClick={() => setActiveRound(Math.min(totalRounds, activeRound + 1))} className="p-1 text-white/40 hover:text-white transition-colors" disabled={activeRound >= totalRounds}><ChevronRight className="h-3 w-3" /></button>
          </div>
          {draftState?.status && (
            <span className={`text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded ${draftState.status === 'in_progress' ? 'bg-red-500/20 text-red-400' : draftState.status === 'complete' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-white/10 text-white/40'}`}>
              {draftState.status === 'in_progress' ? 'LIVE' : draftState.status === 'complete' ? 'COMPLETE' : 'NOT STARTED'}
            </span>
          )}
        </div>
        <div className="flex items-center gap-4">
          <div className="flex items-center gap-2 text-white/40 text-xs">
            <ClipboardList className="h-3.5 w-3.5" />
            <span>Scouts: <span className={`font-mono font-bold ${budgetExhausted ? 'text-red-400' : 'text-white/80'}`}>{scoutsRemaining}</span>/{budgetData?.per_week ?? 10}</span>
          </div>
          <button onClick={() => setBoardExpanded(!boardExpanded)} className="flex items-center gap-1 px-3 py-1.5 rounded bg-white/5 text-white/60 hover:text-white text-xs font-semibold transition-all">
            {boardExpanded ? <><ChevronDown className="h-3 w-3" /> Hide Board</> : <><ChevronUp className="h-3 w-3" /> Draft Board</>}
          </button>
        </div>
      </div>

      {/* Main */}
      <div className="relative z-10 flex flex-1 min-h-0">
        {/* Left: Pick tracker */}
        <div className="w-72 shrink-0 flex flex-col bg-black/40 backdrop-blur border-r border-white/5 overflow-hidden">
          {currentPick && (
            <div className="bg-red-600 px-4 py-3">
              <div className="flex items-center justify-between mb-1">
                <div className="flex items-center gap-2">
                  <TeamLogo abbreviation={currentPick.team_abbreviation} primaryColor={currentPick.team_primary_color} secondaryColor={currentPick.team_secondary_color} size="sm" />
                  <div>
                    <p className="text-white text-xs font-bold uppercase">{currentPick.team_city} {currentPick.team_name}</p>
                    <p className="text-white/70 text-[10px]">Pick #{currentPick.pick_number}</p>
                  </div>
                </div>
                {isUserOnClock && <span className="text-[9px] font-bold bg-white/20 rounded px-1.5 py-0.5 text-white">YOUR PICK</span>}
              </div>
              <div className="flex items-center justify-center gap-2 mt-2">
                <Timer className="h-4 w-4 text-white/80" />
                <span className="font-mono text-3xl font-bold text-white tracking-wider">{timer.display}</span>
              </div>
              <p className="text-center text-[10px] text-white/60 uppercase tracking-[0.2em] mt-1">On The Clock</p>
              {isUserOnClock && (
                <button onClick={handleAutoPick} className="w-full mt-2 text-[10px] font-bold py-1.5 rounded bg-white/20 hover:bg-white/30 text-white transition-all">Auto Pick</button>
              )}
            </div>
          )}
          <div className="flex-1 overflow-y-auto">
            {roundPicks.map((pick) => (
              <PickRow key={pick.id} pick={pick} isActive={currentPick?.id === pick.id}
                isUserTeam={team ? pick.team_id === team.id : false}
                onClick={() => { if (pick.is_used) { setHighlightedTickerPick(pick); setSelectedProspect(null); } }} />
            ))}
            {roundPicks.length === 0 && <div className="text-center py-12 text-white/20 text-xs">No picks for Round {activeRound}</div>}
          </div>
        </div>

        {/* Center: Spotlight */}
        <div className="flex-1 flex flex-col min-h-0">
          <div className="flex-1 min-h-0">
            <ProspectSpotlight prospect={selectedProspect} pick={highlightedTickerPick}
              onDraft={() => { if (!selectedPick && availablePicks.length > 0) setSelectedPick(availablePicks[0]); setConfirmOpen(true); }}
              onScout={() => selectedProspect && handleScout(selectedProspect)}
              scoutingDisabled={scoutMut.isPending || budgetExhausted}
              draftDisabled={!isUserOnClock || availablePicks.length === 0} />
          </div>
          <AnimatePresence>
            {boardExpanded && (
              <motion.div initial={{ height: 0, opacity: 0 }} animate={{ height: 'auto', opacity: 1 }} exit={{ height: 0, opacity: 0 }} transition={{ duration: 0.3 }} className="overflow-hidden">
                <LiveDraftBoardPanel prospects={boardData} posFilter={posFilter} onPosFilterChange={setPosFilter}
                  onSelectProspect={(p) => { setSelectedProspect(p); setHighlightedTickerPick(null); }}
                  selectedId={selectedProspect?.id ?? null} onScout={handleScout} scoutingDisabled={scoutMut.isPending || budgetExhausted} />
              </motion.div>
            )}
          </AnimatePresence>
        </div>

        {/* Right: Your picks (when on clock) */}
        {isUserOnClock && (
          <div className="w-64 shrink-0 bg-black/40 backdrop-blur border-l border-white/5 flex flex-col overflow-hidden">
            <div className="px-4 py-3 border-b border-white/5"><h3 className="text-xs font-bold text-white/60 uppercase tracking-widest">Your Picks</h3></div>
            <div className="flex-1 overflow-y-auto p-3 space-y-2">
              {availablePicks.map((pick) => {
                const isSelected = selectedPick?.id === pick.id;
                return (
                  <button key={pick.id} onClick={() => setSelectedPick(pick)}
                    className={`w-full text-left rounded-lg border px-3 py-2 transition-all ${isSelected ? 'border-emerald-500 bg-emerald-500/10' : 'border-white/10 bg-white/5 hover:bg-white/10'}`}>
                    <p className="font-display text-sm text-white">Round {pick.round}, Pick #{pick.pick_number}</p>
                    {pick.via_team && <p className="text-[10px] text-yellow-400/70 mt-0.5">via {pick.via_team}</p>}
                    {isSelected && <Check className="h-3 w-3 text-emerald-400 mt-1" />}
                  </button>
                );
              })}
            </div>
          </div>
        )}
      </div>

      <ScoutReportDialog result={scoutReport?.result ?? null} prospect={scoutReport?.prospect ?? null}
        open={!!scoutReport} onClose={() => setScoutReport(null)} variant="dark" />

      <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <DialogContent className="bg-[#0f1320] border border-white/10 text-white">
          <DialogHeader><DialogTitle className="font-display text-xl text-white">Confirm Draft Pick</DialogTitle></DialogHeader>
          {selectedProspect && selectedPick && (
            <div className="space-y-4">
              <div className="bg-white/5 rounded-lg p-4 border border-white/10">
                <div className="flex items-center gap-3">
                  <span className="px-2 py-1 rounded text-xs font-bold text-white" style={{ background: posColors[selectedProspect.position] || '#6b7280' }}>{selectedProspect.position}</span>
                  <div>
                    <p className="font-display text-lg text-white">{selectedProspect.first_name} {selectedProspect.last_name}</p>
                    <p className="text-xs text-white/50">{selectedProspect.college} | Age {selectedProspect.age}</p>
                  </div>
                </div>
              </div>
              <p className="text-sm text-white/50 text-center">Round {selectedPick.round}, Pick #{selectedPick.pick_number}</p>
              <p className="text-xs text-white/30 text-center">This action cannot be undone.</p>
            </div>
          )}
          <DialogFooter className="gap-2">
            <button onClick={() => setConfirmOpen(false)} className="px-4 py-2 rounded bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition-all">Cancel</button>
            <button onClick={handleDraft} disabled={draftMut.isPending}
              className="px-6 py-2 rounded bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold transition-all disabled:opacity-50 shadow-lg shadow-emerald-900/30">
              {draftMut.isPending ? 'Drafting...' : 'Confirm Pick'}
            </button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// MAIN EXPORT — scouting board by default, live draft on demand
// ═══════════════════════════════════════════════════════════
export default function DraftRoom() {
  const [mode, setMode] = useState<'scouting' | 'live'>('scouting');
  const { data: draftState } = useDraftState();
  // Only show live draft when the league is in the draft week (pre_draft or draft offseason phase)
  const offseasonPhase = draftState?.offseason_phase;
  const isDraftWeek = offseasonPhase === 'pre_draft' || offseasonPhase === 'draft';
  const draftIsLive = isDraftWeek && (draftState?.status === 'in_progress' || draftState?.status === 'not_started');

  if (mode === 'live') return <LiveDraft onExit={() => setMode('scouting')} />;
  return <ScoutingBoard onEnterLiveDraft={() => setMode('live')} draftIsLive={draftIsLive} />;
}
