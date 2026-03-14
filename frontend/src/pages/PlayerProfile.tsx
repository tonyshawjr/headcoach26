import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { usePlayer, usePlayerStats, usePlayerGameLog } from '@/hooks/useApi';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { FindTradeModal } from '@/components/FindTradeModal';
import { Separator } from '@/components/ui/separator';
import {
  formatStatName, formatHeight, ratingColor, ratingBgColor, CATEGORY_LABELS,
} from '@/lib/formatters';
import {
  ArrowLeft, Heart, Brain, TrendingUp, Shield, Zap, Star, Activity,
  ChevronRight, Award, Dumbbell, Target, Eye,
} from 'lucide-react';

// --- Types ---

interface PlayerData {
  id: number;
  first_name: string;
  last_name: string;
  position: string;
  age: number;
  overall_rating: number;
  jersey_number: number;
  height?: number;
  weight?: number;
  handedness?: string;
  birthdate?: string;
  years_pro?: number;
  college?: string;
  archetype?: string;
  position_type?: string;
  edge?: string | null;
  instincts?: string[] | null;
  potential?: string;
  personality?: string;
  morale?: string;
  experience?: number;
  status?: string;
  running_style?: string;
  speed?: number;
  strength?: number;
  awareness?: number;
  stamina?: number;
  positional_ratings?: Record<string, number>;
}

type RatingsMap = Record<string, Record<string, number>>;

interface PlayerDetailResponse {
  player: PlayerData;
  team: { id: number; city: string; name: string; abbreviation: string; primary_color: string } | null;
  contract: { years_remaining: number; salary: number } | null;
  injury: { type: string; severity: string; weeks_remaining: number } | null;
  ratings?: RatingsMap;
  free_agent?: { free_agent_id: number; market_value: number; asking_salary: number } | null;
}

interface StatsResponse {
  season: Record<string, number> | null;
  career: Record<string, number> | null;
}

interface GameLogResponse {
  games: Array<{
    week: number; opponent: string; result: string; score: string;
    stats: Record<string, number>;
  }>;
}

// --- Helpers ---

function overallBorderColor(rating: number): string {
  if (rating >= 90) return 'border-green-500';
  if (rating >= 80) return 'border-blue-500';
  if (rating >= 70) return 'border-yellow-500';
  return 'border-red-500';
}

function overallTextColor(rating: number): string {
  if (rating >= 90) return 'text-green-400';
  if (rating >= 80) return 'text-blue-400';
  if (rating >= 70) return 'text-yellow-400';
  return 'text-red-400';
}

function ratingBarColor(rating: number): string {
  if (rating >= 90) return 'bg-green-500';
  if (rating >= 80) return 'bg-green-600';
  if (rating >= 70) return 'bg-yellow-500';
  if (rating >= 50) return 'bg-orange-500';
  return 'bg-red-500';
}

function formatSalary(salary: number): string {
  if (salary >= 1_000_000) return `$${(salary / 1_000_000).toFixed(1)}M`;
  if (salary >= 1_000) return `$${(salary / 1_000).toFixed(0)}K`;
  return `$${salary}`;
}

