import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { TeamLogo } from '@/components/TeamLogo';
import { toast } from 'sonner';
import type { AcquirePlayerResult, AcquirePackage } from '@/api/client';

interface AcquireTradeModalProps {
  playerId: number | null;
  playerName?: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

function ratingColor(r: number) {
  if (r >= 85) return 'text-green-400';
  if (r >= 75) return 'text-blue-400';
  if (r >= 65) return 'text-yellow-400';
  return 'text-red-400';
}

const gmLabels: Record<string, string> = {
  aggressive: 'Aggressive',
  conservative: 'Conservative',
  analytics: 'Analytics',
  old_school: 'Old School',
  balanced: 'Balanced',
};

export function AcquireTradeModal({ playerId, playerName, open, onOpenChange }: AcquireTradeModalProps) {
  const navigate = useNavigate();
  const [status, setStatus] = useState<'idle' | 'loading' | 'error' | 'success'>('idle');
  const [result, setResult] = useState<AcquirePlayerResult | null>(null);
  const [errorMsg, setErrorMsg] = useState('');
  const [proposing, setProposing] = useState<number | null>(null);

  const fetchPackages = useCallback(async (pid: number) => {
    setStatus('loading');
    setResult(null);
    setErrorMsg('');
    try {
      const res = await fetch('/api/trades/acquire', {
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
      setResult(data as AcquirePlayerResult);
      setStatus('success');
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : 'Network error');
      setStatus('error');
    }
  }, []);

  useEffect(() => {
    if (open && playerId) {
      fetchPackages(playerId);
    } else if (!open) {
      setStatus('idle');
      setResult(null);
      setErrorMsg('');
    }
  }, [open, playerId, fetchPackages]);

  const handlePropose = async (pkg: AcquirePackage) => {
    if (!result?.player || !result.team) return;
    setProposing(pkg.total_value);
    try {
      const res = await fetch('/api/trades', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          target_team_id: result.team.id,
          offering_player_ids: pkg.players.map((p) => p.id),
          offering_pick_ids: pkg.picks.map((p) => p.id),
          requesting_player_ids: [result.player.id],
          requesting_pick_ids: [],
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
        toast.success(tradeResult.message || `Trade complete! ${result.player.name} is now on your team.`, { duration: 5000 });
        navigate('/my-team');
      } else {
        toast.success('Trade complete!', { duration: 5000 });
        navigate('/my-team');
      }
    } catch {
      toast.error('Network error proposing trade');
    } finally {
      setProposing(null);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto bg-[var(--bg-surface)] border-[var(--border)]">
        <DialogHeader>
          <DialogTitle className="font-display text-xl">
            Acquire {playerName || 'Player'}
          </DialogTitle>
          <DialogDescription className="text-[var(--text-secondary)]">
            What it would cost to get this player on your team.
          </DialogDescription>
        </DialogHeader>

        {status === 'loading' && (
          <div className="flex items-center justify-center py-12">
            <div className="flex items-center gap-3 text-[var(--text-secondary)]">
              <div className="h-5 w-5 animate-spin rounded-full border-2 border-[var(--accent-blue)] border-t-transparent" />
              <span className="text-sm">Their GM is reviewing...</span>
            </div>
          </div>
        )}

        {status === 'error' && (
          <div className="rounded-lg bg-red-500/10 border border-red-500/20 p-4 text-center">
            <p className="text-sm text-red-400">{errorMsg}</p>
          </div>
        )}

        {status === 'success' && result && (
          <div className="space-y-4">
            {/* Player info + team info */}
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div>
                  <p className="font-display text-lg">{result.player.name}</p>
                  <p className="text-xs text-[var(--text-secondary)]">
                    {result.player.position} &middot; {result.player.overall_rating} OVR &middot; Age {result.player.age}
                  </p>
                </div>
              </div>
              {result.team && (
                <div className="flex items-center gap-2">
                  <TeamLogo
                    abbreviation={result.team.abbreviation}
                    primaryColor={result.team.primary_color}
                    secondaryColor={result.team.secondary_color}
                    size="sm"
                  />
                  <div className="text-right">
                    <p className="text-sm font-medium">{result.team.city} {result.team.name}</p>
                    <p className="text-[10px] text-[var(--text-muted)]">
                      {gmLabels[result.team.gm_personality] ?? 'Unknown'} GM &middot; {result.team.mode}
                    </p>
                  </div>
                </div>
              )}
            </div>

            {/* Not available */}
            {!result.available && (
              <div className="rounded-lg bg-red-500/10 border border-red-500/20 p-4">
                <p className="text-sm font-medium text-red-400 mb-1">Not Available</p>
                <p className="text-xs text-[var(--text-secondary)]">{result.reason}</p>
              </div>
            )}

            {/* Their needs */}
            {result.their_needs && result.their_needs.length > 0 && (
              <div>
                <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)] mb-2">
                  What They Need
                </p>
                <div className="flex flex-wrap gap-2">
                  {result.their_needs.map((n) => (
                    <Badge key={n.position} variant="outline" className="text-[10px] border-[var(--border)]">
                      {n.position} (best: {n.best_overall})
                    </Badge>
                  ))}
                </div>
              </div>
            )}

            {/* Packages */}
            {result.available && result.packages.length > 0 && (
              <div className="space-y-3">
                <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">
                  {result.packages.length} Package{result.packages.length !== 1 ? 's' : ''} Available
                </p>
                {result.packages.map((pkg, idx) => (
                  <Card key={idx} className="border-[var(--border)] bg-[var(--bg-primary)] overflow-hidden">
                    <CardContent className="p-4 space-y-3">
                      <div className="flex items-center justify-between">
                        <p className="text-xs font-semibold text-[var(--text-muted)]">
                          Package {idx + 1}
                        </p>
                        <span className="text-xs font-mono text-[var(--text-muted)]">
                          Value: {pkg.total_value}
                          {result.asking_price && (
                            <span className="ml-2">
                              ({Math.round((pkg.total_value / result.asking_price) * 100)}% of ask)
                            </span>
                          )}
                        </span>
                      </div>

                      <div className="space-y-1">
                        <p className="text-[10px] font-bold uppercase tracking-wider text-orange-400">You Send</p>
                        {pkg.players.map((p) => (
                          <div key={p.id} className="flex items-center justify-between py-1 px-2 rounded bg-[var(--bg-surface)]">
                            <span className="text-sm">{p.name}</span>
                            <span className="text-xs text-[var(--text-secondary)]">
                              <span className={ratingColor(p.overall_rating)}>{p.overall_rating}</span>
                              {' '}{p.position} &middot; {p.age}yo
                              {p.fills_need && (
                                <Badge variant="outline" className="ml-2 text-[9px] bg-green-500/10 text-green-400 border-green-500/20">
                                  Fills Their Need
                                </Badge>
                              )}
                            </span>
                          </div>
                        ))}
                        {pkg.picks.map((pk) => (
                          <div key={pk.id} className="flex items-center justify-between py-1 px-2 rounded bg-[var(--bg-surface)]">
                            <span className="text-sm">{pk.label}</span>
                            <span className="text-xs text-[var(--accent-gold)]">Draft Pick</span>
                          </div>
                        ))}
                      </div>

                      <div className="flex items-center justify-between pt-1">
                        <p className="text-[10px] font-bold uppercase tracking-wider text-green-400">
                          You Receive: {result.player.name}
                        </p>
                        <Button
                          size="sm"
                          onClick={() => handlePropose(pkg)}
                          disabled={proposing !== null}
                        >
                          {proposing === pkg.total_value ? 'Proposing...' : 'Make This Trade'}
                        </Button>
                      </div>
                    </CardContent>
                  </Card>
                ))}
              </div>
            )}

            {result.available && result.packages.length === 0 && (
              <div className="rounded-lg bg-yellow-500/10 border border-yellow-500/20 p-4 text-center">
                <p className="text-sm text-yellow-400">
                  They&apos;d consider trading this player, but we don&apos;t have enough matching assets to put together a deal right now.
                </p>
              </div>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
