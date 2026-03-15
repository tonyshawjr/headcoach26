import { useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useTrades, useProposeTrade, useRespondTrade, useRoster, useTeams } from '@/hooks/useApi';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { motion } from 'framer-motion';
import { ArrowLeftRight, CheckCircle, XCircle, Scale, Send, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { TeamBadge } from '@/components/TeamBadge';
import type { Trade, TradeEvaluation } from '@/api/client';
import {
  PageLayout,
  PageHeader,
  SportsTabs,
  Section,
  ActionButton,
  EmptyBlock,
  StatCard,
} from '@/components/ui/sports-ui';

/* ----------------------------------------------------------------
   Helpers
   ---------------------------------------------------------------- */

function statusBadge(status: string) {
  const map: Record<string, string> = {
    pending: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    proposed: 'bg-blue-500/20 text-blue-400 border-blue-500/30',
    accepted: 'bg-green-500/20 text-green-400 border-green-500/30',
    rejected: 'bg-red-500/20 text-red-400 border-red-500/30',
    cancelled: 'bg-gray-500/20 text-gray-400 border-gray-500/30',
  };
  return map[status] ?? '';
}

/* ----------------------------------------------------------------
   Evaluation Card
   ---------------------------------------------------------------- */

function EvaluationCard({ evaluation }: { evaluation: TradeEvaluation }) {
  return (
    <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
      <div
        className="h-[2px] w-full"
        style={{ background: 'linear-gradient(90deg, var(--accent-blue), var(--accent-red))' }}
      />
      <div className="p-5">
        <div className="flex items-center gap-2 mb-4">
          <Scale className="h-4 w-4 text-[var(--accent-blue)]" />
          <h4 className="text-[10px] font-bold uppercase tracking-[0.14em] text-[var(--accent-blue)]">
            Trade Evaluation
          </h4>
        </div>

        <div className="grid grid-cols-3 gap-4">
          <StatCard label="Your Value" value={evaluation.offering_value} accentColor="var(--accent-blue)" />
          <StatCard label="Grade" value={evaluation.grade} accentColor={
            evaluation.grade.startsWith('A') ? '#22c55e'
              : evaluation.grade.startsWith('B') ? '#3b82f6'
              : evaluation.grade.startsWith('C') ? '#eab308'
              : '#ef4444'
          } />
          <StatCard label="Their Value" value={evaluation.requesting_value} accentColor="var(--accent-red)" />
        </div>

        <div className="mt-4 h-px bg-[var(--border)]" />

        <p className="mt-4 text-sm text-[var(--text-secondary)] leading-relaxed">{evaluation.summary}</p>
        <div className="mt-3 flex items-center gap-1">
          {evaluation.fair ? (
            <Badge variant="outline" className="bg-green-500/10 text-green-400 border-green-500/30 text-[10px]">
              Fair Trade
            </Badge>
          ) : (
            <Badge variant="outline" className="bg-red-500/10 text-red-400 border-red-500/30 text-[10px]">
              Unfair Trade
            </Badge>
          )}
        </div>
      </div>
    </div>
  );
}

/* ----------------------------------------------------------------
   Asset Row — single player or pick in a trade side
   ---------------------------------------------------------------- */

function AssetRow({ name, detail, kind }: { name: string; detail: string; kind: 'player' | 'pick' }) {
  return (
    <div className="flex items-center justify-between py-2 border-b border-[var(--border)] last:border-b-0">
      <span className="text-[13px] font-medium text-[var(--text-primary)]">{name}</span>
      <span className={`text-[11px] font-semibold uppercase tracking-wider ${
        kind === 'pick' ? 'text-[var(--accent-gold)]' : 'text-[var(--text-muted)]'
      }`}>
        {detail}
      </span>
    </div>
  );
}

/* ----------------------------------------------------------------
   Trade Card — editorial side-by-side comparison
   ---------------------------------------------------------------- */

function TradeCard({ trade, myTeamId, onRespond }: {
  trade: Trade;
  myTeamId: number;
  onRespond: (id: number, response: string) => void;
}) {
  const isMine = trade.proposing_team_id === myTeamId;
  const isPending = trade.status === 'pending' || trade.status === 'proposed';

  return (
    <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
      {/* Top accent gradient */}
      <div
        className="h-[2px] w-full"
        style={{
          background: `linear-gradient(90deg, ${trade.proposing_team?.primary_color ?? 'var(--accent-blue)'}, ${trade.receiving_team?.primary_color ?? 'var(--accent-red)'})`,
        }}
      />

      <div className="p-5">
        {/* Header row: teams + status */}
        <div className="flex items-center justify-between mb-5">
          <div className="flex items-center gap-3">
            <div className="flex items-center gap-2">
              <TeamBadge
                abbreviation={trade.proposing_team?.abbreviation}
                primaryColor={trade.proposing_team?.primary_color}
                secondaryColor={trade.proposing_team?.secondary_color}
                size="sm"
              />
              <span className="text-sm font-semibold text-[var(--text-primary)]">
                {trade.proposing_team?.city} {trade.proposing_team?.name}
              </span>
            </div>
            <ArrowLeftRight className="h-4 w-4 text-[var(--text-muted)]" />
            <div className="flex items-center gap-2">
              <TeamBadge
                abbreviation={trade.receiving_team?.abbreviation}
                primaryColor={trade.receiving_team?.primary_color}
                secondaryColor={trade.receiving_team?.secondary_color}
                size="sm"
              />
              <span className="text-sm font-semibold text-[var(--text-primary)]">
                {trade.receiving_team?.city} {trade.receiving_team?.name}
              </span>
            </div>
          </div>
          <Badge variant="outline" className={`text-[10px] font-bold uppercase tracking-wider ${statusBadge(trade.status)}`}>
            {trade.status}
          </Badge>
        </div>

        {/* Side-by-side comparison */}
        <div className="grid gap-4 md:grid-cols-2">
          {/* Proposing side */}
          <div className="rounded-lg bg-[var(--bg-primary)] border border-[var(--border)] overflow-hidden">
            <div
              className="px-3 py-2 border-b border-[var(--border)]"
              style={{ background: `${trade.proposing_team?.primary_color ?? 'var(--accent-blue)'}10` }}
            >
              <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-[var(--text-muted)]">
                {trade.proposing_team?.abbreviation} Sends
              </p>
            </div>
            <div className="px-3 py-1">
              {(trade.offered_players ?? []).map((p) => (
                <AssetRow
                  key={p.id}
                  name={`${p.first_name} ${p.last_name}`}
                  detail={`${p.position} — ${p.overall_rating} OVR`}
                  kind="player"
                />
              ))}
              {(trade.offered_picks ?? []).map((pk: any) => (
                <AssetRow
                  key={pk.id}
                  name={pk.label ?? `Round ${pk.round}`}
                  detail="Draft Pick"
                  kind="pick"
                />
              ))}
            </div>
          </div>

          {/* Receiving side */}
          <div className="rounded-lg bg-[var(--bg-primary)] border border-[var(--border)] overflow-hidden">
            <div
              className="px-3 py-2 border-b border-[var(--border)]"
              style={{ background: `${trade.receiving_team?.primary_color ?? 'var(--accent-red)'}10` }}
            >
              <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-[var(--text-muted)]">
                {trade.receiving_team?.abbreviation} Sends
              </p>
            </div>
            <div className="px-3 py-1">
              {(trade.requested_players ?? []).map((p) => (
                <AssetRow
                  key={p.id}
                  name={`${p.first_name} ${p.last_name}`}
                  detail={`${p.position} — ${p.overall_rating} OVR`}
                  kind="player"
                />
              ))}
              {(trade.requested_picks ?? []).map((pk: any) => (
                <AssetRow
                  key={pk.id}
                  name={pk.label ?? `Round ${pk.round}`}
                  detail="Draft Pick"
                  kind="pick"
                />
              ))}
            </div>
          </div>
        </div>

        {/* Evaluation */}
        {trade.evaluation && (
          <div className="mt-5">
            <EvaluationCard evaluation={trade.evaluation} />
          </div>
        )}

        {/* Response actions */}
        {isPending && !isMine && (
          <div className="mt-5 flex gap-2 justify-end">
            <ActionButton
              variant="danger"
              size="sm"
              icon={XCircle}
              onClick={() => onRespond(trade.id, 'reject')}
            >
              Reject
            </ActionButton>
            <ActionButton
              variant="primary"
              size="sm"
              icon={CheckCircle}
              accentColor="#16a34a"
              onClick={() => onRespond(trade.id, 'accept')}
            >
              Accept
            </ActionButton>
          </div>
        )}
      </div>
    </div>
  );
}

/* ----------------------------------------------------------------
   Main Page
   ---------------------------------------------------------------- */

export default function TradePage() {
  const team = useAuthStore((s) => s.team);
  const league = useAuthStore((s) => s.league);
  const { data: trades, isLoading } = useTrades();
  const { data: roster } = useRoster(team?.id);
  const { data: teamsData } = useTeams(league?.id);
  const proposeMut = useProposeTrade();
  const respondMut = useRespondTrade();

  const [dialogOpen, setDialogOpen] = useState(false);
  const [targetTeamId, setTargetTeamId] = useState<string>('');
  const [offeredIds, setOfferedIds] = useState<number[]>([]);
  const [requestedIds, setRequestedIds] = useState<number[]>([]);
  const [lastEval, setLastEval] = useState<TradeEvaluation | null>(null);
  const [lastTradeResponse, setLastTradeResponse] = useState<{
    status: string;
    message?: string;
    reason?: string;
    counter_offer?: {
      original_trade_id: number;
      gap: number;
      gm_personality: string;
      message: string;
      ask_addition?: {
        type: string;
        player_id?: number;
        draft_pick_id?: number;
        name?: string;
        label?: string;
        position?: string;
        overall_rating?: number;
        round?: number;
        value?: number;
      };
    };
  } | null>(null);
  const [activeTab, setActiveTab] = useState('active');

  const allTeams = teamsData?.conferences
    ? Object.values(teamsData.conferences).flatMap((divs) =>
        Object.values(divs).flat()
      )
    : [];
  const otherTeams = allTeams.filter((t) => t.id !== team?.id);

  const myPlayers = roster?.active ?? [];

  function handleRespond(id: number, action: string) {
    respondMut.mutate(
      { id, action },
      {
        onSuccess: () => toast.success(`Trade ${action}ed`),
        onError: (err) => toast.error(err.message),
      },
    );
  }

  function handlePropose() {
    if (!targetTeamId || offeredIds.length === 0 || requestedIds.length === 0) {
      toast.error('Select a team and players on both sides');
      return;
    }
    proposeMut.mutate(
      {
        target_team_id: Number(targetTeamId),
        offering_player_ids: offeredIds,
        requesting_player_ids: requestedIds,
      },
      {
        onSuccess: (data) => {
          setLastEval(data.evaluation);
          const tradeData = data.trade ?? data;
          setLastTradeResponse({
            status: tradeData.status ?? 'unknown',
            message: tradeData.message ?? tradeData.reason,
            reason: tradeData.reason,
            counter_offer: tradeData.counter_offer,
          });

          if (tradeData.status === 'completed') {
            toast.success(tradeData.message || 'Trade accepted!');
          } else if (tradeData.status === 'countered') {
            toast('They have a counter-proposal', { description: tradeData.reason });
          } else if (tradeData.status === 'rejected') {
            toast.error(tradeData.reason || 'Trade rejected');
          } else {
            toast.success('Trade proposed!');
          }

          setDialogOpen(false);
          setOfferedIds([]);
          setRequestedIds([]);
          setTargetTeamId('');
        },
        onError: (err) => toast.error(err.message),
      },
    );
  }

  function toggleOffer(id: number) {
    setOfferedIds((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
    );
  }

  function toggleRequest(id: number) {
    setRequestedIds((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
    );
  }

  const activeTrades = (trades ?? []).filter((t) => t.status === 'pending' || t.status === 'proposed');
  const completedTrades = (trades ?? []).filter((t) => t.status !== 'pending' && t.status !== 'proposed');

  if (isLoading) {
    return (
      <PageLayout>
        <div className="flex h-64 items-center justify-center">
          <div className="text-center">
            <ArrowLeftRight className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
            <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading trades...</p>
          </div>
        </div>
      </PageLayout>
    );
  }

  return (
    <PageLayout>
      <PageHeader
        title="Trades"
        subtitle="Propose, evaluate, and respond to trades"
        icon={ArrowLeftRight}
        actions={
          <ActionButton
            icon={Plus}
            onClick={() => setDialogOpen(true)}
          >
            Propose Trade
          </ActionButton>
        }
      />

      {/* Last Evaluation Banner */}
      {lastEval && (
        <motion.div
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.3 }}
          className="mb-6"
        >
          <EvaluationCard evaluation={lastEval} />
        </motion.div>
      )}

      {/* Counter-Offer / Trade Response */}
      {lastTradeResponse && lastTradeResponse.status !== 'completed' && (
        <motion.div
          initial={{ opacity: 0, y: 10 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.3, delay: 0.1 }}
          className="mb-6"
        >
          <div className={`rounded-lg border overflow-hidden ${
            lastTradeResponse.status === 'countered'
              ? 'border-yellow-500/30 bg-yellow-500/5'
              : 'border-red-500/30 bg-red-500/5'
          }`}>
            <div className={`h-[2px] w-full ${
              lastTradeResponse.status === 'countered'
                ? 'bg-gradient-to-r from-yellow-500 to-transparent'
                : 'bg-gradient-to-r from-red-500 to-transparent'
            }`} />
            <div className="p-5">
              {/* GM avatar + response */}
              <div className="flex items-start gap-3">
                <div className={`h-10 w-10 rounded-full flex items-center justify-center text-white text-sm font-bold shrink-0 ${
                  lastTradeResponse.status === 'countered' ? 'bg-yellow-600' : 'bg-red-600'
                }`}>
                  GM
                </div>
                <div className="flex-1">
                  <div className="flex items-center gap-2 mb-1">
                    <span className="text-xs font-bold uppercase tracking-wider text-[var(--text-muted)]">
                      {lastTradeResponse.counter_offer?.gm_personality
                        ? `${lastTradeResponse.counter_offer.gm_personality} GM`
                        : 'Opposing GM'}
                    </span>
                    <Badge variant="outline" className={`text-[9px] ${
                      lastTradeResponse.status === 'countered'
                        ? 'bg-yellow-500/10 text-yellow-400 border-yellow-500/30'
                        : 'bg-red-500/10 text-red-400 border-red-500/30'
                    }`}>
                      {lastTradeResponse.status === 'countered' ? 'Counter-Offer' : 'Rejected'}
                    </Badge>
                  </div>
                  <p className="text-sm text-[var(--text-secondary)] leading-relaxed italic">
                    &ldquo;{lastTradeResponse.counter_offer?.message || lastTradeResponse.reason || lastTradeResponse.message}&rdquo;
                  </p>
                </div>
              </div>

              {/* Counter-offer details */}
              {lastTradeResponse.counter_offer?.ask_addition && (
                <div className="mt-4 rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-4">
                  <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-yellow-500 mb-2">
                    They want you to add:
                  </p>
                  {lastTradeResponse.counter_offer.ask_addition.type === 'draft_pick' ? (
                    <div className="flex items-center gap-3">
                      <div className="h-8 w-8 rounded-lg bg-[var(--accent-gold)]/10 flex items-center justify-center text-[var(--accent-gold)] text-xs font-bold">
                        R{lastTradeResponse.counter_offer.ask_addition.round}
                      </div>
                      <div>
                        <p className="text-sm font-semibold text-[var(--text-primary)]">
                          {lastTradeResponse.counter_offer.ask_addition.label}
                        </p>
                        <p className="text-[10px] text-[var(--text-muted)]">Draft Pick</p>
                      </div>
                    </div>
                  ) : (
                    <div className="flex items-center gap-3">
                      <div className="h-8 w-8 rounded-lg bg-[var(--accent-blue)]/10 flex items-center justify-center text-[var(--accent-blue)] text-xs font-bold">
                        {lastTradeResponse.counter_offer.ask_addition.position}
                      </div>
                      <div>
                        <p className="text-sm font-semibold text-[var(--text-primary)]">
                          {lastTradeResponse.counter_offer.ask_addition.name}
                        </p>
                        <p className="text-[10px] text-[var(--text-muted)]">
                          {lastTradeResponse.counter_offer.ask_addition.overall_rating} OVR {lastTradeResponse.counter_offer.ask_addition.position}
                        </p>
                      </div>
                    </div>
                  )}

                  <div className="mt-3 flex items-center gap-2">
                    <ActionButton
                      variant="primary"
                      size="sm"
                      accentColor="#16a34a"
                      onClick={() => {
                        // Re-propose with the additional asset included
                        toast.info('To accept, re-propose the trade with the requested addition included.');
                        setLastTradeResponse(null);
                      }}
                    >
                      Accept Counter
                    </ActionButton>
                    <ActionButton
                      variant="ghost"
                      size="sm"
                      onClick={() => setLastTradeResponse(null)}
                    >
                      Walk Away
                    </ActionButton>
                  </div>
                </div>
              )}

              {/* Rejected — no counter details */}
              {lastTradeResponse.status === 'rejected' && !lastTradeResponse.counter_offer?.ask_addition && (
                <div className="mt-3">
                  <ActionButton
                    variant="ghost"
                    size="sm"
                    onClick={() => setLastTradeResponse(null)}
                  >
                    Dismiss
                  </ActionButton>
                </div>
              )}
            </div>
          </div>
        </motion.div>
      )}

      {/* Tabs */}
      <SportsTabs
        tabs={[
          { key: 'active', label: `Active (${activeTrades.length})` },
          { key: 'history', label: `History (${completedTrades.length})` },
        ]}
        activeTab={activeTab}
        onChange={setActiveTab}
        variant="underline"
      />

      {/* Tab Content */}
      <div className="mt-5">
        {activeTab === 'active' && (
          <>
            {activeTrades.length === 0 ? (
              <EmptyBlock
                icon={ArrowLeftRight}
                title="No active trades"
                description="Propose a trade to get started"
                action={{ label: 'Propose Trade', onClick: () => setDialogOpen(true) }}
              />
            ) : (
              <Section title="Pending Deals" delay={0.05}>
                <div className="space-y-4">
                  {activeTrades.map((trade, i) => (
                    <motion.div
                      key={trade.id}
                      initial={{ opacity: 0, y: 10 }}
                      animate={{ opacity: 1, y: 0 }}
                      transition={{ duration: 0.3, delay: i * 0.05 }}
                    >
                      <TradeCard
                        trade={trade}
                        myTeamId={team?.id ?? 0}
                        onRespond={handleRespond}
                      />
                    </motion.div>
                  ))}
                </div>
              </Section>
            )}
          </>
        )}

        {activeTab === 'history' && (
          <>
            {completedTrades.length === 0 ? (
              <EmptyBlock
                icon={ArrowLeftRight}
                title="No trade history yet"
                description="Completed, rejected, and cancelled trades will appear here"
              />
            ) : (
              <Section title="Trade History" delay={0.05}>
                <div className="space-y-4">
                  {completedTrades.map((trade, i) => (
                    <motion.div
                      key={trade.id}
                      initial={{ opacity: 0, y: 10 }}
                      animate={{ opacity: 1, y: 0 }}
                      transition={{ duration: 0.3, delay: i * 0.03 }}
                    >
                      <TradeCard
                        trade={trade}
                        myTeamId={team?.id ?? 0}
                        onRespond={handleRespond}
                      />
                    </motion.div>
                  ))}
                </div>
              </Section>
            )}
          </>
        )}
      </div>

      {/* Propose Trade Dialog */}
      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-w-2xl bg-[var(--bg-surface)] border-[var(--border)]">
          <DialogHeader>
            <DialogTitle className="font-display text-xl">Propose a Trade</DialogTitle>
          </DialogHeader>

          <div className="space-y-4">
            <div>
              <label className="text-[10px] font-bold uppercase tracking-[0.14em] text-[var(--text-muted)] mb-1.5 block">
                Trade Partner
              </label>
              <Select value={targetTeamId} onValueChange={(v) => v && setTargetTeamId(v)}>
                <SelectTrigger>
                  <SelectValue placeholder="Select a team" />
                </SelectTrigger>
                <SelectContent>
                  {otherTeams.map((t) => (
                    <SelectItem key={t.id} value={String(t.id)}>
                      {t.abbreviation} - {t.city} {t.name} ({t.wins}-{t.losses})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
              {/* Players you offer */}
              <div>
                <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-[var(--text-muted)] mb-2">
                  You Send ({offeredIds.length} selected)
                </p>
                <div className="max-h-48 space-y-0.5 overflow-y-auto rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-2">
                  {myPlayers.map((p) => (
                    <button
                      key={p.id}
                      onClick={() => toggleOffer(p.id)}
                      className={`flex w-full items-center justify-between rounded px-2 py-1.5 text-left text-sm transition-colors ${
                        offeredIds.includes(p.id)
                          ? 'bg-[var(--accent-blue)]/10 text-[var(--accent-blue)] border border-[var(--accent-blue)]/20'
                          : 'hover:bg-[var(--bg-elevated)] border border-transparent'
                      }`}
                    >
                      <span className="text-[13px] font-medium">{p.first_name} {p.last_name}</span>
                      <span className="text-[11px] text-[var(--text-muted)] font-semibold">{p.position} {p.overall_rating}</span>
                    </button>
                  ))}
                </div>
              </div>

              {/* Players you request */}
              <div>
                <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-[var(--text-muted)] mb-2">
                  You Receive (enter player IDs)
                </p>
                <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-3">
                  <p className="text-xs text-[var(--text-muted)] mb-2">
                    Selected player IDs: {requestedIds.join(', ') || 'none'}
                  </p>
                  <div className="flex gap-2">
                    <input
                      type="number"
                      placeholder="Player ID"
                      className="flex-1 rounded bg-[var(--bg-surface)] border border-[var(--border)] px-2 py-1 text-sm text-[var(--text-primary)]"
                      onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                          const val = Number((e.target as HTMLInputElement).value);
                          if (val > 0) {
                            toggleRequest(val);
                            (e.target as HTMLInputElement).value = '';
                          }
                        }
                      }}
                    />
                  </div>
                  {requestedIds.length > 0 && (
                    <div className="mt-2 flex flex-wrap gap-1">
                      {requestedIds.map((id) => (
                        <Badge
                          key={id}
                          variant="outline"
                          className="cursor-pointer text-[10px] hover:bg-red-500/10"
                          onClick={() => toggleRequest(id)}
                        >
                          #{id} <Trash2 className="ml-1 h-2.5 w-2.5" />
                        </Badge>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            </div>
          </div>

          <DialogFooter>
            <ActionButton variant="ghost" onClick={() => setDialogOpen(false)}>
              Cancel
            </ActionButton>
            <ActionButton
              icon={Send}
              onClick={handlePropose}
              disabled={proposeMut.isPending}
            >
              {proposeMut.isPending ? 'Sending...' : 'Send Proposal'}
            </ActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </PageLayout>
  );
}
