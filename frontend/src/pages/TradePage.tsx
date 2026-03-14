import { useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useTrades, useProposeTrade, useRespondTrade, useRoster, useTeams } from '@/hooks/useApi';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { motion } from 'framer-motion';
import { ArrowLeftRight, CheckCircle, XCircle, Scale, Send, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { TeamBadge } from '@/components/TeamBadge';
import type { Trade, TradeEvaluation } from '@/api/client';

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

function gradeColor(grade: string) {
  const map: Record<string, string> = {
    'A+': 'text-green-400', A: 'text-green-400',
    'B+': 'text-blue-400', B: 'text-blue-400',
    'C+': 'text-yellow-400', C: 'text-yellow-400',
    D: 'text-orange-400', F: 'text-red-400',
  };
  return map[grade] ?? 'text-[var(--text-secondary)]';
}

function EvaluationCard({ evaluation }: { evaluation: TradeEvaluation }) {
  return (
    <Card className="border-[var(--border)] bg-[var(--bg-primary)]">
      <CardContent className="p-4">
        <div className="flex items-center gap-2 mb-3">
          <Scale className="h-4 w-4 text-[var(--accent-blue)]" />
          <h4 className="font-display text-sm uppercase tracking-wider text-[var(--text-muted)]">
            Trade Evaluation
          </h4>
        </div>
        <div className="grid grid-cols-3 gap-4 text-center">
          <div>
            <p className="text-xs text-[var(--text-muted)]">Your Value</p>
            <p className="font-display text-xl text-[var(--accent-blue)]">{evaluation.offering_value}</p>
          </div>
          <div>
            <p className="text-xs text-[var(--text-muted)]">Grade</p>
            <p className={`font-display text-xl ${gradeColor(evaluation.grade)}`}>{evaluation.grade}</p>
          </div>
          <div>
            <p className="text-xs text-[var(--text-muted)]">Their Value</p>
            <p className="font-display text-xl text-[var(--accent-red)]">{evaluation.requesting_value}</p>
          </div>
        </div>
        <Separator className="my-3" />
        <p className="text-sm text-[var(--text-secondary)]">{evaluation.summary}</p>
        <div className="mt-2 flex items-center gap-1">
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
      </CardContent>
    </Card>
  );
}

function TradeCard({ trade, myTeamId, onRespond }: {
  trade: Trade;
  myTeamId: number;
  onRespond: (id: number, response: string) => void;
}) {
  const isMine = trade.proposing_team_id === myTeamId;
  const isPending = trade.status === 'pending' || trade.status === 'proposed';

  return (
    <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
      <CardContent className="p-5">
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-3">
            <div className="flex items-center gap-2">
              <TeamBadge
                abbreviation={trade.proposing_team?.abbreviation}
                primaryColor={trade.proposing_team?.primary_color}
                secondaryColor={trade.proposing_team?.secondary_color}
                size="sm"
              />
              <span className="text-sm font-semibold">{trade.proposing_team?.city} {trade.proposing_team?.name}</span>
            </div>
            <ArrowLeftRight className="h-4 w-4 text-[var(--text-muted)]" />
            <div className="flex items-center gap-2">
              <TeamBadge
                abbreviation={trade.receiving_team?.abbreviation}
                primaryColor={trade.receiving_team?.primary_color}
                secondaryColor={trade.receiving_team?.secondary_color}
                size="sm"
              />
              <span className="text-sm font-semibold">{trade.receiving_team?.city} {trade.receiving_team?.name}</span>
            </div>
          </div>
          <Badge variant="outline" className={`text-[10px] ${statusBadge(trade.status)}`}>
            {trade.status}
          </Badge>
        </div>

        <div className="grid gap-4 md:grid-cols-2">
          <div className="rounded-lg bg-[var(--bg-primary)] p-3 border border-[var(--border)]">
            <p className="text-xs font-medium text-[var(--text-muted)] mb-2">
              {trade.proposing_team?.abbreviation} Sends
            </p>
            {(trade.offered_players ?? []).map((p) => (
              <div key={p.id} className="flex items-center justify-between py-1">
                <span className="text-sm">{p.first_name} {p.last_name}</span>
                <span className="text-xs text-[var(--text-secondary)]">{p.position} - {p.overall_rating} OVR</span>
              </div>
            ))}
          </div>
          <div className="rounded-lg bg-[var(--bg-primary)] p-3 border border-[var(--border)]">
            <p className="text-xs font-medium text-[var(--text-muted)] mb-2">
              {trade.receiving_team?.abbreviation} Sends
            </p>
            {(trade.requested_players ?? []).map((p) => (
              <div key={p.id} className="flex items-center justify-between py-1">
                <span className="text-sm">{p.first_name} {p.last_name}</span>
                <span className="text-xs text-[var(--text-secondary)]">{p.position} - {p.overall_rating} OVR</span>
              </div>
            ))}
          </div>
        </div>

        {trade.evaluation && (
          <div className="mt-4">
            <EvaluationCard evaluation={trade.evaluation} />
          </div>
        )}

        {isPending && !isMine && (
          <div className="mt-4 flex gap-2 justify-end">
            <Button
              size="sm"
              variant="outline"
              className="text-red-400 border-red-500/30 hover:bg-red-500/10"
              onClick={() => onRespond(trade.id, 'reject')}
            >
              <XCircle className="mr-1 h-3.5 w-3.5" /> Reject
            </Button>
            <Button
              size="sm"
              className="bg-green-600 hover:bg-green-700"
              onClick={() => onRespond(trade.id, 'accept')}
            >
              <CheckCircle className="mr-1 h-3.5 w-3.5" /> Accept
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

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
          toast.success('Trade proposed!');
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
      <div className="flex h-64 items-center justify-center">
        <div className="text-center">
          <ArrowLeftRight className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
          <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading trades...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
      >
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--accent-blue)]/10">
              <ArrowLeftRight className="h-5 w-5 text-[var(--accent-blue)]" />
            </div>
            <div>
              <h1 className="font-display text-2xl">Trade Center</h1>
              <p className="text-sm text-[var(--text-secondary)]">
                Propose, evaluate, and respond to trades
              </p>
            </div>
          </div>
          <Button onClick={() => setDialogOpen(true)}>
            <Plus className="mr-1 h-4 w-4" /> Propose Trade
          </Button>
        </div>
      </motion.div>

      {lastEval && (
        <motion.div
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.3 }}
        >
          <EvaluationCard evaluation={lastEval} />
        </motion.div>
      )}

      <Tabs defaultValue="active">
        <TabsList>
          <TabsTrigger value="active">
            Active ({activeTrades.length})
          </TabsTrigger>
          <TabsTrigger value="history">
            History ({completedTrades.length})
          </TabsTrigger>
        </TabsList>

        <TabsContent value="active" className="mt-4">
          {activeTrades.length === 0 ? (
            <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardContent className="flex flex-col items-center justify-center py-12">
                <ArrowLeftRight className="h-8 w-8 text-[var(--text-muted)] mb-2" />
                <p className="text-sm text-[var(--text-secondary)]">No active trades</p>
                <p className="text-xs text-[var(--text-muted)] mt-1">
                  Propose a trade to get started
                </p>
              </CardContent>
            </Card>
          ) : (
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
          )}
        </TabsContent>

        <TabsContent value="history" className="mt-4">
          {completedTrades.length === 0 ? (
            <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardContent className="flex flex-col items-center justify-center py-12">
                <p className="text-sm text-[var(--text-secondary)]">No trade history yet</p>
              </CardContent>
            </Card>
          ) : (
            <div className="space-y-4">
              {completedTrades.map((trade) => (
                <TradeCard
                  key={trade.id}
                  trade={trade}
                  myTeamId={team?.id ?? 0}
                  onRespond={handleRespond}
                />
              ))}
            </div>
          )}
        </TabsContent>
      </Tabs>

      {/* Propose Trade Dialog */}
      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-w-2xl bg-[var(--bg-surface)] border-[var(--border)]">
          <DialogHeader>
            <DialogTitle className="font-display text-xl">Propose a Trade</DialogTitle>
          </DialogHeader>

          <div className="space-y-4">
            <div>
              <label className="text-xs font-medium text-[var(--text-muted)] mb-1 block">
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
                <p className="text-xs font-medium text-[var(--text-muted)] mb-2">
                  You Send ({offeredIds.length} selected)
                </p>
                <div className="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-2">
                  {myPlayers.map((p) => (
                    <button
                      key={p.id}
                      onClick={() => toggleOffer(p.id)}
                      className={`flex w-full items-center justify-between rounded px-2 py-1.5 text-left text-sm transition-colors ${
                        offeredIds.includes(p.id)
                          ? 'bg-[var(--accent-blue)]/10 text-[var(--accent-blue)]'
                          : 'hover:bg-[var(--bg-elevated)]'
                      }`}
                    >
                      <span>{p.first_name} {p.last_name}</span>
                      <span className="text-xs text-[var(--text-muted)]">{p.position} {p.overall_rating}</span>
                    </button>
                  ))}
                </div>
              </div>

              {/* Players you request */}
              <div>
                <p className="text-xs font-medium text-[var(--text-muted)] mb-2">
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
            <Button variant="outline" onClick={() => setDialogOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={handlePropose}
              disabled={proposeMut.isPending}
            >
              <Send className="mr-1 h-4 w-4" />
              {proposeMut.isPending ? 'Sending...' : 'Send Proposal'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
