import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { TeamLogo } from '@/components/TeamLogo';
import { toast } from 'sonner';
import type { FindTradeResult, TradeOpportunity, TradePackageSide } from '@/api/client';

interface FindTradeModalProps {
  playerId: number | null;
  playerName?: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

const interestColors: Record<string, string> = {
  high: 'bg-green-500/15 text-green-400 border-green-500/25',
  medium: 'bg-yellow-500/15 text-yellow-400 border-yellow-500/25',
  low: 'bg-gray-500/15 text-gray-400 border-gray-500/25',
};

const modeLabels: Record<string, string> = {
  contender: 'Contender',
  competitive: 'Competitive',
  rebuilding: 'Rebuilding',
};
const modeColors: Record<string, string> = {
  contender: 'text-emerald-400',
  competitive: 'text-blue-400',
  rebuilding: 'text-orange-400',
};

const packageTypeLabels: Record<string, string> = {
  player_for_player: 'Player for Player',
  player_plus_pick_for_player: 'Player + Pick for Player',
  player_for_player_plus_picks: 'Player for Player + Picks',
  player_for_picks_only: 'Player for Picks',
  multi_player_swap: 'Multi-Player Swap',
  multi_player_plus_picks: 'Multi-Player + Picks',
};

function ratingColor(r: number) {
  if (r >= 85) return 'text-green-400';
  if (r >= 75) return 'text-blue-400';
  if (r >= 65) return 'text-yellow-400';
  return 'text-red-400';
}

function PackageSide({ side, label, labelColor, onPlayerClick }: { side: TradePackageSide; label: string; labelColor: string; onPlayerClick?: (id: number) => void }) {
  return (
    <div className="flex-1 min-w-0">
      <p className={`text-[10px] font-bold uppercase tracking-widest mb-1.5 ${labelColor}`}>
        {label}
      </p>
      <div className="space-y-1">
        {side.players.map((p) => (
          <div key={p.id} className="flex items-center gap-1.5 rounded-md bg-[var(--bg-elevated)] px-2 py-1.5">
            {p.is_selected && (
              <span className="text-amber-400 text-xs flex-shrink-0" title="Selected player">&#9733;</span>
            )}
            <span className={`font-stat text-xs font-bold flex-shrink-0 ${ratingColor(p.overall_rating)}`}>
              {p.overall_rating}
            </span>
            {onPlayerClick ? (
              <button
                onClick={() => onPlayerClick(p.id)}
                className="text-xs truncate text-left hover:underline hover:text-[var(--accent-blue)] transition-colors"
                title="View player profile"
              >
                {p.name}
              </button>
            ) : (
              <span className="text-xs truncate">{p.name}</span>
            )}
            <div className="flex items-center gap-1.5 flex-shrink-0 ml-auto">
              <Badge variant="outline" className="text-[8px]">{p.position}</Badge>
              <span className="text-[10px] text-[var(--text-muted)]">Age {p.age}</span>
              {p.fills_need && (
                <Badge variant="outline" className="text-[8px] bg-emerald-500/15 text-emerald-400 border-emerald-500/25">NEED</Badge>
              )}
            </div>
          </div>
        ))}
        {side.picks.map((pk) => (
          <div key={`pick-${pk.id}`} className="flex items-center gap-1.5 rounded-md bg-[var(--bg-elevated)] px-2 py-1.5 border border-dashed border-[var(--border)]">
            <span className="text-xs font-bold text-amber-400 flex-shrink-0">R{pk.round}</span>
            <span className="text-xs truncate">{pk.label}</span>
            <div className="flex items-center gap-1.5 flex-shrink-0 ml-auto">
              <Badge variant="outline" className="text-[8px] border-amber-500/30 text-amber-400">PICK</Badge>
              <span className="text-[10px] text-[var(--text-muted)]">Val {pk.trade_value}</span>
            </div>
          </div>
        ))}
      </div>
      <p className="text-[10px] text-[var(--text-muted)] mt-1.5 font-mono">
        Total: {Math.round((side.total_value ?? 0) * 10) / 10}
      </p>
    </div>
  );
}

export function FindTradeModal({ playerId, playerName, open, onOpenChange }: FindTradeModalProps) {
  const navigate = useNavigate();
  const [status, setStatus] = useState<'idle' | 'loading' | 'error' | 'success'>('idle');
  const [result, setResult] = useState<FindTradeResult | null>(null);
  const [errorMsg, setErrorMsg] = useState('');

  const fetchOpportunities = useCallback(async (pid: number) => {
    setStatus('loading');
    setResult(null);
    setErrorMsg('');
    try {
      const res = await fetch('/api/trades/find-opportunities', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: pid }),
      });
      const data = await res.json();
      if (!res.ok) {
        setErrorMsg(data?.error || `Server error (${res.status})`);
        setStatus('error');
        return;
      }
      setResult(data as FindTradeResult);
      setStatus('success');
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : 'Network error');
      setStatus('error');
    }
  }, []);

  useEffect(() => {
    if (open && playerId) {
      fetchOpportunities(playerId);
    } else if (!open) {
      setStatus('idle');
      setResult(null);
      setErrorMsg('');
    }
  }, [open, playerId, fetchOpportunities]);

  const [proposing, setProposing] = useState<number | null>(null);
  const [sweetening, setSweetening] = useState<number | null>(null);

  const handleSweeten = async (opp: TradeOpportunity) => {
    if (!result?.player) return;
    setSweetening(opp.team.id);
    try {
      const res = await fetch('/api/trades/sweeten', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          team_id: opp.team.id,
          player_id: result.player.id,
          current_offer_player_ids: opp.they_send.players.map((p) => p.id),
          current_offer_pick_ids: opp.they_send.picks.map((p) => p.id),
        }),
      });
      const data = await res.json();
      if (!res.ok) {
        toast.error(data?.error || 'Failed to request sweetener');
        return;
      }
      if (data.sweetened) {
        toast.success(data.message);
        // Update the opportunity in-place instead of re-fetching
        setResult((prev) => {
          if (!prev) return prev;
          const updated = { ...prev };
          updated.opportunities = prev.opportunities.map((o) => {
            if (o.team.id !== opp.team.id) return o;
            const newOpp = { ...o, they_send: { ...o.they_send } };
            // Add sweetened player or pick to their side
            if (data.type === 'added_player' && data.added_player) {
              newOpp.they_send = {
                ...newOpp.they_send,
                players: [...newOpp.they_send.players, {
                  id: data.added_player.id,
                  name: data.added_player.name,
                  position: data.added_player.position,
                  overall_rating: data.added_player.overall_rating,
                  age: data.added_player.age,
                  is_selected: false,
                  fills_need: false,
                }],
                total_value: data.new_total_value ?? newOpp.they_send.total_value,
              };
            } else if (data.type === 'added_pick' && data.added_pick) {
              newOpp.they_send = {
                ...newOpp.they_send,
                picks: [...newOpp.they_send.picks, {
                  id: data.added_pick.id,
                  label: data.added_pick.label,
                  round: data.added_pick.round,
                  trade_value: data.added_pick.trade_value,
                }],
                total_value: data.new_total_value ?? newOpp.they_send.total_value,
              };
            }
            return newOpp;
          });
          return updated;
        });
      } else {
        toast.info(data.reason || "They can't improve this offer.");
      }
    } catch {
      toast.error('Network error');
    } finally {
      setSweetening(null);
    }
  };

  const handleProposeTrade = async (opp: TradeOpportunity) => {
    if (!result?.player) return;
    setProposing(opp.team.id);
    try {
      const res = await fetch('/api/trades', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          target_team_id: opp.team.id,
          offering_player_ids: opp.you_send.players.map((p) => p.id),
          offering_pick_ids: opp.you_send.picks.map((p) => p.id),
          requesting_player_ids: opp.they_send.players.map((p) => p.id),
          requesting_pick_ids: opp.they_send.picks.map((p) => p.id),
          pre_agreed: true,
        }),
      });
      const data = await res.json();
      if (!res.ok) {
        toast.error(data?.error || 'Trade proposal failed');
        return;
      }
      const tradeResult = data?.trade ?? data?.data?.trade;
      onOpenChange(false);
      if (tradeResult?.status === 'completed' || tradeResult?.status === 'accepted') {
        toast.success(tradeResult.message || 'Trade accepted! Players have been swapped.', { duration: 5000 });
        navigate('/my-team');
      } else if (tradeResult?.status === 'rejected') {
        toast.error(tradeResult.message || 'Trade rejected by the other team.');
      } else if (tradeResult?.status === 'counter') {
        toast.info(tradeResult.message || 'The other team wants to counter-offer.');
        navigate('/trades');
      } else {
        // Fallback — trade likely succeeded
        toast.success('Trade complete!', { duration: 5000 });
        navigate('/my-team');
      }
    } catch (err) {
      toast.error('Network error proposing trade');
    } finally {
      setProposing(null);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl max-h-[85vh] overflow-y-auto bg-[var(--bg-surface)] border-[var(--border)]">
        <DialogHeader>
          <DialogTitle className="font-display">
            Find a Trade{playerName ? ` for ${playerName}` : ''}
          </DialogTitle>
          <DialogDescription>
            {status === 'loading'
              ? 'Scanning the league for interested teams...'
              : status === 'error'
                ? 'Something went wrong.'
                : status === 'success'
                  ? 'These teams are interested in making a deal.'
                  : 'Preparing search...'}
          </DialogDescription>
        </DialogHeader>

        {status === 'loading' && (
          <div className="flex flex-col items-center justify-center py-12 gap-3">
            <div className="h-8 w-8 animate-spin rounded-full border-2 border-[var(--accent-blue)] border-t-transparent" />
            <p className="text-sm text-[var(--text-secondary)]">Scanning league for trade partners...</p>
          </div>
        )}

        {status === 'error' && (
          <div className="flex flex-col items-center py-8 gap-3">
            <p className="text-sm text-red-400">Failed to find trade opportunities.</p>
            <p className="text-xs text-red-400/70">{errorMsg}</p>
            <Button
              size="sm"
              variant="outline"
              onClick={() => playerId && fetchOpportunities(playerId)}
            >
              Try Again
            </Button>
          </div>
        )}

        {status === 'success' && result && (
          <div className="space-y-4">
            {/* Selected Player Card */}
            {result.player && (
              <Card className="border-[var(--border)] bg-[var(--bg-elevated)]">
                <CardContent className="flex items-center gap-4 p-4">
                  <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-[var(--accent-blue)]/20">
                    <span className="font-display text-lg font-bold text-[var(--accent-blue)]">
                      {result.player.overall_rating}
                    </span>
                  </div>
                  <div className="flex-1">
                    <p className="font-medium">{result.player.name}</p>
                    <div className="flex items-center gap-2 text-xs text-[var(--text-muted)]">
                      <Badge variant="outline" className="text-[10px]">{result.player.position}</Badge>
                      <span>Age {result.player.age}</span>
                      <span>Trade Value: {result.player.trade_value}</span>
                    </div>
                  </div>
                </CardContent>
              </Card>
            )}

            {/* Your Team Needs */}
            {result.team_needs && result.team_needs.length > 0 && (
              <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-elevated)] px-4 py-3">
                <p className="text-[10px] font-bold uppercase tracking-widest text-[var(--text-muted)] mb-2">
                  Your Top Needs
                </p>
                <div className="flex flex-wrap gap-2">
                  {result.team_needs.map((need, i) => (
                    <div
                      key={need.position}
                      className="flex items-center gap-1.5 rounded-md bg-[var(--bg-surface)] px-2.5 py-1.5 border border-[var(--border)]"
                    >
                      <span className="text-xs font-bold text-emerald-400">#{i + 1}</span>
                      <span className="text-xs font-semibold">{need.position}</span>
                      <span className="text-[10px] text-[var(--text-muted)]">
                        {need.roster_count}/{need.ideal_count}
                        {need.best_overall > 0 && <>, best {need.best_overall}</>}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Results */}
            {result.opportunities.length === 0 ? (
              <div className="py-8 text-center">
                <p className="text-sm text-[var(--text-secondary)]">No viable trade partners found for this player.</p>
              </div>
            ) : (
              <div className="space-y-3">
                <p className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                  {result.opportunities.length} Team{result.opportunities.length !== 1 ? 's' : ''} Interested
                </p>
                {result.opportunities.map((opp) => (
                  <Card key={opp.team.id} className="border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
                    <CardContent className="p-4 space-y-3">
                      {/* Team header */}
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                          <TeamLogo
                            abbreviation={opp.team.abbreviation}
                            primaryColor={opp.team.primary_color}
                            secondaryColor={opp.team.secondary_color}
                            size="md"
                          />
                          <div>
                            <p className="text-sm font-medium">{opp.team.city} {opp.team.name}</p>
                            <p className="text-[10px] text-[var(--text-muted)]">
                              {packageTypeLabels[opp.package_type] ?? opp.package_type}
                            </p>
                          </div>
                        </div>
                        <div className="flex items-center gap-2">
                          <Badge variant="outline" className={`text-[10px] font-semibold ${interestColors[opp.interest] ?? ''}`}>
                            {opp.interest}
                          </Badge>
                          {opp.team_mode && (
                            <span className={`text-[10px] font-medium ${modeColors[opp.team_mode] ?? ''}`}>
                              {modeLabels[opp.team_mode] ?? opp.team_mode}
                            </span>
                          )}
                        </div>
                      </div>

                      {/* Two-column trade package */}
                      <div className="flex gap-3">
                        <PackageSide
                          side={opp.you_send}
                          label="You Send"
                          labelColor="text-red-400"
                          onPlayerClick={(id) => {
                            onOpenChange(false);
                            navigate(`/player/${id}`);
                          }}
                        />
                        <div className="flex items-center px-1">
                          <span className="text-[var(--text-muted)] text-lg">&#8644;</span>
                        </div>
                        <PackageSide
                          side={opp.they_send}
                          label="They Send"
                          labelColor="text-green-400"
                          onPlayerClick={(id) => {
                            onOpenChange(false);
                            navigate(`/player/${id}`);
                          }}
                        />
                      </div>

                      {/* GM Analysis */}
                      {opp.gm_note && (
                        <div className="border-t border-[var(--border)] pt-2 space-y-1">
                          <p className="text-[10px] font-semibold uppercase tracking-wider text-[var(--accent-gold)]">Your GM&apos;s Take</p>
                          <p className="text-xs leading-relaxed text-[var(--text-secondary)]">
                            {opp.gm_note}
                          </p>
                        </div>
                      )}

                      {/* Footer */}
                      <div className="flex items-center justify-between pt-1">
                        <div className="flex items-center gap-2 text-xs text-[var(--text-muted)]">
                          <span>Their interest: {opp.reason}</span>
                        </div>
                        <div className="flex items-center gap-3">
                          <span className="text-xs font-mono text-[var(--text-muted)]">
                            {Math.round(opp.fairness * 100)}% fair
                          </span>
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => handleSweeten(opp)}
                            disabled={sweetening === opp.team.id}
                            className="text-xs"
                          >
                            {sweetening === opp.team.id ? 'Asking...' : 'Sweeten the Deal'}
                          </Button>
                          <Button size="sm" onClick={() => handleProposeTrade(opp)} disabled={proposing === opp.team.id} className="bg-green-600 hover:bg-green-700">
                            {proposing === opp.team.id ? 'Processing...' : 'Accept Trade'}
                          </Button>
                        </div>
                      </div>
                    </CardContent>
                  </Card>
                ))}
              </div>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