/** Capitalize a snake_case or lowercase string for display (e.g. "team_player" → "Team Player") */
function formatLabel(value: string): string {
  return value
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

/** Morale color based on the string value */
function moraleColor(morale: string): string {
  const m = morale.toLowerCase();
  if (m === 'ecstatic') return 'text-green-400';
  if (m === 'happy') return 'text-blue-400';
  if (m === 'content') return 'text-yellow-400';
  if (m === 'frustrated') return 'text-orange-400';
  return 'text-red-400'; // angry
}

function moraleBgColor(morale: string): string {
  const m = morale.toLowerCase();
  if (m === 'ecstatic') return 'bg-green-500';
  if (m === 'happy') return 'bg-blue-500';
  if (m === 'content') return 'bg-yellow-500';
  if (m === 'frustrated') return 'bg-orange-500';
  return 'bg-red-500';
}

function moralePercent(morale: string): number {
  const m = morale.toLowerCase();
  if (m === 'ecstatic') return 100;
  if (m === 'happy') return 75;
  if (m === 'content') return 50;
  if (m === 'frustrated') return 25;
  return 10; // angry
}

function getMoraleDescription(morale: string, firstName: string): string {
  const m = morale.toLowerCase();
  const label = formatLabel(morale);
  if (m === 'ecstatic') return `${firstName} is **${label}** — morale is boosting his overall performance.`;
  if (m === 'happy') return `${firstName} is **${label}** — morale is positively impacting his play.`;
  if (m === 'content') return `${firstName} is **${label}** — morale is not currently impacting his overall.`;
  if (m === 'frustrated') return `${firstName} is **${label}** — morale is negatively affecting his performance.`;
  return `${firstName} is **${label}** — morale is severely hurting his overall rating.`;
}

function getDevelopmentLabel(potential?: string): string {
  if (!potential) return 'Average';
  const map: Record<string, string> = {
    'elite': 'Elite',
    'high': 'High',
    'average': 'Average',
    'limited': 'Limited',
    // Legacy values (pre-rename)
    'superstar': 'Elite',
    'star': 'High',
    'normal': 'Average',
    'slow': 'Limited',
  };
  return map[potential.toLowerCase()] ?? formatLabel(potential);
}

// --- Madden-style Rating Number ---

function RatingNumber({ label, value, size = 'normal' }: { label: string; value: number; size?: 'normal' | 'large' }) {
  const textSize = size === 'large' ? 'text-5xl' : 'text-4xl';
  return (
    <div className="flex flex-col gap-1">
      <span className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
        {label}
      </span>
      <span className={`font-display ${textSize} leading-none ${ratingColor(value)}`}>
        {value}
      </span>
      <div className="mt-0.5 h-[3px] w-full overflow-hidden rounded-full bg-white/10">
        <div
          className={`h-full rounded-full transition-all ${ratingBarColor(value)}`}
          style={{ width: `${value}%` }}
        />
      </div>
    </div>
  );
}

// --- Inline Markdown Bold ---

function BoldText({ text }: { text: string }) {
  const parts = text.split(/\*\*(.*?)\*\*/g);
  return (
    <span>
      {parts.map((part, i) =>
        i % 2 === 1 ? <strong key={i} className="font-bold text-[var(--text-primary)]">{part}</strong> : part
      )}
    </span>
  );
}

// --- Main Component ---

export default function PlayerProfile() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { data: rawData, isLoading } = usePlayer(Number(id));
  const { data: rawStats } = usePlayerStats(Number(id));
  const { data: rawGameLog } = usePlayerGameLog(Number(id));
  const [tradeModalOpen, setTradeModalOpen] = useState(false);
  const [signing, setSigning] = useState(false);

  if (isLoading) {
    return (
      <div className="flex h-96 items-center justify-center">
        <div className="flex flex-col items-center gap-3">
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-[var(--accent-blue)] border-t-transparent" />
          <p className="text-sm text-[var(--text-secondary)]">Loading player...</p>
        </div>
      </div>
    );
  }

  const detail = rawData as unknown as PlayerDetailResponse | undefined;
  const player = detail?.player;
  const team = detail?.team;
  const contract = detail?.contract;
  const injury = detail?.injury;
  const ratings = detail?.ratings;
  const freeAgent = detail?.free_agent;
  const stats = rawStats as unknown as StatsResponse | undefined;
  const gameLog = rawGameLog as unknown as GameLogResponse['games'] | undefined;

  async function handleSign() {
    if (!freeAgent) return;
    setSigning(true);
    try {
      const res = await fetch(`/api/free-agents/${freeAgent.free_agent_id}/bid`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          salary_offer: freeAgent.market_value,
          years_offer: 1,
        }),
      });
      const data = await res.json();
      if (!res.ok) {
        alert(data?.error || 'Failed to sign player');
        return;
      }
      alert(`Offer submitted for ${player?.first_name} ${player?.last_name} at ${formatSalary(freeAgent.market_value)}/yr!`);
      navigate('/free-agency');
    } catch {
      alert('Network error');
    } finally {
      setSigning(false);
    }
  }

  if (!player) {
    return (
      <div className="flex h-96 items-center justify-center">
        <p className="text-[var(--text-secondary)]">Player not found.</p>
      </div>
    );
  }

  // Build ratings
  const hasGroupedRatings = ratings && Object.keys(ratings).length > 0;
  const fallbackRatings: RatingsMap = {};
  if (!hasGroupedRatings) {
    const coreStats: Record<string, number> = {};
    if (player.speed != null) coreStats.speed = player.speed;
    if (player.strength != null) coreStats.strength = player.strength;
    if (player.awareness != null) coreStats.awareness = player.awareness;
    if (player.stamina != null) coreStats.stamina = player.stamina;
    if (Object.keys(coreStats).length > 0) fallbackRatings.physical = coreStats;
    if (player.positional_ratings && Object.keys(player.positional_ratings).length > 0) {
      fallbackRatings.positional = player.positional_ratings;
    }
  }
  const displayRatings = hasGroupedRatings ? ratings : fallbackRatings;
  const ratingCategories = Object.keys(displayRatings);

  // Separate core vs secondary categories
  const coreCategories = ['physical', 'ball_carrier', 'receiving', 'quarterback'];
  const secondaryCategories = ratingCategories.filter(c => !coreCategories.includes(c));
  const primaryCategories = ratingCategories.filter(c => coreCategories.includes(c));

  const abilities = player.instincts ?? [];

  // Get position rank text
  const positionName = CATEGORY_LABELS[player.position_type ?? ''] ?? player.position;

  return (
    <div className="mx-auto max-w-6xl">
      {/* Back Button */}
      <button
        onClick={() => navigate(-1)}
        className="mb-4 flex items-center gap-1.5 text-sm text-[var(--text-muted)] transition-colors hover:text-[var(--text-primary)]"
      >
        <ArrowLeft className="h-4 w-4" />
        Back
      </button>

      {/* ===== PLAYER HEADER - Madden Style ===== */}
      <div className="relative mb-0 overflow-hidden rounded-t-xl bg-gradient-to-br from-[var(--bg-surface)] via-[var(--bg-surface)] to-[var(--bg-elevated)]">
        {/* Subtle background pattern */}
        <div className="absolute inset-0 opacity-[0.03]"
          style={{
            backgroundImage: `radial-gradient(circle at 20% 50%, ${team?.primary_color ?? 'var(--accent-blue)'} 0%, transparent 50%)`,
          }}
        />

        <div className="relative flex flex-col sm:flex-row items-start sm:items-center gap-6 p-6 sm:p-8">
          {/* Team Color Accent Bar */}
          <div
            className="absolute left-0 top-0 bottom-0 w-1.5"
            style={{ backgroundColor: team?.primary_color ?? 'var(--accent-blue)' }}
          />

          {/* Player Identity */}
          <div className="flex-1 pl-4">
            <div className="flex items-center gap-2 mb-1">
              <span className="text-sm font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                {player.position} #{player.jersey_number}
              </span>
              {team && (
                <>
                  <span className="text-[var(--text-muted)]">|</span>
                  <span className="text-sm text-[var(--text-secondary)]">
                    {team.city} {team.name}
                  </span>
                </>
              )}
            </div>

            <h1 className="font-display leading-tight">
              <span className="block text-2xl text-[var(--text-secondary)]">{player.first_name}</span>
              <span className="block text-5xl sm:text-6xl tracking-tight">{player.last_name}</span>
            </h1>

            {/* Abilities Row */}
            {(player.edge || abilities.length > 0) && (
              <div className="mt-3 flex flex-wrap items-center gap-2">
                {player.edge && (
                  <span className="inline-flex items-center gap-1.5 rounded border border-yellow-500/40 bg-yellow-500/10 px-3 py-1 text-xs font-bold uppercase tracking-wider text-yellow-400">
                    <Zap className="h-3 w-3" />
                    Edge: {player.edge}
                  </span>
                )}
                {abilities.map((ability) => (
                  <span
                    key={ability}
                    className="inline-flex items-center gap-1 rounded border border-purple-500/40 bg-purple-500/10 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-purple-400"
                  >
                    <Star className="h-2.5 w-2.5" />
                    {ability}
                  </span>
                ))}
              </div>
            )}
          </div>

          {/* OVR Rating Badge */}
          <div className={`flex h-24 w-24 shrink-0 flex-col items-center justify-center rounded-lg border-2 ${overallBorderColor(player.overall_rating)} bg-[var(--bg-primary)]`}>
            <span className={`font-display text-5xl leading-none ${overallTextColor(player.overall_rating)}`}>
              {player.overall_rating}
            </span>
            <span className="text-[10px] font-bold uppercase tracking-widest text-[var(--text-muted)]">
              OVR
            </span>
          </div>
        </div>

        {/* Physical Info Bar */}
        <div className="flex flex-wrap items-center gap-x-5 gap-y-1 border-t border-white/5 bg-black/20 px-8 py-3 pl-10 text-xs">
          {player.height != null && (
            <div>
              <span className="text-[var(--text-muted)]">HT </span>
              <span className="font-semibold">{formatHeight(player.height)}</span>
            </div>
          )}
          {player.weight != null && (
            <div>
              <span className="text-[var(--text-muted)]">WT </span>
              <span className="font-semibold">{player.weight} lbs</span>
            </div>
          )}
          <div>
            <span className="text-[var(--text-muted)]">AGE </span>
            <span className="font-semibold">{player.age}</span>
          </div>
          {player.college && (
            <div>
              <span className="text-[var(--text-muted)]">COLLEGE </span>
              <span className="font-semibold">{player.college}</span>
            </div>
          )}
          {player.years_pro != null && (
            <div>
              <span className="text-[var(--text-muted)]">EXP </span>
              <span className="font-semibold">{player.years_pro} YR{player.years_pro !== 1 ? 'S' : ''}</span>
            </div>
          )}
          {player.handedness && (
            <div>
              <span className="text-[var(--text-muted)]">THROWS </span>
              <span className="font-semibold">{player.handedness}</span>
            </div>
          )}
          {player.archetype && (
            <div>
              <span className="text-[var(--text-muted)]">BLUEPRINT </span>
              <span className="font-semibold">{player.archetype}</span>
            </div>
          )}
          {contract && (
            <div>
              <span className="text-[var(--text-muted)]">CONTRACT </span>
              <span className="font-semibold">{formatSalary(contract.salary)}/yr, {contract.years_remaining}yr</span>
            </div>
          )}
          {injury && (
            <Badge variant="destructive" className="text-[10px]">
              {injury.type} — {injury.severity} ({injury.weeks_remaining}w)
            </Badge>
          )}
        </div>

        {/* Free Agent Sign Bar */}
        {freeAgent && (
          <div className="flex items-center justify-between border-t border-white/5 bg-green-500/[0.04] px-8 py-3 pl-10">
            <div className="flex items-center gap-4 text-sm">
              <Badge className="bg-green-500/10 text-green-400 border-green-500/20">Free Agent</Badge>
              <span className="text-[var(--text-secondary)]">
                Market Value: <span className="font-semibold text-[var(--text-primary)]">{formatSalary(freeAgent.market_value)}</span>/yr
              </span>
            </div>
            <Button
              size="sm"
              onClick={handleSign}
              disabled={signing}
              className="bg-green-600 hover:bg-green-700"
            >
              {signing ? 'Signing...' : `Sign Player — ${formatSalary(freeAgent.market_value)}/yr`}
            </Button>
          </div>
        )}
      </div>

      {/* ===== TABBED CONTENT - Madden Tab Strip ===== */}
      <Tabs defaultValue="ratings">
        <div className="rounded-b-none border-b border-white/5 bg-[var(--bg-surface)]">
          <TabsList variant="line" className="h-auto gap-0 rounded-none bg-transparent px-4">
            <TabsTrigger value="overview" className="rounded-none px-5 py-3 text-xs font-semibold uppercase tracking-wider">
              Overview
            </TabsTrigger>
            <TabsTrigger value="ratings" className="rounded-none px-5 py-3 text-xs font-semibold uppercase tracking-wider">
              Ratings
            </TabsTrigger>
            <TabsTrigger value="stats" className="rounded-none px-5 py-3 text-xs font-semibold uppercase tracking-wider">
              Stats
            </TabsTrigger>
            <TabsTrigger value="gamelog" className="rounded-none px-5 py-3 text-xs font-semibold uppercase tracking-wider">
              Game Log
            </TabsTrigger>
            <TabsTrigger value="health" className="rounded-none px-5 py-3 text-xs font-semibold uppercase tracking-wider">
              Health
            </TabsTrigger>
            <TabsTrigger value="traits" className="rounded-none px-5 py-3 text-xs font-semibold uppercase tracking-wider">
              Traits
            </TabsTrigger>
          </TabsList>
        </div>

        {/* ===== OVERVIEW TAB ===== */}
        <TabsContent value="overview" className="mt-0">
          <div className="rounded-b-xl bg-[var(--bg-surface)] p-6 sm:p-8">
            <div className="grid gap-8 lg:grid-cols-3">
              {/* Player Summary */}
              <div className="lg:col-span-2 space-y-6">
                <div>
                  <h2 className="font-display text-lg uppercase tracking-wider text-[var(--text-primary)] mb-3">Player Summary</h2>
                  <p className="text-sm leading-relaxed text-[var(--text-secondary)]">
                    {player.first_name} {player.last_name} is a {player.age}-year-old {player.archetype ?? ''} {player.position}
                    {team ? ` for the ${team.city} ${team.name}` : ''}.
                    {player.years_pro != null ? ` Now in year ${player.years_pro} of his career` : ''}
                    {player.college ? ` out of ${player.college}` : ''}.
                    {player.overall_rating >= 90 ? ' He is an elite player at his position.' :
                     player.overall_rating >= 80 ? ' He is a solid starter with room to grow.' :
                     player.overall_rating >= 70 ? ' He is a capable player who can contribute.' :
                     ' He is still developing his skills.'}
                  </p>
                </div>

                {/* Quick Ratings Preview */}
                {ratingCategories.length > 0 && (
                  <div>
                    <h3 className="font-display text-sm uppercase tracking-wider text-[var(--text-muted)] mb-4">Key Ratings</h3>
                    <div className="grid grid-cols-4 sm:grid-cols-6 gap-4">
                      {Object.entries(displayRatings).flatMap(([, catRatings]) =>
                        Object.entries(catRatings)
                      ).slice(0, 6).map(([key, val]) => (
                        <RatingNumber key={key} label={formatStatName(key)} value={val} />
                      ))}
                    </div>
                  </div>
                )}

                {/* Season Stats Preview */}
                {stats?.season && Object.keys(stats.season).length > 0 && (
                  <div>
                    <h3 className="font-display text-sm uppercase tracking-wider text-[var(--text-muted)] mb-4">Season Highlights</h3>
                    <div className="grid grid-cols-3 sm:grid-cols-5 gap-4">
                      {Object.entries(stats.season).filter(([, val]) => val != null && val !== 0).slice(0, 5).map(([key, val]) => (
                        <div key={key} className="rounded-lg bg-[var(--bg-elevated)] p-3 text-center">
                          <p className="text-[10px] uppercase tracking-wider text-[var(--text-muted)]">{key.replace(/_/g, ' ')}</p>
                          <p className="font-display text-2xl text-[var(--text-primary)]">{val as React.ReactNode}</p>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>

              {/* Sidebar Info */}
              <div className="space-y-5">
                {/* Contract Card */}
                {contract && (
                  <div className="rounded-lg border border-white/5 bg-[var(--bg-elevated)] p-4">
                    <h3 className="font-display text-xs uppercase tracking-wider text-[var(--text-muted)] mb-3">Contract</h3>
                    <div className="space-y-2">
                      <div className="flex justify-between text-sm">
                        <span className="text-[var(--text-secondary)]">Salary</span>
                        <span className="font-semibold">{formatSalary(contract.salary)}/yr</span>
                      </div>
                      <div className="flex justify-between text-sm">
                        <span className="text-[var(--text-secondary)]">Years Left</span>
                        <span className="font-semibold">{contract.years_remaining}</span>
                      </div>
                    </div>
                  </div>
                )}

                {/* Morale */}
                {player.morale && (
                  <div className="rounded-lg border border-white/5 bg-[var(--bg-elevated)] p-4">
                    <h3 className="font-display text-xs uppercase tracking-wider text-[var(--text-muted)] mb-2">Morale</h3>
                    <div className="flex items-center gap-3">
                      <span className={`font-display text-2xl ${moraleColor(player.morale)}`}>{formatLabel(player.morale)}</span>
                      <div className="h-2 flex-1 overflow-hidden rounded-full bg-white/10">
                        <div
                          className={`h-full rounded-full transition-all ${moraleBgColor(player.morale)}`}
                          style={{ width: `${moralePercent(player.morale)}%` }}
                        />
                      </div>
                    </div>
                  </div>
                )}

                {/* Quick Actions */}
                <div className="space-y-2">
                  <Button
                    size="sm"
                    variant="outline"
                    className="w-full justify-between"
                    onClick={() => setTradeModalOpen(true)}
                  >
                    Find a Trade
                    <ChevronRight className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            </div>
          </div>
        </TabsContent>

        {/* ===== RATINGS TAB - Madden Style ===== */}
        <TabsContent value="ratings" className="mt-0">
          <div className="rounded-b-xl bg-[var(--bg-surface)] p-6 sm:p-8">
            <div className="grid gap-8 lg:grid-cols-[320px_1fr]">
              {/* Left Column: Analysis, Morale, Personality */}
              <div className="space-y-6">
                {/* Analysis */}
                <div>
                  <h2 className="font-display text-lg uppercase tracking-wider text-[var(--text-primary)] mb-3">
                    Analysis
                  </h2>
                  <p className="text-sm leading-relaxed text-[var(--text-secondary)]">
                    {player.first_name} {player.last_name} is a{' '}
                    <strong className="font-bold text-[var(--text-primary)]">
                      {player.archetype ?? player.position}
                    </strong>{' '}
                    with an Overall Rating of{' '}
                    <strong className="font-bold text-[var(--text-primary)]">
                      {player.overall_rating}
                    </strong>
                    {player.overall_rating >= 85
                      ? ', putting him among the elite at his position.'
                      : player.overall_rating >= 75
                        ? ', making him a quality starter.'
                        : player.overall_rating >= 65
                          ? ', giving him potential to contribute.'
                          : '.'}
                  </p>
                  {player.potential && (
                    <p className="mt-2 text-sm text-[var(--text-secondary)]">
                      Development:{' '}
                      <strong className="font-bold text-[var(--text-primary)]">{getDevelopmentLabel(player.potential)}</strong>
                    </p>
                  )}
                </div>

                <Separator className="bg-white/5" />

                {/* Morale */}
                <div>
                  <h2 className="font-display text-lg uppercase tracking-wider text-[var(--text-primary)] mb-2">
                    Morale
                  </h2>
                  {player.morale ? (
                    <>
                      <p className="text-sm leading-relaxed text-[var(--text-secondary)]">
                        <BoldText text={getMoraleDescription(player.morale, player.first_name)} />
                      </p>
                      <div className="mt-3 flex items-center gap-3">
                        <span className={`font-display text-3xl ${moraleColor(player.morale)}`}>
                          {formatLabel(player.morale)}
                        </span>
                        <div className="h-2 flex-1 overflow-hidden rounded-full bg-white/10">
                          <div
                            className={`h-full rounded-full ${moraleBgColor(player.morale)}`}
                            style={{ width: `${moralePercent(player.morale)}%` }}
                          />
                        </div>
                      </div>
                    </>
                  ) : (
                    <p className="text-sm text-[var(--text-muted)]">No morale data available.</p>
                  )}
                </div>

                <Separator className="bg-white/5" />

                {/* Personality */}
                <div>
                  <h2 className="font-display text-lg uppercase tracking-wider text-[var(--text-primary)] mb-2">
                    Personality
                  </h2>
                  <p className="text-sm text-[var(--text-secondary)]">
                    {formatLabel(player.personality ?? 'Unknown')}
                  </p>
                </div>

                <Separator className="bg-white/5" />

                {/* Status */}
                <div>
                  <h2 className="font-display text-lg uppercase tracking-wider text-[var(--text-primary)] mb-2">
                    Status
                  </h2>
                  <div className="flex flex-wrap gap-2">
                    {player.status && (
                      <Badge variant={player.status === 'active' ? 'default' : 'destructive'} className="uppercase text-[10px] tracking-wider">
                        {player.status}
                      </Badge>
                    )}
                    {injury && (
                      <Badge variant="destructive" className="uppercase text-[10px] tracking-wider">
                        {injury.type} — {injury.severity} ({injury.weeks_remaining}w)
                      </Badge>
                    )}
                  </div>
                </div>
              </div>

              {/* Right Column: Rating Numbers Grid */}
              <div className="space-y-8">
                {/* Core Attributes */}
                {primaryCategories.length > 0 && (
                  <div>
                    <h2 className="font-display text-lg uppercase tracking-wider text-[var(--text-primary)] mb-5">
                      Core Attributes
                    </h2>
                    <div className="space-y-6">
                      {primaryCategories.map((category) => {
                        const catEntries = Object.entries(displayRatings[category]);
                        return (
                          <div key={category}>
                            {primaryCategories.length > 1 && (
                              <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                                {CATEGORY_LABELS[category] ?? category.replace(/_/g, ' ')}
                              </h3>
                            )}
                            <div className="grid grid-cols-3 gap-x-6 gap-y-5 sm:grid-cols-4">
                              {catEntries.map(([key, val]) => (
                                <RatingNumber key={key} label={formatStatName(key)} value={val} />
                              ))}
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}

                {/* Secondary Attributes */}
                {secondaryCategories.length > 0 && (
                  <div>
                    <h2 className="font-display text-lg uppercase tracking-wider text-[var(--text-primary)] mb-5">
                      Secondary Attributes
                    </h2>
                    <div className="space-y-6">
                      {secondaryCategories.map((category) => {
                        const catEntries = Object.entries(displayRatings[category]);
                        return (
                          <div key={category}>
                            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                              {CATEGORY_LABELS[category] ?? category.replace(/_/g, ' ')}
                            </h3>
                            <div className="grid grid-cols-3 gap-x-6 gap-y-5 sm:grid-cols-4">
                              {catEntries.map(([key, val]) => (
                                <RatingNumber key={key} label={formatStatName(key)} value={val} />
                              ))}
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}

                {ratingCategories.length === 0 && (
                  <p className="text-sm text-[var(--text-muted)]">No ratings data available.</p>
                )}
              </div>
            </div>
          </div>
        </TabsContent>

        {/* ===== STATS TAB ===== */}
        <TabsContent value="stats" className="mt-0">
          <div className="rounded-b-xl bg-[var(--bg-surface)] p-6 sm:p-8">
            <div className="space-y-8">
              {/* Season Stats */}
              <div>
                <h2 className="font-display text-lg uppercase tracking-wider text-[var(--text-primary)] mb-5">
                  Season Statistics
                </h2>
                {stats?.season && Object.keys(stats.season).length > 0 ? (
                  <div className="grid grid-cols-3 gap-4 sm:grid-cols-4 lg:grid-cols-6">
                    {Object.entries(stats.season).filter(([, val]) => val != null && val !== 0).map(([key, val]) => (
                      <div key={key} className="rounded-lg border border-white/5 bg-[var(--bg-elevated)] p-3 text-center">
                        <p className="text-[10px] uppercase tracking-wider text-[var(--text-muted)]">{key.replace(/_/g, ' ')}</p>
                        <p className="font-display text-3xl text-[var(--text-primary)]">{val as React.ReactNode}</p>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-sm text-[var(--text-muted)]">No stats yet this season.</p>
                )}
              </div>

              {/* Career Stats */}
              {stats?.career && Object.keys(stats.career).length > 0 && (
                <div>
                  <h2 className="font-display text-lg uppercase tracking-wider text-[var(--text-primary)] mb-5">
                    Career Statistics
                  </h2>
                  <div className="grid grid-cols-3 gap-4 sm:grid-cols-4 lg:grid-cols-6">
                    {Object.entries(stats.career).filter(([, val]) => val != null && val !== 0).map(([key, val]) => (
                      <div key={key} className="rounded-lg border border-white/5 bg-[var(--bg-elevated)] p-3 text-center">
                        <p className="text-[10px] uppercase tracking-wider text-[var(--text-muted)]">{key.replace(/_/g, ' ')}</p>
                        <p className="font-display text-3xl text-[var(--text-primary)]">{val as React.ReactNode}</p>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </div>
        </TabsContent>

        {/* ===== GAME LOG TAB ===== */}
        <TabsContent value="gamelog" className="mt-0">
          <div className="rounded-b-xl bg-[var(--bg-surface)] overflow-hidden">
            {gameLog && Array.isArray(gameLog) && gameLog.length > 0 ? (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow className="border-white/5 hover:bg-transparent">
                      <TableHead className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">WK</TableHead>
                      <TableHead className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">OPP</TableHead>
                      <TableHead className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">RESULT</TableHead>
                      {Object.keys(gameLog[0]?.stats ?? {}).map((k) => (
                        <TableHead key={k} className="text-center text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                          {k.replace(/_/g, ' ')}
                        </TableHead>
                      ))}
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {gameLog.map((g) => (
                      <TableRow key={g.week} className="border-white/5 hover:bg-white/[0.02]">
                        <TableCell className="font-mono text-sm font-semibold">{g.week}</TableCell>
                        <TableCell className="text-sm">{g.opponent}</TableCell>
                        <TableCell>
                          <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-bold ${
                            g.result === 'W'
                              ? 'bg-green-500/10 text-green-400'
                              : 'bg-red-500/10 text-red-400'
                          }`}>
                            {g.result} {g.score}
                          </span>
                        </TableCell>
                        {Object.values(g.stats ?? {}).map((v, i) => (
                          <TableCell key={i} className="text-center font-mono text-sm">{v || '—'}</TableCell>
                        ))}
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            ) : (
              <div className="p-8">
                <p className="text-sm text-[var(--text-muted)]">No game log entries yet.</p>
              </div>
            )}
          </div>
        </TabsContent>

        {/* ===== HEALTH TAB ===== */}
        <TabsContent value="health" className="mt-0">
          <div className="rounded-b-xl bg-[var(--bg-surface)] p-6 sm:p-8">
            <div className="grid gap-6 lg:grid-cols-2">
              {/* Current Health Status */}
              <div>
                <h2 className="font-display text-lg uppercase tracking-wider text-[var(--text-primary)] mb-4">
                  Health Status
                </h2>
                {injury ? (
                  <div className="rounded-lg border border-red-500/20 bg-red-500/5 p-5">
                    <div className="flex items-start gap-4">
                      <div className="flex h-10 w-10 items-center justify-center rounded-full bg-red-500/10">
                        <Activity className="h-5 w-5 text-red-400" />
                      </div>
                      <div>
                        <h3 className="font-semibold text-red-400">{injury.type}</h3>
                        <p className="mt-1 text-sm text-[var(--text-secondary)]">
                          Severity: <span className="font-semibold text-red-400">{injury.severity}</span>
                        </p>
                        <p className="text-sm text-[var(--text-secondary)]">
                          Recovery: <span className="font-semibold">{injury.weeks_remaining} week{injury.weeks_remaining !== 1 ? 's' : ''} remaining</span>
                        </p>
                      </div>
                    </div>
                  </div>
                ) : (
                  <div className="rounded-lg border border-green-500/20 bg-green-500/5 p-5">
                    <div className="flex items-center gap-4">
                      <div className="flex h-10 w-10 items-center justify-center rounded-full bg-green-500/10">
                        <Heart className="h-5 w-5 text-green-400" />
                      </div>
                      <div>
                        <h3 className="font-semibold text-green-400">Healthy</h3>
                        <p className="mt-1 text-sm text-[var(--text-secondary)]">
                          {player.first_name} is fully healthy and available to play.
                        </p>
                      </div>
                    </div>
                  </div>
                )}
              </div>

              {/* Stamina/Physical Condition */}
              <div>
                <h2 className="font-display text-lg uppercase tracking-wider text-[var(--text-primary)] mb-4">
                  Physical Condition
                </h2>
                <div className="space-y-4">
                  {player.stamina != null && (
                    <div className="rounded-lg border border-white/5 bg-[var(--bg-elevated)] p-4">
                      <div className="flex items-center justify-between mb-2">
                        <span className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Stamina</span>
                        <span className={`font-display text-2xl ${ratingColor(player.stamina)}`}>{player.stamina}</span>
                      </div>
                      <div className="h-2 overflow-hidden rounded-full bg-white/10">
                        <div
                          className={`h-full rounded-full ${ratingBarColor(player.stamina)}`}
                          style={{ width: `${player.stamina}%` }}
                        />
                      </div>
                    </div>
                  )}
                  <div className="rounded-lg border border-white/5 bg-[var(--bg-elevated)] p-4">
                    <div className="flex items-center justify-between mb-1">
                      <span className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Age</span>
                      <span className="font-display text-2xl">{player.age}</span>
                    </div>
                    <p className="text-xs text-[var(--text-muted)]">
                      {player.age <= 25 ? 'Still developing — prime years ahead.' :
                       player.age <= 30 ? 'In his prime playing years.' :
                       player.age <= 33 ? 'Approaching the tail end of his prime.' :
                       'Veteran player — decline risk increasing.'}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </TabsContent>

        {/* ===== TRAITS TAB ===== */}
        <TabsContent value="traits" className="mt-0">
          <div className="rounded-b-xl bg-[var(--bg-surface)] p-6 sm:p-8">
            <div className="grid gap-6 lg:grid-cols-2">
              {/* Edge & Instincts */}
              <div>
                <h2 className="font-display text-lg uppercase tracking-wider text-[var(--text-primary)] mb-4">
                  Abilities
                </h2>
                <div className="space-y-3">
                  {player.edge ? (
                    <div className="rounded-lg border border-yellow-500/20 bg-yellow-500/5 p-4">
                      <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-yellow-500/10 border border-yellow-500/30">
                          <Zap className="h-5 w-5 text-yellow-400" />
                        </div>
                        <div>
                          <p className="text-[10px] font-semibold uppercase tracking-wider text-yellow-500/70">Edge</p>
                          <p className="font-semibold text-yellow-400">{player.edge}</p>
                        </div>
                      </div>
                    </div>
                  ) : (
                    <div className="rounded-lg border border-white/5 bg-[var(--bg-elevated)] p-4">
                      <p className="text-sm text-[var(--text-muted)]">No Edge ability.</p>
                    </div>
                  )}

                  {abilities.length > 0 ? (
                    abilities.map((ability) => (
                      <div key={ability} className="rounded-lg border border-purple-500/20 bg-purple-500/5 p-4">
                        <div className="flex items-center gap-3">
                          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-500/10 border border-purple-500/30">
                            <Star className="h-5 w-5 text-purple-400" />
                          </div>
                          <div>
                            <p className="text-[10px] font-semibold uppercase tracking-wider text-purple-500/70">Instinct</p>
                            <p className="font-semibold text-purple-400">{ability}</p>
                          </div>
                        </div>
                      </div>
                    ))
                  ) : !player.edge ? (
                    <p className="text-sm text-[var(--text-muted)]">No instincts.</p>
                  ) : null}
                </div>
              </div>

              {/* Player Traits */}
              <div>
                <h2 className="font-display text-lg uppercase tracking-wider text-[var(--text-primary)] mb-4">
                  Player Traits
                </h2>
                <div className="space-y-3">
                  <div className="rounded-lg border border-white/5 bg-[var(--bg-elevated)] p-4">
                    <div className="flex items-center gap-3">
                      <Brain className="h-5 w-5 text-[var(--text-muted)]" />
                      <div>
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Personality</p>
                        <p className="font-semibold">{formatLabel(player.personality ?? 'Unknown')}</p>
                      </div>
                    </div>
                  </div>

                  <div className="rounded-lg border border-white/5 bg-[var(--bg-elevated)] p-4">
                    <div className="flex items-center gap-3">
                      <TrendingUp className="h-5 w-5 text-[var(--text-muted)]" />
                      <div>
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Development</p>
                        <p className="font-semibold">{getDevelopmentLabel(player.potential)}</p>
                      </div>
                    </div>
                  </div>

                  {player.running_style && (
                    <div className="rounded-lg border border-white/5 bg-[var(--bg-elevated)] p-4">
                      <div className="flex items-center gap-3">
                        <Dumbbell className="h-5 w-5 text-[var(--text-muted)]" />
                        <div>
                          <p className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Running Style</p>
                          <p className="font-semibold">{player.running_style}</p>
                        </div>
                      </div>
                    </div>
                  )}

                  <div className="rounded-lg border border-white/5 bg-[var(--bg-elevated)] p-4">
                    <div className="flex items-center gap-3">
                      <Award className="h-5 w-5 text-[var(--text-muted)]" />
                      <div>
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Blueprint</p>
                        <p className="font-semibold">{player.archetype ?? player.position}</p>
                      </div>
                    </div>
                  </div>

                  {player.handedness && (
                    <div className="rounded-lg border border-white/5 bg-[var(--bg-elevated)] p-4">
                      <div className="flex items-center gap-3">
                        <Target className="h-5 w-5 text-[var(--text-muted)]" />
                        <div>
                          <p className="text-[10px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Handedness</p>
                          <p className="font-semibold">{player.handedness}</p>
                        </div>
                      </div>
                    </div>
                  )}
                </div>
              </div>
            </div>
            <div className="mt-6 text-center">
              <button
                onClick={() => navigate('/glossary')}
                className="text-xs text-[var(--accent-blue)] hover:underline"
              >
                View full glossary of Edges, Instincts, Blueprints & Development tiers
              </button>
            </div>
          </div>
        </TabsContent>
      </Tabs>

      <FindTradeModal
        playerId={player.id}
        playerName={`${player.first_name} ${player.last_name}`}
        open={tradeModalOpen}
        onOpenChange={setTradeModalOpen}
      />
    </div>
  );
}
