import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useNavigate } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { motion } from 'framer-motion';
import { DollarSign, AlertTriangle, CheckCircle, XCircle, UserPlus, GraduationCap, ArrowLeftRight } from 'lucide-react';

function formatSalary(n: number): string {
  if (n < 0) return `-${formatSalary(Math.abs(n))}`;
  if (n >= 1_000_000) return `$${(n / 1_000_000).toFixed(1)}M`;
  if (n >= 1_000) return `$${(n / 1_000).toFixed(0)}K`;
  return `$${n.toLocaleString()}`;
}

interface PlannerPlayer {
  id: number;
  name: string;
  position: string;
  overall_rating: number;
  age: number;
  potential: string;
  is_starter: boolean;
  current_salary: number;
  market_value: number;
  reason: string;
  gm_note: string;
  replacement?: {
    type: 'free_agent' | 'draft' | 'trade';
    name: string;
    position: string;
    overall_rating?: number;
    age?: number;
    potential?: string;
    estimated_cost?: number;
    projected_round?: number;
    team?: string;
    note: string;
    player_id?: number;
    prospect_id?: number;
  };
}

interface PlannerData {
  summary: string;
  budget: {
    total: number;
    committed: number;
    available: number;
    must_sign_cost: number;
    should_sign_cost: number;
    after_must_signs: number;
    after_all_signs: number;
  };
  must_sign: PlannerPlayer[];
  should_sign: PlannerPlayer[];
  can_let_go: PlannerPlayer[];
  total_expiring: number;
  cap_fixes?: {
    over_by: number;
    message: string;
    restructure: { contract_id: number; player_id: number; name: string; position: string; overall_rating: number; current_cap_hit: number; new_cap_hit: number; savings: number; note: string }[];
    cuts: { player_id: number; name: string; position: string; overall_rating: number; age: number; cap_hit: number; dead_cap: number; savings: number; note: string }[];
  };
}

