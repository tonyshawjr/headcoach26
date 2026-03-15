import { useState } from 'react';
import { usePlayer, usePlayerStats } from '@/hooks/useApi';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { motion } from 'framer-motion';
import { ratingColor } from '@/lib/formatters';
import { Search, X, ArrowRight } from 'lucide-react';

interface PlayerComparisonProps {
  playerId: number;
  playerName: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

interface SearchResult {
  id: number;
  first_name: string;
  last_name: string;
  position: string;
  overall_rating: number;
  team_name?: string;
}

// Stats to compare by position group
const QB_STATS = ['pass_yards', 'pass_tds', 'interceptions', 'rush_yards'];
const RB_STATS = ['rush_yards', 'rush_tds', 'rec_yards', 'receptions'];
const WR_STATS = ['rec_yards', 'rec_tds', 'receptions', 'rush_yards'];
const DEF_STATS = ['tackles', 'sacks', 'interceptions_def', 'forced_fumbles'];

// Rating keys to compare by position
const QB_RATINGS = ['throw_power', 'throw_accuracy_short', 'throw_accuracy_mid', 'throw_accuracy_deep', 'awareness', 'speed'];
const RB_RATINGS = ['speed', 'acceleration', 'agility', 'carrying', 'bc_vision', 'strength'];
const WR_RATINGS = ['speed', 'acceleration', 'catching', 'route_running', 'agility', 'awareness'];
const DEF_RATINGS = ['speed', 'strength', 'tackle', 'awareness', 'pursuit', 'play_recognition'];

function getStatsForPosition(pos: string): string[] {
  if (['QB'].includes(pos)) return QB_STATS;
  if (['RB', 'FB', 'HB'].includes(pos)) return RB_STATS;
  if (['WR', 'TE'].includes(pos)) return WR_STATS;
  return DEF_STATS;
}

function getRatingsForPosition(pos: string): string[] {
  if (['QB'].includes(pos)) return QB_RATINGS;
  if (['RB', 'FB', 'HB'].includes(pos)) return RB_RATINGS;
  if (['WR', 'TE'].includes(pos)) return WR_RATINGS;
  return DEF_RATINGS;
}

function formatStatLabel(key: string): string {
  return key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function ComparisonBar({ label, leftVal, rightVal }: { label: string; leftVal: number; rightVal: number }) {
  const max = Math.max(leftVal, rightVal, 1);
  const leftPct = (leftVal / max) * 100;
  const rightPct = (rightVal / max) * 100;
  const leftWins = leftVal > rightVal;
  const rightWins = rightVal > leftVal;
  const tie = leftVal === rightVal;

  return (
    <div className="space-y-1">
      <div className="flex items-center justify-between text-xs">
        <span className={`font-mono font-semibold ${leftWins ? 'text-green-400' : tie ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'}`}>
          {leftVal}
        </span>
        <span className="text-[10px] uppercase tracking-wider text-[var(--text-muted)]">{label}</span>
        <span className={`font-mono font-semibold ${rightWins ? 'text-green-400' : tie ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'}`}>
          {rightVal}
        </span>
      </div>
      <div className="flex gap-1 h-1.5">
        <div className="flex-1 flex justify-end overflow-hidden rounded-l-full bg-white/5">
          <motion.div
            className={`h-full rounded-l-full ${leftWins ? 'bg-green-500' : 'bg-[var(--accent-blue)]'}`}
            initial={{ width: 0 }}
            animate={{ width: `${leftPct}%` }}
            transition={{ duration: 0.5 }}
          />
        </div>
        <div className="flex-1 overflow-hidden rounded-r-full bg-white/5">
          <motion.div
            className={`h-full rounded-r-full ${rightWins ? 'bg-green-500' : 'bg-[var(--accent-blue)]'}`}
            initial={{ width: 0 }}
            animate={{ width: `${rightPct}%` }}
            transition={{ duration: 0.5 }}
          />
        </div>
      </div>
    </div>
  );
}

export function PlayerComparison({ playerId, playerName, open, onOpenChange }: PlayerComparisonProps) {
  const [search, setSearch] = useState('');
  const [searchResults, setSearchResults] = useState<SearchResult[]>([]);
  const [searching, setSearching] = useState(false);
  const [compareId, setCompareId] = useState<number | null>(null);

  const { data: rawPlayer1 } = usePlayer(playerId);
  const { data: rawPlayer2 } = usePlayer(compareId ?? undefined);
  const { data: rawStats1 } = usePlayerStats(playerId);
  const { data: rawStats2 } = usePlayerStats(compareId ?? undefined);

  const player1 = (rawPlayer1 as any)?.player;
  const player2 = (rawPlayer2 as any)?.player;
  const ratings1 = (rawPlayer1 as any)?.ratings ?? {};
  const ratings2 = (rawPlayer2 as any)?.ratings ?? {};
  const team1 = (rawPlayer1 as any)?.team;
  const team2 = (rawPlayer2 as any)?.team;
  const stats1 = (rawStats1 as any)?.season ?? {};
  const stats2 = (rawStats2 as any)?.season ?? {};

  async function handleSearch() {
    if (!search.trim()) return;
    setSearching(true);
    try {
      // Use the roster endpoint to find players by searching rosters
      // We'll search through a simple approach using available API
      const res = await fetch(`/api/players/search?q=${encodeURIComponent(search.trim())}`, {
        credentials: 'include',
      });
      if (res.ok) {
        const data = await res.json();
        setSearchResults(data.players ?? []);
      } else {
        setSearchResults([]);
      }
    } catch {
      setSearchResults([]);
    } finally {
      setSearching(false);
    }
  }

  function selectPlayer(id: number) {
    setCompareId(id);
    setSearch('');
    setSearchResults([]);
  }

  function resetComparison() {
    setCompareId(null);
    setSearch('');
    setSearchResults([]);
  }

  // Get all ratings as flat key-value from grouped ratings
  function flattenRatings(grouped: Record<string, Record<string, number>>): Record<string, number> {
    const flat: Record<string, number> = {};
    for (const cat of Object.values(grouped)) {
      if (typeof cat === 'object') {
        for (const [k, v] of Object.entries(cat)) {
          if (typeof v === 'number') flat[k] = v;
        }
      }
    }
    return flat;
  }

  const flatRatings1 = flattenRatings(ratings1);
  const flatRatings2 = flattenRatings(ratings2);

  const pos = player1?.position ?? 'DEF';
  const ratingKeys = getRatingsForPosition(pos);
  const statKeys = getStatsForPosition(pos);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl bg-[var(--bg-surface)] border-[var(--border)] text-[var(--text-primary)]">
        <DialogHeader>
          <DialogTitle className="font-display text-lg">Player Comparison</DialogTitle>
        </DialogHeader>

        {/* Search for second player */}
        {!compareId && (
          <div className="space-y-3">
            <p className="text-sm text-[var(--text-secondary)]">
              Comparing <strong>{playerName}</strong>. Search for a player to compare against:
            </p>
            <div className="flex gap-2">
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[var(--text-muted)]" />
                <input
                  type="text"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                  placeholder="Search player name..."
                  className="w-full rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] pl-9 pr-3 py-2 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:outline-none focus:ring-1 focus:ring-[var(--accent-blue)]"
                />
              </div>
              <Button size="sm" onClick={handleSearch} disabled={searching}>
                {searching ? 'Searching...' : 'Search'}
              </Button>
            </div>

            {searchResults.length > 0 && (
              <div className="max-h-48 overflow-y-auto space-y-1 rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-2">
                {searchResults.filter(r => r.id !== playerId).map((r) => (
                  <button
                    key={r.id}
                    onClick={() => selectPlayer(r.id)}
                    className="flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm hover:bg-white/5 transition-colors"
                  >
                    <span className="flex-1 text-left truncate">{r.first_name} {r.last_name}</span>
                    <Badge variant="outline" className="text-[10px]">{r.position}</Badge>
                    <span className={`font-mono font-semibold ${ratingColor(r.overall_rating)}`}>{r.overall_rating}</span>
                    <ArrowRight className="h-3 w-3 text-[var(--text-muted)]" />
                  </button>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Comparison View */}
        {compareId && player1 && player2 && (
          <div className="space-y-5">
            {/* Player Headers */}
            <div className="grid grid-cols-[1fr_auto_1fr] gap-4 items-center">
              <div className="text-center">
                <p className="font-display text-lg">{player1.first_name} {player1.last_name}</p>
                <div className="flex items-center justify-center gap-2 mt-1">
                  <Badge variant="outline" className="text-[10px]">{player1.position}</Badge>
                  <span className={`font-display text-2xl ${ratingColor(player1.overall_rating)}`}>{player1.overall_rating}</span>
                </div>
                {team1 && (
                  <p className="text-xs text-[var(--text-muted)] mt-1">{team1.city} {team1.name}</p>
                )}
                <p className="text-xs text-[var(--text-muted)]">Age {player1.age}</p>
              </div>

              <div className="text-center">
                <span className="text-xs font-bold uppercase tracking-widest text-[var(--text-muted)]">VS</span>
              </div>

              <div className="text-center">
                <p className="font-display text-lg">{player2.first_name} {player2.last_name}</p>
                <div className="flex items-center justify-center gap-2 mt-1">
                  <Badge variant="outline" className="text-[10px]">{player2.position}</Badge>
                  <span className={`font-display text-2xl ${ratingColor(player2.overall_rating)}`}>{player2.overall_rating}</span>
                </div>
                {team2 && (
                  <p className="text-xs text-[var(--text-muted)] mt-1">{team2.city} {team2.name}</p>
                )}
                <p className="text-xs text-[var(--text-muted)]">Age {player2.age}</p>
              </div>
            </div>

            {/* Overall Rating Bar */}
            <ComparisonBar
              label="Overall"
              leftVal={player1.overall_rating}
              rightVal={player2.overall_rating}
            />

            {/* Ratings Comparison */}
            {ratingKeys.length > 0 && (
              <div>
                <h3 className="text-[10px] font-bold uppercase tracking-widest text-[var(--text-muted)] mb-3">Ratings</h3>
                <div className="space-y-2">
                  {ratingKeys.map((key) => {
                    const v1 = flatRatings1[key] ?? 0;
                    const v2 = flatRatings2[key] ?? 0;
                    if (v1 === 0 && v2 === 0) return null;
                    return (
                      <ComparisonBar
                        key={key}
                        label={formatStatLabel(key)}
                        leftVal={v1}
                        rightVal={v2}
                      />
                    );
                  })}
                </div>
              </div>
            )}

            {/* Stats Comparison */}
            {(Object.keys(stats1).length > 0 || Object.keys(stats2).length > 0) && (
              <div>
                <h3 className="text-[10px] font-bold uppercase tracking-widest text-[var(--text-muted)] mb-3">Season Stats</h3>
                <div className="space-y-2">
                  {statKeys.map((key) => {
                    const v1 = stats1[key] ?? 0;
                    const v2 = stats2[key] ?? 0;
                    if (v1 === 0 && v2 === 0) return null;
                    return (
                      <ComparisonBar
                        key={key}
                        label={formatStatLabel(key)}
                        leftVal={v1}
                        rightVal={v2}
                      />
                    );
                  })}
                </div>
              </div>
            )}

            {/* Reset */}
            <div className="flex justify-center pt-2">
              <Button variant="outline" size="sm" onClick={resetComparison}>
                <X className="h-3 w-3 mr-1" />
                Compare Different Player
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