function PlayerRow({ p, navigate, category }: { p: PlannerPlayer; navigate: (path: string) => void; category: string }) {
  const r = p.replacement;

  return (
    <div
      className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] overflow-hidden cursor-pointer hover:bg-[var(--bg-elevated)] transition-colors"
      onClick={() => navigate(`/player/${p.id}`)}
    >
      <div className="p-3 sm:p-4">
        <div className="flex items-center justify-between gap-3">
          <div className="flex items-center gap-3 min-w-0">
            <div className="text-center shrink-0">
              <p className={`font-stat text-xl leading-none ${
                p.overall_rating >= 80 ? 'text-green-400' : p.overall_rating >= 70 ? 'text-blue-400' : 'text-yellow-400'
              }`}>{p.overall_rating}</p>
              <p className="text-[9px] text-[var(--text-muted)]">OVR</p>
            </div>
            <div className="min-w-0">
              <div className="flex items-center gap-2">
                <span className="font-semibold text-sm text-[var(--text-primary)] truncate">{p.name}</span>
                <Badge variant="outline" className="text-[9px] shrink-0">{p.position}</Badge>
                {p.is_starter && <Badge variant="outline" className="text-[9px] bg-green-500/10 text-green-400 border-green-500/20 shrink-0">Starter</Badge>}
              </div>
              <p className="text-xs text-[var(--text-muted)]">
                Age {p.age} &middot; {p.potential} dev &middot; Makes {formatSalary(p.current_salary)}/yr &middot; Worth {formatSalary(p.market_value)}/yr
              </p>
            </div>
          </div>
          <div className="shrink-0 text-right">
            <p className="font-bold text-sm text-[var(--text-primary)]">{formatSalary(p.market_value)}</p>
            <p className="text-[9px] text-[var(--text-muted)]">to re-sign</p>
          </div>
        </div>

        {/* GM note */}
        <p className="text-xs text-[var(--text-secondary)] mt-2 italic">{p.gm_note}</p>

        {/* Replacement suggestion */}
        {r && category === 'let_go' && (
          <div
            className="mt-2 rounded-lg bg-[var(--bg-surface)] border border-[var(--border)] p-2.5 flex items-center gap-3 cursor-pointer hover:border-[var(--accent-blue)]/40 transition-colors"
            onClick={(e) => {
              e.stopPropagation();
              if (r.type === 'free_agent' && r.player_id) navigate(`/player/${r.player_id}`);
              else if (r.type === 'draft' && r.prospect_id) navigate(`/prospect/${r.prospect_id}`);
              else if (r.type === 'trade' && r.player_id) navigate(`/player/${r.player_id}`);
            }}
          >
            <div className="shrink-0">
              {r.type === 'free_agent' && <UserPlus className="h-4 w-4 text-green-400" />}
              {r.type === 'draft' && <GraduationCap className="h-4 w-4 text-blue-400" />}
              {r.type === 'trade' && <ArrowLeftRight className="h-4 w-4 text-purple-400" />}
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-[var(--text-primary)]">{r.name}</span>
                {r.overall_rating && (
                  <span className={`font-stat text-xs ${
                    r.overall_rating >= 80 ? 'text-green-400' : r.overall_rating >= 70 ? 'text-blue-400' : 'text-yellow-400'
                  }`}>{r.overall_rating}</span>
                )}
                <Badge variant="outline" className={`text-[8px] ${
                  r.type === 'free_agent' ? 'bg-green-500/10 text-green-400 border-green-500/20' :
                  r.type === 'draft' ? 'bg-blue-500/10 text-blue-400 border-blue-500/20' :
                  'bg-purple-500/10 text-purple-400 border-purple-500/20'
                }`}>
                  {r.type === 'free_agent' ? 'Free Agent' : r.type === 'draft' ? `Round ${r.projected_round}` : `Trade (${r.team})`}
                </Badge>
              </div>
              <p className="text-[10px] text-[var(--text-muted)]">{r.note}</p>
            </div>
            {r.estimated_cost && (
              <span className="text-xs font-semibold text-[var(--text-secondary)] shrink-0">{formatSalary(r.estimated_cost)}/yr</span>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

export default function ContractPlanner() {
  const navigate = useNavigate();

  const { data, isLoading } = useQuery<PlannerData>({
    queryKey: ['contractPlanner'],
    queryFn: () => api.get('/offseason/contract-planner'),
  });

  if (isLoading) {
    return (
      <div className="max-w-4xl mx-auto py-12 text-center">
        <DollarSign className="h-8 w-8 animate-pulse text-[var(--accent-gold)] mx-auto" />
        <p className="mt-2 text-sm text-[var(--text-secondary)]">Your GM is reviewing contracts...</p>
      </div>
    );
  }

  if (!data) return null;

  const b = data.budget;

  return (
    <div className="max-w-4xl mx-auto space-y-6">
      {/* Header */}
      <div>
        <div className="flex items-center gap-2 mb-1">
          <DollarSign className="h-5 w-5 text-[var(--accent-gold)]" />
          <h1 className="font-display text-2xl tracking-tight">Contract Planner</h1>
        </div>
        <p className="text-sm text-[var(--text-muted)]">{data.total_expiring} players with expiring contracts</p>
      </div>

      {/* GM Summary */}
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        className="rounded-xl bg-[var(--accent-gold)]/5 border border-[var(--accent-gold)]/20 p-5"
      >
        <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--accent-gold)] mb-2">Your GM</p>
        <p className="text-sm text-[var(--text-primary)] leading-relaxed">{data.summary}</p>
      </motion.div>

      {/* Budget breakdown */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-4 text-center">
          <p className="text-[10px] text-[var(--text-muted)]">Money to spend</p>
          <p className={`font-stat text-2xl ${b.available > 30000000 ? 'text-green-400' : b.available > 10000000 ? 'text-yellow-400' : 'text-red-400'}`}>
            {formatSalary(b.available)}
          </p>
        </div>
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-4 text-center">
          <p className="text-[10px] text-[var(--text-muted)]">Must-sign cost</p>
          <p className="font-stat text-2xl text-[var(--text-primary)]">
            {b.must_sign_cost > 0 ? formatSalary(b.must_sign_cost) : '$0'}
          </p>
        </div>
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-4 text-center">
          <p className="text-[10px] text-[var(--text-muted)]">After must-signs</p>
          <p className={`font-stat text-2xl ${b.after_must_signs > 20000000 ? 'text-green-400' : b.after_must_signs > 0 ? 'text-yellow-400' : 'text-red-400'}`}>
            {formatSalary(b.after_must_signs)}
          </p>
        </div>
        <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-4 text-center">
          <p className="text-[10px] text-[var(--text-muted)]">After all recommended</p>
          <p className={`font-stat text-2xl ${b.after_all_signs > 10000000 ? 'text-green-400' : b.after_all_signs > 0 ? 'text-yellow-400' : 'text-red-400'}`}>
            {formatSalary(b.after_all_signs)}
          </p>
          <p className="text-[9px] text-[var(--text-muted)]">Left for FA + draft</p>
        </div>
      </div>

      {/* Budget bar */}
      <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-4">
        <div className="flex justify-between text-xs text-[var(--text-muted)] mb-2">
          <span>Budget Breakdown</span>
          <span>{formatSalary(b.total)} total</span>
        </div>
        <div className="h-4 w-full rounded-full bg-[var(--bg-elevated)] overflow-hidden flex">
          <div className="h-full bg-gray-500" style={{ width: `${(b.committed / b.total) * 100}%` }} title="Committed to current roster" />
          {b.must_sign_cost > 0 && (
            <div className="h-full bg-red-500" style={{ width: `${(b.must_sign_cost / b.total) * 100}%` }} title="Must-sign players" />
          )}
          {b.should_sign_cost > 0 && (
            <div className="h-full bg-yellow-500" style={{ width: `${(b.should_sign_cost / b.total) * 100}%` }} title="Should-sign players" />
          )}
        </div>
        <div className="flex flex-wrap gap-4 mt-2 text-[10px]">
          <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-gray-500" /> Committed ({formatSalary(b.committed)})</span>
          {b.must_sign_cost > 0 && <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-red-500" /> Must sign ({formatSalary(b.must_sign_cost)})</span>}
          {b.should_sign_cost > 0 && <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-yellow-500" /> Should sign ({formatSalary(b.should_sign_cost)})</span>}
          <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-green-500" /> Free to spend ({formatSalary(Math.max(0, b.after_all_signs))})</span>
        </div>
      </div>

      {/* Cap Fix Section — only shows when over the cap */}
      {data.cap_fixes && (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="space-y-4">
          <div className="rounded-xl bg-red-500/10 border-2 border-red-500/30 p-5">
            <div className="flex items-center gap-2 mb-3">
              <AlertTriangle className="h-5 w-5 text-red-400" />
              <h2 className="font-display text-lg text-red-400">Over the Cap</h2>
              <span className="ml-auto font-stat text-xl text-red-400">-{formatSalary(data.cap_fixes.over_by)}</span>
            </div>
            <p className="text-sm text-[var(--text-secondary)] mb-4">{data.cap_fixes.message} You can&apos;t start the season until you&apos;re under the cap.</p>

            {/* Restructure options */}
            {data.cap_fixes.restructure.length > 0 && (
              <div className="mb-4">
                <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)] mb-2">
                  Restructure Contracts <span className="normal-case font-normal">— spread the cost over future years</span>
                </p>
                <div className="space-y-2">
                  {data.cap_fixes.restructure.map((r) => (
                    <div key={r.contract_id} className="flex items-center justify-between rounded-lg bg-[var(--bg-surface)] border border-[var(--border)] p-3">
                      <div className="flex items-center gap-3 min-w-0">
                        <span className={`font-stat text-lg ${r.overall_rating >= 80 ? 'text-green-400' : 'text-blue-400'}`}>{r.overall_rating}</span>
                        <div className="min-w-0">
                          <p className="text-sm font-semibold truncate">{r.name} <span className="text-xs text-[var(--text-muted)]">{r.position}</span></p>
                          <p className="text-[10px] text-[var(--text-muted)]">{r.note}</p>
                        </div>
                      </div>
                      <div className="text-right shrink-0 ml-3">
                        <p className="text-xs text-[var(--text-muted)] line-through">{formatSalary(r.current_cap_hit)}</p>
                        <p className="text-sm font-bold text-green-400">{formatSalary(r.new_cap_hit)}</p>
                        <p className="text-[10px] text-green-400">Saves {formatSalary(r.savings)}</p>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Cut options */}
            {data.cap_fixes.cuts.length > 0 && (
              <div>
                <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)] mb-2">
                  Cut Players <span className="normal-case font-normal">— release to free agency (may have dead money)</span>
                </p>
                <div className="space-y-2">
                  {data.cap_fixes.cuts.map((c) => (
                    <div
                      key={c.player_id}
                      className="flex items-center justify-between rounded-lg bg-[var(--bg-surface)] border border-[var(--border)] p-3 cursor-pointer hover:bg-[var(--bg-elevated)] transition-colors"
                      onClick={() => navigate(`/player/${c.player_id}`)}
                    >
                      <div className="flex items-center gap-3 min-w-0">
                        <span className={`font-stat text-lg ${c.overall_rating >= 80 ? 'text-green-400' : c.overall_rating >= 70 ? 'text-blue-400' : 'text-yellow-400'}`}>{c.overall_rating}</span>
                        <div className="min-w-0">
                          <p className="text-sm font-semibold truncate">{c.name} <span className="text-xs text-[var(--text-muted)]">{c.position} &middot; age {c.age}</span></p>
                          <p className="text-[10px] text-[var(--text-muted)]">{c.note}</p>
                        </div>
                      </div>
                      <div className="text-right shrink-0 ml-3">
                        <p className="text-sm font-bold text-green-400">Saves {formatSalary(c.savings)}</p>
                        {c.dead_cap > 0 && <p className="text-[10px] text-red-400">{formatSalary(c.dead_cap)} dead money</p>}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </motion.div>
      )}

      {/* Must Sign */}
      {data.must_sign.length > 0 && (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.1 }}>
          <div className="flex items-center gap-2 mb-3">
            <AlertTriangle className="h-4 w-4 text-red-400" />
            <h2 className="font-display text-sm uppercase tracking-wider text-red-400">Must Sign ({data.must_sign.length})</h2>
            <span className="text-xs text-[var(--text-muted)]">— Don&apos;t let these players walk</span>
          </div>
          <div className="space-y-2">
            {data.must_sign.map((p) => (
              <PlayerRow key={p.id} p={p} navigate={navigate} category="must_sign" />
            ))}
          </div>
        </motion.div>
      )}

      {/* Should Sign */}
      {data.should_sign.length > 0 && (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.2 }}>
          <div className="flex items-center gap-2 mb-3">
            <CheckCircle className="h-4 w-4 text-yellow-400" />
            <h2 className="font-display text-sm uppercase tracking-wider text-yellow-400">Should Sign ({data.should_sign.length})</h2>
            <span className="text-xs text-[var(--text-muted)]">— Worth keeping if the money works</span>
          </div>
          <div className="space-y-2">
            {data.should_sign.map((p) => (
              <PlayerRow key={p.id} p={p} navigate={navigate} category="should_sign" />
            ))}
          </div>
        </motion.div>
      )}

      {/* Can Let Go */}
      {data.can_let_go.length > 0 && (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.3 }}>
          <div className="flex items-center gap-2 mb-3">
            <XCircle className="h-4 w-4 text-[var(--text-muted)]" />
            <h2 className="font-display text-sm uppercase tracking-wider text-[var(--text-muted)]">Can Let Go ({data.can_let_go.length})</h2>
            <span className="text-xs text-[var(--text-muted)]">— Save the money, your GM found replacements</span>
          </div>
          <div className="space-y-2">
            {data.can_let_go.map((p) => (
              <PlayerRow key={p.id} p={p} navigate={navigate} category="let_go" />
            ))}
          </div>
        </motion.div>
      )}
    </div>
  );
}
