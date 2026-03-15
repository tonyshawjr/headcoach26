import { useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { usePlayer, usePlayerStats, usePlayerGameLog, useContractStatus } from '@/hooks/useApi';
import { useQueryClient } from '@tanstack/react-query';
import { playerApi } from '@/api/client';
import type { ContractStatusResponse, ExtensionOfferResponse } from '@/api/client';
import { Badge } from '@/components/ui/badge';
// FindTradeModal replaced by full-page TradeCenter at /trade/find/:playerId
import { PlayerComparison } from '@/components/PlayerComparison';
import { formatStatName, formatHeight, CATEGORY_LABELS } from '@/lib/formatters';
import { Heart, Zap, Star, Activity, FileText, DollarSign, X, Check, MessageSquare } from 'lucide-react';

// --- Types ---

interface PlayerData {
  id: number;
  first_name: string;
  last_name: string;
  position: string;
  age: number;
  overall_rating: number;
  jersey_number: number;
  image_url?: string | null;
  height?: number;
  weight?: number;
  handedness?: string;
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
  team?: { id: number; city: string; name: string; abbreviation: string; primary_color: string; secondary_color: string };
  contract?: { salary_annual: number; salary?: number; cap_hit?: number; years_remaining: number; years_total?: number; signing_bonus?: number; total_value?: number; status?: string };
  injury?: { type: string; severity: string; weeks_remaining: number };
  ratings?: RatingsMap;
  free_agent?: { free_agent_id: number; market_value: number; asking_salary: number } | null;
}

interface StatsResponse {
  position: string;
  season: Record<string, number> | null;
  season_year: number | null;
  career: Record<string, number> | null;
  career_by_year: (Record<string, number> & { season_year: number })[] | null;
}

interface GameLogEntry {
  week: number;
  opponent: string;
  location: string;
  result: string;
  score: string;
  pass_attempts?: number;
  pass_completions?: number;
  pass_yards?: number;
  pass_tds?: number;
  interceptions?: number;
  rush_attempts?: number;
  rush_yards?: number;
  rush_tds?: number;
  targets?: number;
  receptions?: number;
  rec_yards?: number;
  rec_tds?: number;
  tackles?: number;
  sacks?: number;
  interceptions_def?: number;
  forced_fumbles?: number;
  fg_attempts?: number;
  fg_made?: number;
  grade?: string;
  [key: string]: unknown;
}

// --- Stat column configs by position ---

const QB_STATS: [string, string][] = [
  ['pass_completions', 'CMP'], ['pass_attempts', 'ATT'], ['comp_pct', 'CMP%'],
  ['pass_yards', 'YDS'], ['pass_tds', 'TD'], ['interceptions', 'INT'],
  ['passer_rating', 'RTG'], ['yards_per_attempt', 'Y/A'],
  ['rush_attempts', 'CAR'], ['rush_yards', 'RUSH'], ['rush_tds', 'RTDS'],
];
const RB_STATS: [string, string][] = [
  ['rush_attempts', 'CAR'], ['rush_yards', 'YDS'], ['rush_tds', 'TD'], ['yards_per_carry', 'Y/C'],
  ['receptions', 'REC'], ['rec_yards', 'REC YDS'], ['rec_tds', 'REC TD'],
];
const WR_TE_STATS: [string, string][] = [
  ['targets', 'TGT'], ['receptions', 'REC'], ['rec_yards', 'YDS'], ['rec_tds', 'TD'],
  ['yards_per_catch', 'Y/R'], ['catch_pct', 'CTH%'],
  ['rush_attempts', 'CAR'], ['rush_yards', 'RUSH'],
];
const DEF_STATS: [string, string][] = [
  ['tackles', 'TKL'], ['sacks', 'SCK'], ['interceptions_def', 'INT'], ['forced_fumbles', 'FF'],
];
const K_STATS: [string, string][] = [
  ['fg_made', 'FGM'], ['fg_attempts', 'FGA'], ['fg_pct', 'FG%'],
];

const QB_GAME_COLS: [string, string][] = [
  ['pass_completions', 'CMP'], ['pass_attempts', 'ATT'], ['pass_yards', 'YDS'],
  ['pass_tds', 'TD'], ['interceptions', 'INT'], ['rush_attempts', 'CAR'], ['rush_yards', 'RUSH'],
];
const RB_GAME_COLS: [string, string][] = [
  ['rush_attempts', 'CAR'], ['rush_yards', 'YDS'], ['rush_tds', 'TD'],
  ['receptions', 'REC'], ['rec_yards', 'REC YDS'], ['rec_tds', 'REC TD'],
];
const WR_TE_GAME_COLS: [string, string][] = [
  ['targets', 'TGT'], ['receptions', 'REC'], ['rec_yards', 'YDS'], ['rec_tds', 'TD'],
  ['rush_attempts', 'CAR'], ['rush_yards', 'RUSH'],
];
const DEF_GAME_COLS: [string, string][] = [
  ['tackles', 'TKL'], ['sacks', 'SCK'], ['interceptions_def', 'INT'], ['forced_fumbles', 'FF'],
];
const K_GAME_COLS: [string, string][] = [['fg_made', 'FGM'], ['fg_attempts', 'FGA']];

function getStatsForPosition(pos: string): [string, string][] {
  if (pos === 'QB') return QB_STATS;
  if (pos === 'RB' || pos === 'FB') return RB_STATS;
  if (pos === 'WR' || pos === 'TE') return WR_TE_STATS;
  if (['DE', 'DT', 'LB', 'CB', 'S'].includes(pos)) return DEF_STATS;
  if (pos === 'K' || pos === 'P') return K_STATS;
  return QB_STATS;
}

function getGameColsForPosition(pos: string): [string, string][] {
  if (pos === 'QB') return QB_GAME_COLS;
  if (pos === 'RB' || pos === 'FB') return RB_GAME_COLS;
  if (pos === 'WR' || pos === 'TE') return WR_TE_GAME_COLS;
  if (['DE', 'DT', 'LB', 'CB', 'S'].includes(pos)) return DEF_GAME_COLS;
  if (pos === 'K' || pos === 'P') return K_GAME_COLS;
  return QB_GAME_COLS;
}

function computeGamePasserRating(g: GameLogEntry): string {
  const att = Number(g.pass_attempts) || 0;
  if (att === 0) return '—';
  const comp = Number(g.pass_completions) || 0;
  const yds = Number(g.pass_yards) || 0;
  const td = Number(g.pass_tds) || 0;
  const int_ = Number(g.interceptions) || 0;
  const a = Math.max(0, Math.min(2.375, ((comp / att) - 0.3) * 5));
  const b = Math.max(0, Math.min(2.375, ((yds / att) - 3) * 0.25));
  const c = Math.max(0, Math.min(2.375, (td / att) * 20));
  const d = Math.max(0, Math.min(2.375, 2.375 - ((int_ / att) * 25)));
  return (((a + b + c + d) / 6) * 100).toFixed(1);
}

// --- Helpers ---

function formatLabel(value: string): string {
  return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatSalary(salary: number): string {
  if (salary >= 1_000_000) return `$${(salary / 1_000_000).toFixed(1)}M`;
  if (salary >= 1_000) return `$${(salary / 1_000).toFixed(0)}K`;
  return `$${salary}`;
}

function formatInjuryType(type: string): string {
  return formatLabel(type);
}

function formatInjurySeverity(severity: string): string {
  const map: Record<string, string> = {
    day_to_day: 'Day-to-Day', short_term: 'Short-Term',
    long_term: 'Long-Term', season_ending: 'Season-Ending',
  };
  return map[severity] ?? formatLabel(severity);
}

function getDevelopmentLabel(potential?: string): string {
  if (!potential) return 'Average';
  const map: Record<string, string> = {
    elite: 'Elite', high: 'High', average: 'Average', limited: 'Limited',
    superstar: 'Elite', star: 'High', normal: 'Average', slow: 'Limited',
  };
  return map[potential.toLowerCase()] ?? formatLabel(potential);
}

// --- Tabs ---

const TABS = [
  { key: 'ratings', label: 'Ratings' },
  { key: 'scout', label: 'Scout Report' },
  { key: 'stats', label: 'Stats' },
  { key: 'gamelog', label: 'Game Log' },
  { key: 'awards', label: 'Awards' },
  { key: 'health', label: 'Health' },
  { key: 'traits', label: 'Traits' },
];

// --- Main Component ---

export default function PlayerProfile() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { data: rawData, isLoading } = usePlayer(Number(id));
  const { data: rawStats } = usePlayerStats(Number(id));
  const { data: rawGameLog } = usePlayerGameLog(Number(id));
  const { data: contractStatusRaw } = useContractStatus(Number(id));
  const contractStatus = contractStatusRaw as unknown as ContractStatusResponse | undefined;
  const queryClient = useQueryClient();
  // Trade navigation
  const [compareModalOpen, setCompareModalOpen] = useState(false);
  const [signing, setSigning] = useState(false);
  const [showBidDialog, setShowBidDialog] = useState(false);
  const [bidSalary, setBidSalary] = useState('');
  const [bidYears, setBidYears] = useState('1');
  const [activeTab, setActiveTab] = useState('ratings');
  const [extensionModalOpen, setExtensionModalOpen] = useState(false);
  const [extensionSalary, setExtensionSalary] = useState('');
  const [extensionYears, setExtensionYears] = useState('3');
  const [extensionSubmitting, setExtensionSubmitting] = useState(false);
  const [extensionResult, setExtensionResult] = useState<ExtensionOfferResponse | null>(null);

  if (isLoading) {
    return (
      <div className="flex h-96 items-center justify-center">
        <p className="text-[var(--text-secondary)]">Loading player...</p>
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
  const playerAwards = (detail as any)?.awards as { list: { type: string; label: string; season_year: number; details: Record<string, unknown> }[]; summary: Record<string, number> } | undefined;
  const stats = rawStats as unknown as StatsResponse | undefined;
  const gameLog = rawGameLog as unknown as GameLogEntry[] | undefined;

  if (!player) {
    return (
      <div className="flex h-96 items-center justify-center">
        <p className="text-[var(--text-secondary)]">Player not found.</p>
      </div>
    );
  }

  async function handleSign() {
    if (!freeAgent) return;
    const salaryNum = parseFloat(bidSalary) * 1_000_000;
    const yearsNum = parseInt(bidYears);
    if (isNaN(salaryNum) || salaryNum <= 0) { alert('Enter a valid salary.'); return; }
    if (isNaN(yearsNum) || yearsNum < 1 || yearsNum > 7) { alert('Years must be 1-7.'); return; }
    setSigning(true);
    try {
      const res = await fetch(`/api/free-agents/${freeAgent.free_agent_id}/bid`, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ salary_offer: salaryNum, years_offer: yearsNum }),
      });
      const data = await res.json();
      if (!res.ok) { alert(data?.error || 'Failed to place bid'); return; }
      setShowBidDialog(false);
      alert(`Bid submitted for ${player?.first_name} ${player?.last_name}!`);
      navigate('/free-agency');
    } catch { alert('Network error'); } finally { setSigning(false); }
  }

  async function handleOfferExtension() {
    if (!player) return;
    const salaryNum = parseFloat(extensionSalary) * 1_000_000;
    const yearsNum = parseInt(extensionYears);
    if (isNaN(salaryNum) || salaryNum <= 0 || isNaN(yearsNum) || yearsNum <= 0) {
      alert('Please enter a valid salary and years.');
      return;
    }
    setExtensionSubmitting(true);
    try {
      const result = await playerApi.offerExtension(player.id, Math.round(salaryNum), yearsNum);
      setExtensionResult(result);
      if (result.result === 'accepted') {
        queryClient.invalidateQueries({ queryKey: ['player', player.id] });
        queryClient.invalidateQueries({ queryKey: ['contractStatus', player.id] });
      }
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : 'Network error';
      alert(msg);
    } finally {
      setExtensionSubmitting(false);
    }
  }

  async function handleAcceptCounter() {
    if (!player || !extensionResult?.counter_offer) return;
    setExtensionSubmitting(true);
    try {
      const co = extensionResult.counter_offer;
      const result = await playerApi.offerExtension(player.id, co.salary, co.years);
      setExtensionResult(result);
      if (result.result === 'accepted') {
        queryClient.invalidateQueries({ queryKey: ['player', player.id] });
        queryClient.invalidateQueries({ queryKey: ['contractStatus', player.id] });
      }
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : 'Network error';
      alert(msg);
    } finally {
      setExtensionSubmitting(false);
    }
  }

  const isMyPlayer = contractStatus?.is_my_player ?? false;
  const isEligibleForExtension = contractStatus?.eligible_for_extension ?? false;
  const showContractCard = contractStatus && contractStatus.contract && isEligibleForExtension;

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
  const abilities = player.instincts ?? [];
  const gameCols = getGameColsForPosition(player.position);
  const isQB = player.position === 'QB';

  return (
    <div className="space-y-6 -mt-6">
      {/* ── ESPN-style Player Header ── */}
      {(() => {
        const teamColor = team?.primary_color ?? '#1c2333';
        const teamColor2 = (team as Record<string, unknown>)?.secondary_color as string ?? teamColor;
        const hasRealPhoto = player.image_url && (
          player.image_url.endsWith('.webp') || player.image_url.endsWith('.jpg') ||
          player.image_url.endsWith('.jpeg') || player.image_url.endsWith('.png')
        );
        const posStatCols = getStatsForPosition(player.position).slice(0, 4);
        const seasonData = stats?.season;
        const seasonYear = stats?.season_year;

        return (
          <div className="-mx-4 sm:-mx-6" style={{ width: '100vw', marginLeft: 'calc(-50vw + 50%)' }}>
            {/* Top color strip */}
            <div className="h-1" style={{ backgroundColor: teamColor }} />

            {/* Main header area */}
            <div className="bg-[var(--bg-surface)]">
              <div className="flex items-stretch" style={{ minHeight: '200px' }}>
                {/* Headshot area with ESPN-style angled dividers */}
                <div className="relative shrink-0 w-56 sm:w-72 hidden sm:block overflow-hidden">
                  {/* Team color gradient background */}
                  <div
                    className="absolute inset-0"
                    style={{
                      background: `linear-gradient(135deg, ${teamColor} 0%, ${teamColor2} 100%)`,
                    }}
                  />

                  {/* Player image — clipped to box, head near top */}
                  <div className="absolute inset-0 flex items-end justify-center" style={{ zIndex: 5 }}>
                    {hasRealPhoto ? (
                      <img
                        src={player.image_url!}
                        alt={`${player.first_name} ${player.last_name}`}
                        className="h-full w-auto max-w-none object-cover object-top drop-shadow-xl"
                      />
                    ) : (
                      <div className="flex h-full w-full items-center justify-center">
                        <svg viewBox="0 0 80 80" className="h-24 w-24 text-white opacity-25">
                          <circle cx="40" cy="28" r="14" fill="currentColor" />
                          <path d="M12 72c0-15.5 12.5-28 28-28s28 12.5 28 28" fill="currentColor" />
                        </svg>
                      </div>
                    )}
                  </div>

                  {/* Angled strips — ON TOP of the player image (higher z-index) */}
                  {/* Strip 1: thin accent line to the LEFT of player */}
                  <div
                    className="absolute -top-2 -bottom-2 left-[15%] w-[6px]"
                    style={{
                      background: `rgba(255,255,255,0.15)`,
                      transform: 'skewX(-8deg)',
                      zIndex: 8,
                    }}
                  />

                  {/* Strip 2: team-colored accent strip (between player and white edge) */}
                  <div
                    className="absolute -top-2 -bottom-2 right-[20px] w-[10px]"
                    style={{
                      background: `${teamColor}66`,
                      transform: 'skewX(-8deg)',
                      boxShadow: '-2px 0 4px rgba(0,0,0,0.1)',
                      zIndex: 10,
                    }}
                  />

                  {/* Strip 3: main white angled edge — overlaps the player */}
                  <div
                    className="absolute -top-2 -bottom-2 right-[-8px] w-[35px]"
                    style={{
                      background: 'var(--bg-surface)',
                      transform: 'skewX(-8deg)',
                      boxShadow: '-4px 0 10px rgba(0,0,0,0.15)',
                      zIndex: 12,
                    }}
                  />
                </div>

                {/* Name + OVR + Meta */}
                <div className="flex-1 min-w-0 px-8 py-8 flex flex-col justify-center">
                  <div className="flex items-start gap-6">
                    {/* Name block */}
                    <div className="flex-1 min-w-0">
                      <h1 className="leading-none">
                        <span className="block text-xl sm:text-2xl font-normal text-[var(--text-secondary)]">{player.first_name}</span>
                        <span className="block text-3xl sm:text-5xl font-black text-[var(--text-primary)] uppercase tracking-tight">{player.last_name}</span>
                      </h1>

                      {/* Team · Number · Position · Potential */}
                      <div className="flex flex-wrap items-center gap-x-2 mt-3 text-sm text-[var(--text-secondary)]">
                        {team && (
                          <Link to={`/team/${team.id}`} className="font-semibold hover:underline" style={{ color: teamColor }}>
                            {team.city} {team.name}
                          </Link>
                        )}
                        {team && <span className="text-[var(--text-muted)]">&middot;</span>}
                        <span>#{player.jersey_number}</span>
                        <span className="text-[var(--text-muted)]">&middot;</span>
                        <span>{player.position}</span>
                        {player.potential && (
                          <>
                            <span className="text-[var(--text-muted)]">&middot;</span>
                            <span>{getDevelopmentLabel(player.potential)} Potential</span>
                          </>
                        )}
                      </div>
                    </div>

                    {/* OVR badge */}
                    <div className="shrink-0 flex flex-col items-center bg-[var(--bg-elevated)] border border-[var(--border)] rounded-lg px-6 py-4">
                      <span className="text-5xl font-bold text-[var(--text-primary)] leading-none">{player.overall_rating}</span>
                      <span className="text-[10px] font-bold uppercase tracking-widest text-[var(--text-muted)] mt-1.5">OVR</span>
                    </div>
                  </div>
                </div>

                {/* Bio details column */}
                <div className="hidden lg:flex flex-col justify-center gap-3 px-8 py-6 border-l border-[var(--border)] min-w-[250px]">
                  {player.height != null && player.weight != null && (
                    <DetailLine label="HT/WT" value={`${formatHeight(player.height)}, ${player.weight} lbs`} />
                  )}
                  <DetailLine label="AGE" value={String(player.age)} />
                  {player.college && <DetailLine label="COLLEGE" value={player.college} />}
                  {player.years_pro != null && (
                    <DetailLine label="EXPERIENCE" value={`${player.years_pro} yr${player.years_pro !== 1 ? 's' : ''}`} />
                  )}
                  <DetailLine label="STATUS" value={injury ? `Injured — ${formatInjuryType(injury.type)}` : 'Active'} />
                </div>

                {/* Season stats box */}
                {seasonData && Object.keys(seasonData).length > 0 && (
                  <div className="hidden xl:flex flex-col shrink-0 min-w-[280px]">
                    <div
                      className="px-4 py-1.5 text-center text-xs font-bold uppercase tracking-wider text-white"
                      style={{ backgroundColor: teamColor }}
                    >
                      {seasonYear ?? 'Current'} Regular Season Stats
                    </div>
                    <div className="flex-1 flex items-center justify-center gap-6 px-6 py-4">
                      {posStatCols.map(([key, label]) => {
                        const val = seasonData[key];
                        if (val == null) return null;
                        const isPct = key.endsWith('_pct') || key === 'passer_rating';
                        return (
                          <div key={key} className="text-center">
                            <p className="text-xs font-semibold text-[var(--text-muted)]">{label}</p>
                            <p className="text-2xl font-bold text-[var(--text-primary)]">{isPct ? val : val.toLocaleString()}</p>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}
              </div>
            </div>

            {/* Contract + Actions strip */}
            <div className="bg-[#222222] text-white">
              <div className="flex items-center justify-between px-8 py-3.5">
                {/* Left: Contract info */}
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-white/70">
                  {contract && (
                    <>
                      <span>
                        Contract: <span className="font-semibold text-white">{formatSalary(contract.salary_annual ?? contract.salary)}/yr</span>
                      </span>
                      <span>{contract.years_remaining} yr remaining</span>
                    </>
                  )}
                  {freeAgent && (
                    <span>
                      Free Agent — Market Value: <span className="font-semibold text-white">{formatSalary(freeAgent.market_value)}/yr</span>
                    </span>
                  )}
                  {!contract && !freeAgent && (
                    <span>No contract information</span>
                  )}
                </div>

                {/* Right: Action buttons */}
                <div className="flex items-center gap-2 shrink-0">
                  <button
                    onClick={() => navigate(`/trade/find/${player.id}`)}
                    className="rounded border border-white/20 px-4 py-1.5 text-sm font-semibold text-white hover:bg-white/10 transition-colors"
                  >
                    Find Trade
                  </button>
                  <button
                    onClick={() => setCompareModalOpen(true)}
                    className="rounded border border-white/20 px-4 py-1.5 text-sm font-semibold text-white hover:bg-white/10 transition-colors"
                  >
                    Compare
                  </button>
                  {freeAgent && (
                    <button
                      onClick={() => { setBidSalary((freeAgent.market_value / 1_000_000).toFixed(1)); setBidYears('1'); setShowBidDialog(true); }}
                      disabled={signing}
                      className="rounded bg-green-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-green-500 transition-colors disabled:opacity-50"
                    >
                      {signing ? 'Signing...' : 'Make Offer'}
                    </button>
                  )}
                </div>
              </div>
            </div>

          </div>
        );
      })()}

      {/* ── Contract Status Card (final contract year, your player) ── */}
      {showContractCard && (() => {
        const gm = (contractStatus as any).gm_advice;
        const cap = (contractStatus as any).cap_info;
        const presets = (contractStatus as any).offer_presets;
        const w = contractStatus.willingness;
        const minSalary = w.minimum_salary;
        const prefYears = w.preferred_years;

        const makeOffer = (salary: number, years: number) => {
          setExtensionResult(null);
          setExtensionSalary(String(Math.round(salary / 1_000_000 * 10) / 10));
          setExtensionYears(String(years));
          setExtensionModalOpen(true);
        };

        // Build offer options including "Match His Ask"
        const offerOptions = [];
        if (presets) {
          offerOptions.push({ ...presets.team_friendly, key: 'team_friendly', color: 'blue' });
          // Insert "Match His Ask" between team-friendly and balanced if it's different
          if (minSalary && Math.abs(minSalary - presets.balanced.salary) > 200000) {
            offerOptions.push({
              key: 'match_ask',
              label: 'Match His Ask',
              description: "Exactly what he's asking for. Should get the deal done.",
              salary: minSalary,
              years: prefYears,
              total: minSalary * prefYears,
              risk: 'low',
              color: 'purple',
            });
          }
          offerOptions.push({ ...presets.balanced, key: 'balanced', color: 'gold' });
          offerOptions.push({ ...presets.player_friendly, key: 'player_friendly', color: 'green' });
        }

        const colorMap: Record<string, { border: string; hover: string; text: string }> = {
          blue: { border: 'border-blue-500/30', hover: 'hover:border-blue-500/50', text: 'text-blue-400' },
          purple: { border: 'border-purple-500/30', hover: 'hover:border-purple-500/50', text: 'text-purple-400' },
          gold: { border: 'border-[var(--accent-gold)]/30', hover: 'hover:border-[var(--accent-gold)]/50', text: 'text-[var(--accent-gold)]' },
          green: { border: 'border-green-500/30', hover: 'hover:border-green-500/50', text: 'text-green-400' },
        };

        return (
          <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
            <div className="p-5 sm:p-6">
              <div className="flex items-center gap-2 mb-4">
                <FileText className="h-5 w-5 text-[var(--text-muted)]" />
                <h3 className="font-bold text-[var(--text-primary)]">Contract Status</h3>
                <Badge variant="outline" className="ml-auto text-xs">
                  {contractStatus.contract!.years_remaining <= 0 ? 'Expired' : 'Final Year'}
                </Badge>
              </div>

              {/* Two-column layout: Left = player info, Right = GM + financials */}
              <div className="grid gap-6 lg:grid-cols-5">

                {/* LEFT COLUMN — Player's side (3 cols) */}
                <div className="lg:col-span-3 space-y-4">
                  {/* Player's quote */}
                  <div className="rounded-lg bg-[var(--bg-elevated)] border border-[var(--border)] p-3">
                    <div className="flex items-start gap-2">
                      <MessageSquare className="h-4 w-4 text-[var(--text-muted)] mt-0.5 shrink-0" />
                      <div>
                        <p className="text-sm text-[var(--text-secondary)] italic">
                          &ldquo;{w.reasoning}&rdquo;
                        </p>
                        <div className="flex flex-wrap items-center gap-3 mt-2 text-xs text-[var(--text-muted)]">
                          <span>Wants <span className="font-semibold text-[var(--text-primary)]">{formatSalary(minSalary)}/yr</span></span>
                          <span>for <span className="font-semibold text-[var(--text-primary)]">{prefYears} year{prefYears !== 1 ? 's' : ''}</span></span>
                          <span className={`font-semibold ${w.open_to_extension ? 'text-green-400' : 'text-red-400'}`}>
                            {w.open_to_extension ? 'Open to staying' : 'Wants to leave'}
                          </span>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Contract numbers — plain English */}
                  <div className="grid grid-cols-2 gap-3">
                    <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-3">
                      <p className="text-[10px] text-[var(--text-muted)]">What he makes now</p>
                      <p className="text-lg font-bold">{formatSalary(contractStatus.contract!.salary_annual)}/yr</p>
                    </div>
                    <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-3">
                      <p className="text-[10px] text-[var(--text-muted)]">What he&apos;s worth</p>
                      <p className="text-lg font-bold">{formatSalary(contractStatus.market_value)}/yr</p>
                      <p className="text-[9px] text-[var(--text-muted)] mt-0.5">Based on his rating, age, and position</p>
                    </div>
                  </div>

                  {/* Offer presets */}
                  {isMyPlayer && offerOptions.length > 0 && (
                    <div className="space-y-2">
                      <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">Make an Offer</p>
                      <div className="grid gap-2 sm:grid-cols-2">
                        {offerOptions.map((opt: any) => {
                          const c = colorMap[opt.color] ?? colorMap.gold;
                          return (
                            <button
                              key={opt.key}
                              onClick={() => makeOffer(opt.salary, opt.years)}
                              className={`rounded-lg border p-3 text-left transition-all hover:bg-[var(--bg-elevated)] ${c.border} ${c.hover}`}
                            >
                              <div className="flex items-center justify-between mb-1">
                                <p className={`text-xs font-bold uppercase tracking-wider ${c.text}`}>
                                  {opt.label}
                                </p>
                                <span className={`text-[9px] px-1.5 py-0.5 rounded border ${
                                  opt.risk === 'low' ? 'text-green-400 border-green-500/20 bg-green-500/10' :
                                  opt.risk === 'high' ? 'text-red-400 border-red-500/20 bg-red-500/10' :
                                  'text-yellow-400 border-yellow-500/20 bg-yellow-500/10'
                                }`}>
                                  {opt.risk === 'low' ? 'Likely Accept' : opt.risk === 'high' ? 'May Reject' : 'Possible'}
                                </span>
                              </div>
                              <p className="text-lg font-bold text-[var(--text-primary)]">{formatSalary(opt.salary)}/yr</p>
                              <p className="text-xs text-[var(--text-muted)]">{opt.years} yr &middot; {formatSalary(opt.total)} total</p>
                            </button>
                          );
                        })}
                      </div>
                      <button
                        onClick={() => makeOffer(contractStatus.market_value, prefYears)}
                        className="w-full mt-1 rounded-lg border border-[var(--border)] px-4 py-2 text-xs font-semibold text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)] transition-colors"
                      >
                        <DollarSign className="h-3 w-3 inline-block mr-1 -mt-0.5" />
                        Custom Offer
                      </button>
                    </div>
                  )}
                </div>

                {/* RIGHT COLUMN — GM + Financials (2 cols) */}
                <div className="lg:col-span-2 space-y-4">
                  {/* GM Advice */}
                  {gm && (
                    <div className={`rounded-lg p-4 border ${
                      gm.priority === 'critical' ? 'bg-red-500/10 border-red-500/30' :
                      gm.priority === 'high' ? 'bg-blue-500/10 border-blue-500/30' :
                      gm.priority === 'low' ? 'bg-gray-500/10 border-gray-500/30' :
                      'bg-yellow-500/10 border-yellow-500/30'
                    }`}>
                      <div className="flex items-center gap-2 mb-2">
                        <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--accent-gold)]">GM Recommendation</span>
                      </div>
                      <Badge variant="outline" className={`text-[10px] mb-2 ${
                        gm.recommendation === 'must_sign' ? 'bg-red-500/15 text-red-400 border-red-500/25' :
                        gm.recommendation === 'should_sign' ? 'bg-green-500/15 text-green-400 border-green-500/25' :
                        gm.recommendation === 'let_walk' ? 'bg-gray-500/15 text-gray-400 border-gray-500/25' :
                        'bg-yellow-500/15 text-yellow-400 border-yellow-500/25'
                      }`}>
                        {gm.recommendation === 'must_sign' ? 'MUST SIGN' :
                         gm.recommendation === 'should_sign' ? 'SHOULD SIGN' :
                         gm.recommendation === 'let_walk' ? 'LET WALK' : 'CONSIDER'}
                      </Badge>
                      <p className="text-xs text-[var(--text-secondary)] leading-relaxed">{gm.reasoning}</p>
                    </div>
                  )}

                  {/* Your Budget — plain English */}
                  {cap && (
                    <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-4">
                      <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)] mb-3">Your Budget</p>
                      <div className="space-y-2">
                        <div className="flex justify-between text-sm">
                          <span className="text-[var(--text-muted)]">Total budget</span>
                          <span className="font-bold">{formatSalary(cap.cap_total)}</span>
                        </div>
                        <div className="flex justify-between text-sm">
                          <span className="text-[var(--text-muted)]">Already committed</span>
                          <span className="font-bold">{formatSalary(cap.cap_used)}</span>
                        </div>
                        <div className="h-px bg-[var(--border)]" />
                        <div className="flex justify-between text-sm">
                          <span className="font-semibold text-[var(--text-primary)]">Money you can spend</span>
                          <span className={`font-bold ${cap.cap_remaining > 20000000 ? 'text-green-400' : cap.cap_remaining > 5000000 ? 'text-yellow-400' : 'text-red-400'}`}>
                            {formatSalary(cap.cap_remaining)}
                          </span>
                        </div>
                        {cap.dead_money > 0 && (
                          <div className="flex justify-between text-xs">
                            <span className="text-[var(--text-muted)]" title="Money still owed to players you cut — counts against your budget">Wasted on cut players</span>
                            <span className="text-red-400">{formatSalary(cap.dead_money)}</span>
                          </div>
                        )}
                      </div>
                      {/* Cap bar */}
                      <div className="mt-3 h-2 w-full rounded-full bg-[var(--bg-elevated)] overflow-hidden">
                        <div
                          className={`h-full rounded-full transition-all ${cap.cap_remaining > 20000000 ? 'bg-green-500' : cap.cap_remaining > 5000000 ? 'bg-yellow-500' : 'bg-red-500'}`}
                          style={{ width: `${Math.min(100, (cap.cap_used / cap.cap_total) * 100)}%` }}
                        />
                      </div>
                      <p className="text-[10px] text-[var(--text-muted)] mt-1">
                        {Math.round((cap.cap_used / cap.cap_total) * 100)}% of cap committed
                      </p>
                    </div>
                  )}
                </div>
              </div>
            </div>
          </div>
        );
      })()}

      {/* Contract info for players NOT on your team (read-only) */}
      {contractStatus && contractStatus.contract && !isMyPlayer && isEligibleForExtension && (
        <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 sm:p-6">
          <div className="flex items-center gap-2 mb-3">
            <FileText className="h-5 w-5 text-[var(--text-muted)]" />
            <h3 className="font-bold text-[var(--text-primary)]">Contract Expiring</h3>
          </div>
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
            <div>
              <p className="text-xs text-[var(--text-muted)]">Current Salary</p>
              <p className="font-semibold text-[var(--text-primary)]">{formatSalary(contractStatus.contract.salary_annual)}/yr</p>
            </div>
            <div>
              <p className="text-xs text-[var(--text-muted)]">Market Value</p>
              <p className="font-semibold text-[var(--text-primary)]">{formatSalary(contractStatus.market_value)}/yr</p>
            </div>
            <div>
              <p className="text-xs text-[var(--text-muted)]">Status</p>
              <p className="font-semibold text-[var(--text-primary)]">{contractStatus.contract.years_remaining <= 0 ? 'Expired' : 'Final Year'}</p>
            </div>
          </div>
        </div>
      )}

      {/* ── Extension Offer Modal ── */}
      {extensionModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="w-full max-w-md mx-4 rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] shadow-xl overflow-hidden">
            <div className="flex items-center justify-between px-5 py-4 border-b border-[var(--border)]">
              <h2 className="font-bold text-[var(--text-primary)]">
                Offer Extension to {player?.first_name} {player?.last_name}
              </h2>
              <button onClick={() => setExtensionModalOpen(false)} className="text-[var(--text-muted)] hover:text-[var(--text-primary)]">
                <X className="h-5 w-5" />
              </button>
            </div>

            <div className="p-5 space-y-4">
              {/* Show result if we have one */}
              {extensionResult ? (
                <div className="space-y-4">
                  {/* Result banner */}
                  <div className={`rounded-lg p-4 ${
                    extensionResult.result === 'accepted'
                      ? 'bg-green-500/10 border border-green-500/30'
                      : extensionResult.result === 'countered'
                        ? 'bg-yellow-500/10 border border-yellow-500/30'
                        : 'bg-red-500/10 border border-red-500/30'
                  }`}>
                    <div className="flex items-center gap-2 mb-2">
                      {extensionResult.result === 'accepted' && <Check className="h-5 w-5 text-green-500" />}
                      {extensionResult.result === 'countered' && <MessageSquare className="h-5 w-5 text-yellow-500" />}
                      {extensionResult.result === 'refused' && <X className="h-5 w-5 text-red-400" />}
                      <span className="font-bold text-[var(--text-primary)]">
                        {extensionResult.result === 'accepted' && 'Deal!'}
                        {extensionResult.result === 'countered' && 'Counter Offer'}
                        {extensionResult.result === 'refused' && 'Declined'}
                      </span>
                    </div>
                    <p className="text-sm text-[var(--text-secondary)]">{extensionResult.message}</p>
                  </div>

                  {/* Player's quote */}
                  <div className="rounded-lg bg-[var(--bg-elevated)] border border-[var(--border)] p-3">
                    <p className="text-sm text-[var(--text-secondary)] italic">"{extensionResult.reasoning}"</p>
                  </div>

                  {/* Counter offer details */}
                  {extensionResult.result === 'countered' && extensionResult.counter_offer && (
                    <div className="space-y-3">
                      <div className="text-sm text-[var(--text-secondary)]">
                        <p>He wants:</p>
                        <p className="font-bold text-[var(--text-primary)] text-lg mt-1">
                          {formatSalary(extensionResult.counter_offer.salary)}/yr, {extensionResult.counter_offer.years} year{extensionResult.counter_offer.years !== 1 ? 's' : ''}
                        </p>
                      </div>
                      <div className="flex gap-2">
                        <button
                          onClick={handleAcceptCounter}
                          disabled={extensionSubmitting}
                          className="flex-1 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90 transition-opacity disabled:opacity-50"
                        >
                          {extensionSubmitting ? 'Accepting...' : 'Accept Counter'}
                        </button>
                        <button
                          onClick={() => setExtensionModalOpen(false)}
                          className="flex-1 rounded-lg border border-[var(--border)] px-4 py-2.5 text-sm font-semibold text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] transition-colors"
                        >
                          Walk Away
                        </button>
                      </div>
                    </div>
                  )}

                  {/* Close button for accepted/refused */}
                  {extensionResult.result !== 'countered' && (
                    <button
                      onClick={() => {
                        setExtensionModalOpen(false);
                        // Refresh all player data so the new contract shows
                        queryClient.invalidateQueries({ queryKey: ['player', Number(id)] });
                        queryClient.invalidateQueries({ queryKey: ['contractStatus', Number(id)] });
                        queryClient.invalidateQueries({ queryKey: ['contractPlanner'] });
                        queryClient.invalidateQueries({ queryKey: ['roster'] });
                      }}
                      className="w-full rounded-lg border border-[var(--border)] px-4 py-2.5 text-sm font-semibold text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] transition-colors"
                    >
                      {extensionResult.result === 'accepted' ? 'Done' : 'Close'}
                    </button>
                  )}
                </div>
              ) : (
                <>
                  {/* Offer form */}
                  <div>
                    <p className="text-xs text-[var(--text-muted)] mb-1">Market Value</p>
                    <p className="text-sm font-semibold text-[var(--text-primary)] mb-3">
                      {contractStatus ? formatSalary(contractStatus.market_value) : '---'}/yr
                    </p>
                  </div>

                  <div>
                    <label className="block text-xs text-[var(--text-muted)] mb-1">Annual Salary (in millions)</label>
                    <div className="flex items-center gap-1">
                      <span className="text-[var(--text-muted)]">$</span>
                      <input
                        type="number"
                        step="0.1"
                        min="1.1"
                        value={extensionSalary}
                        onChange={(e) => setExtensionSalary(e.target.value)}
                        className="flex-1 rounded-lg border border-[var(--border)] bg-[var(--bg-elevated)] px-3 py-2 text-sm text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--accent-blue)]"
                        placeholder="15.0"
                      />
                      <span className="text-[var(--text-muted)] text-sm">M/yr</span>
                    </div>
                  </div>

                  <div>
                    <label className="block text-xs text-[var(--text-muted)] mb-1">Contract Length (years)</label>
                    <select
                      value={extensionYears}
                      onChange={(e) => setExtensionYears(e.target.value)}
                      className="w-full rounded-lg border border-[var(--border)] bg-[var(--bg-elevated)] px-3 py-2 text-sm text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--accent-blue)]"
                    >
                      {[1, 2, 3, 4, 5, 6].map((y) => (
                        <option key={y} value={y}>{y} year{y > 1 ? 's' : ''}</option>
                      ))}
                    </select>
                  </div>

                  <div className="text-xs text-[var(--text-muted)]">
                    Total value: <span className="font-semibold text-[var(--text-primary)]">{formatSalary(parseFloat(extensionSalary || '0') * parseInt(extensionYears || '1') * 1_000_000)}</span>
                  </div>

                  <div className="flex gap-2 pt-2">
                    <button
                      onClick={handleOfferExtension}
                      disabled={extensionSubmitting}
                      className="flex-1 rounded-lg bg-[var(--accent-blue)] px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90 transition-opacity disabled:opacity-50"
                    >
                      {extensionSubmitting ? 'Submitting...' : 'Make Offer'}
                    </button>
                    <button
                      onClick={() => setExtensionModalOpen(false)}
                      className="rounded-lg border border-[var(--border)] px-4 py-2.5 text-sm font-semibold text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] transition-colors"
                    >
                      Cancel
                    </button>
                  </div>
                </>
              )}
            </div>
          </div>
        </div>
      )}

      {/* ── Tabs ── */}
      <div className="flex gap-1 border-b border-[var(--border)]">
        {TABS.map((tab) => (
          <button
            key={tab.key}
            onClick={() => setActiveTab(tab.key)}
            className={`px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition-colors ${
              activeTab === tab.key
                ? 'border-[var(--accent-blue)] text-[var(--text-primary)]'
                : 'border-transparent text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* ── Tab Content (min height prevents page jumping between tabs) ── */}
      <div className="min-h-[500px]">

      {/* RATINGS */}
      {activeTab === 'ratings' && (
        <div className="space-y-6">
          {ratingCategories.length > 0 ? (
            ratingCategories.map((category) => {
              const catEntries = Object.entries(displayRatings[category]);
              return (
                <div key={category} className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 sm:p-6">
                  <h3 className="text-sm font-bold uppercase tracking-wider text-[var(--text-muted)] mb-4">
                    {CATEGORY_LABELS[category] ?? formatLabel(category)}
                  </h3>
                  <div className="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-5 gap-x-6 gap-y-5">
                    {catEntries.map(([key, val]) => (
                      <div key={key}>
                        <p className="text-xs text-[var(--text-muted)] mb-1">{formatStatName(key)}</p>
                        <p className="text-3xl font-bold text-[var(--text-primary)] leading-none">{val}</p>
                        <div className="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-[var(--bg-elevated)]">
                          <div
                            className="h-full rounded-full bg-[var(--accent-blue)] transition-all"
                            style={{ width: `${val}%` }}
                          />
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              );
            })
          ) : (
            <p className="text-center text-[var(--text-secondary)] py-8">No ratings data available.</p>
          )}

          {/* Sidebar info */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {player.potential && (
              <InfoCard label="Development" value={getDevelopmentLabel(player.potential)} />
            )}
            {player.personality && (
              <InfoCard label="Personality" value={formatLabel(player.personality)} />
            )}
            {player.morale && (
              <InfoCard label="Morale" value={formatLabel(player.morale)} />
            )}
          </div>
        </div>
      )}

      {/* SCOUT REPORT */}
      {activeTab === 'scout' && (() => {
        const report = (detail as any)?.scout_report as { scout: string; style: string; paragraphs: string[] } | undefined;
        if (!report || !report.paragraphs?.length) {
          return (
            <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-8 text-center">
              <FileText className="mx-auto h-8 w-8 text-[var(--text-muted)] mb-2" />
              <p className="text-[var(--text-secondary)]">No scout report available</p>
            </div>
          );
        }

        const styleColor = report.style === 'old_school'
          ? 'var(--accent-red)'
          : report.style === 'analytical'
            ? 'var(--accent-blue)'
            : 'var(--accent-gold)';

        const styleLabel = report.style === 'old_school'
          ? 'Old School'
          : report.style === 'analytical'
            ? 'Analytical'
            : 'Narrative';

        return (
          <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
            {/* Scout byline */}
            <div
              className="px-6 py-4 border-b border-[var(--border)]"
              style={{ background: `linear-gradient(135deg, color-mix(in srgb, ${styleColor} 8%, transparent), var(--bg-surface))` }}
            >
              <div className="flex items-center gap-3">
                <div
                  className="h-10 w-10 rounded-full flex items-center justify-center text-white font-bold text-sm"
                  style={{ backgroundColor: styleColor }}
                >
                  {report.scout.split(' ').map(n => n[0]).join('')}
                </div>
                <div>
                  <p className="font-display text-base text-[var(--text-primary)]">{report.scout}</p>
                  <p className="text-xs text-[var(--text-muted)]">
                    {styleLabel} Scout — Scouting Report
                  </p>
                </div>
              </div>
            </div>

            {/* Report body */}
            <div className="px-6 py-5 space-y-4">
              {report.paragraphs.map((para, i) => (
                <p key={i} className="text-sm leading-relaxed text-[var(--text-secondary)]">
                  {para}
                </p>
              ))}
            </div>
          </div>
        );
      })()}

      {/* STATS */}
      {activeTab === 'stats' && (
        <div className="space-y-6">
          {/* Current Season Totals */}
          <StatsBlock
            label={`${stats?.season_year ?? 'Current'} Season`}
            data={stats?.season ?? null}
            position={player.position}
          />

          {/* Current Season Game-by-Game */}
          {gameLog && Array.isArray(gameLog) && gameLog.length > 0 && (
            <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
              <div className="p-5 sm:p-6 pb-0">
                <h3 className="text-sm font-bold uppercase tracking-wider text-[var(--text-muted)] mb-4">{stats?.season_year ?? 'Current'} Game Log</h3>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-[var(--border)] text-left">
                      <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">WK</th>
                      <th className="px-3 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">TM</th>
                      <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">OPP</th>
                      <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Result</th>
                      {gameCols.map(([, label]) => (
                        <th key={label} className="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">{label}</th>
                      ))}
                      {isQB && <th className="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">RTG</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {gameLog.map((g) => (
                      <tr key={g.week} className="border-b border-[var(--border)] last:border-0 hover:bg-[var(--bg-elevated)] transition-colors">
                        <td className="px-4 py-3 font-semibold">{g.week}</td>
                        <td className="px-3 py-3">
                          <span
                            className="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold text-white"
                            style={{ backgroundColor: (g as Record<string, unknown>).team_color as string ?? 'var(--text-muted)' }}
                          >
                            {(g as Record<string, unknown>).team as string ?? ''}
                          </span>
                        </td>
                        <td className="px-4 py-3">
                          <span className="text-[var(--text-muted)]">{g.location === 'away' ? '@' : 'vs'}</span> {g.opponent}
                        </td>
                        <td className="px-4 py-3 font-semibold">{g.result} {g.score}</td>
                        {gameCols.map(([colKey]) => {
                          const val = (g as Record<string, unknown>)[colKey];
                          const num = Number(val ?? 0);
                          return (
                            <td key={colKey} className={`px-3 py-3 text-center ${num > 0 ? 'font-semibold' : 'text-[var(--text-muted)]'}`}>
                              {num || '—'}
                            </td>
                          );
                        })}
                        {isQB && (
                          <td className="px-3 py-3 text-center font-semibold">{computeGamePasserRating(g)}</td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* Career Year-by-Year Table */}
          {stats?.career_by_year && stats.career_by_year.length > 0 && (
            <CareerTable
              years={stats.career_by_year}
              position={player.position}
              careerTotals={stats.career}
            />
          )}
        </div>
      )}

      {/* GAME LOG */}
      {activeTab === 'gamelog' && (
        <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
          {gameLog && Array.isArray(gameLog) && gameLog.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-[var(--border)] text-left">
                    <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">WK</th>
                    <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">TM</th>
                    <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">OPP</th>
                    <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Result</th>
                    {gameCols.map(([, label]) => (
                      <th key={label} className="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">{label}</th>
                    ))}
                    {isQB && <th className="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">RTG</th>}
                  </tr>
                </thead>
                <tbody>
                  {gameLog.map((g) => (
                    <tr key={g.week} className="border-b border-[var(--border)] last:border-0 hover:bg-[var(--bg-elevated)] transition-colors">
                      <td className="px-4 py-3 font-semibold">{g.week}</td>
                      <td className="px-4 py-3">
                        <span
                          className="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold text-white"
                          style={{ backgroundColor: (g as Record<string, unknown>).team_color as string ?? 'var(--text-muted)' }}
                        >
                          {(g as Record<string, unknown>).team as string ?? ''}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <span className="text-[var(--text-muted)]">{g.location === 'away' ? '@' : 'vs'}</span> {g.opponent}
                      </td>
                      <td className="px-4 py-3 font-semibold">{g.result} {g.score}</td>
                      {gameCols.map(([colKey]) => {
                        const val = (g as Record<string, unknown>)[colKey];
                        const num = Number(val ?? 0);
                        return (
                          <td key={colKey} className={`px-3 py-3 text-center ${num > 0 ? 'font-semibold' : 'text-[var(--text-muted)]'}`}>
                            {num || '—'}
                          </td>
                        );
                      })}
                      {isQB && (
                        <td className="px-3 py-3 text-center font-semibold">{computeGamePasserRating(g)}</td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="text-center text-[var(--text-secondary)] py-8">No game log entries yet.</p>
          )}
        </div>
      )}

      {/* HEALTH */}
      {activeTab === 'health' && (
        <div className="space-y-4">
          <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-6">
            {injury ? (
              <div>
                <div className="flex items-center gap-2 mb-2">
                  <Activity className="h-5 w-5 text-[var(--text-muted)]" />
                  <h3 className="font-bold text-[var(--text-primary)]">{formatInjuryType(injury.type)} Injury</h3>
                </div>
                <p className="text-[var(--text-secondary)]">
                  Severity: <span className="font-semibold text-[var(--text-primary)]">{formatInjurySeverity(injury.severity)}</span>
                </p>
                <p className="text-[var(--text-secondary)]">
                  Recovery: <span className="font-semibold text-[var(--text-primary)]">{injury.weeks_remaining} week{injury.weeks_remaining !== 1 ? 's' : ''} remaining</span>
                </p>
              </div>
            ) : (
              <div className="flex items-center gap-3">
                <Heart className="h-5 w-5 text-[var(--text-muted)]" />
                <div>
                  <h3 className="font-bold text-[var(--text-primary)]">Healthy</h3>
                  <p className="text-[var(--text-secondary)]">{player.first_name} is fully healthy and available to play.</p>
                </div>
              </div>
            )}
          </div>

          <div className="grid gap-4 sm:grid-cols-3">
            {player.stamina != null && <InfoCard label="Stamina" value={String(player.stamina)} />}
            <InfoCard label="Age" value={String(player.age)} sub={
              player.age <= 25 ? 'Still developing' :
              player.age <= 30 ? 'In his prime' :
              player.age <= 33 ? 'Late prime' : 'Veteran — decline risk'
            } />
            {player.status && <InfoCard label="Status" value={formatLabel(player.status)} />}
          </div>
        </div>
      )}

      {/* AWARDS */}
      {activeTab === 'awards' && (
        <div className="space-y-6">
          {/* Award Summary Counts */}
          {playerAwards && playerAwards.summary && (
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
              {playerAwards.summary.all_league_first > 0 && (
                <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-4 text-center">
                  <p className="text-3xl font-bold text-[var(--text-primary)]">{playerAwards.summary.all_league_first}x</p>
                  <p className="text-xs text-[var(--text-secondary)] mt-1">All-League First Team</p>
                </div>
              )}
              {playerAwards.summary.all_league_second > 0 && (
                <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-4 text-center">
                  <p className="text-3xl font-bold text-[var(--text-primary)]">{playerAwards.summary.all_league_second}x</p>
                  <p className="text-xs text-[var(--text-secondary)] mt-1">All-League Second Team</p>
                </div>
              )}
              {playerAwards.summary.gridiron_classic > 0 && (
                <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-4 text-center">
                  <p className="text-3xl font-bold text-[var(--text-primary)]">{playerAwards.summary.gridiron_classic}x</p>
                  <p className="text-xs text-[var(--text-secondary)] mt-1">Gridiron Classic</p>
                </div>
              )}
              {playerAwards.summary.mvp > 0 && (
                <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-4 text-center">
                  <p className="text-3xl font-bold text-[var(--text-primary)]">{playerAwards.summary.mvp}x</p>
                  <p className="text-xs text-[var(--text-secondary)] mt-1">MVP</p>
                </div>
              )}
              {playerAwards.summary.opoy > 0 && (
                <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-4 text-center">
                  <p className="text-3xl font-bold text-[var(--text-primary)]">{playerAwards.summary.opoy}x</p>
                  <p className="text-xs text-[var(--text-secondary)] mt-1">Off. Player of the Year</p>
                </div>
              )}
              {playerAwards.summary.dpoy > 0 && (
                <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-4 text-center">
                  <p className="text-3xl font-bold text-[var(--text-primary)]">{playerAwards.summary.dpoy}x</p>
                  <p className="text-xs text-[var(--text-secondary)] mt-1">Def. Player of the Year</p>
                </div>
              )}
            </div>
          )}

          {/* Full Awards List by Year */}
          {playerAwards && playerAwards.list.length > 0 ? (
            <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-[var(--border)] text-left">
                      <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Year</th>
                      <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Award</th>
                    </tr>
                  </thead>
                  <tbody>
                    {playerAwards.list.map((award, i) => (
                      <tr key={`${award.type}-${award.season_year}-${i}`} className="border-b border-[var(--border)] last:border-0">
                        <td className="px-4 py-3 font-semibold text-[var(--text-primary)]">{award.season_year}</td>
                        <td className="px-4 py-3 text-[var(--text-primary)]">{award.label}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          ) : (
            <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-8 text-center">
              <p className="text-[var(--text-secondary)]">No awards yet.</p>
            </div>
          )}
        </div>
      )}

      {/* TRAITS */}
      {activeTab === 'traits' && (
        <div className="space-y-4">
          {/* Edge + Instincts */}
          <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-6">
            <h3 className="font-bold text-[var(--text-primary)] mb-4">Abilities</h3>
            {player.edge ? (
              <div className="flex items-center gap-3 mb-3">
                <Zap className="h-5 w-5 text-[var(--text-muted)]" />
                <div>
                  <p className="text-xs text-[var(--text-muted)]">Edge</p>
                  <p className="font-semibold text-[var(--text-primary)]">{player.edge}</p>
                </div>
              </div>
            ) : (
              <p className="text-[var(--text-secondary)] mb-3">No Edge ability.</p>
            )}
            {abilities.length > 0 ? (
              abilities.map((a) => (
                <div key={a} className="flex items-center gap-3 mb-3">
                  <Star className="h-5 w-5 text-[var(--text-muted)]" />
                  <div>
                    <p className="text-xs text-[var(--text-muted)]">Instinct</p>
                    <p className="font-semibold text-[var(--text-primary)]">{a}</p>
                  </div>
                </div>
              ))
            ) : !player.edge ? (
              <p className="text-[var(--text-secondary)]">No instincts.</p>
            ) : null}
          </div>

          {/* Trait cards */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {player.personality && <InfoCard label="Personality" value={formatLabel(player.personality)} />}
            {player.potential && <InfoCard label="Development" value={getDevelopmentLabel(player.potential)} />}
            {player.archetype && <InfoCard label="Blueprint" value={player.archetype} />}
            {player.running_style && <InfoCard label="Running Style" value={player.running_style} />}
            {player.handedness && <InfoCard label="Handedness" value={player.handedness} />}
          </div>

          <div className="text-center">
            <button onClick={() => navigate('/glossary')} className="text-sm text-[var(--accent-blue)] hover:underline">
              View glossary of Edges, Instincts, Blueprints & Development tiers
            </button>
          </div>
        </div>
      )}

      </div>{/* end min-h tab content wrapper */}

      {/* Modals */}
      <PlayerComparison playerId={player.id} playerName={`${player.first_name} ${player.last_name}`} open={compareModalOpen} onOpenChange={setCompareModalOpen} />

      {/* Bid Dialog */}
      {showBidDialog && freeAgent && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60" onClick={() => setShowBidDialog(false)}>
          <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-6 w-full max-w-md shadow-xl" onClick={e => e.stopPropagation()}>
            <h3 className="font-display text-lg mb-1">Make an Offer</h3>
            <p className="text-sm text-[var(--text-secondary)] mb-4">
              {player?.first_name} {player?.last_name} — {player?.position}, {player?.overall_rating} OVR
            </p>
            <div className="space-y-3">
              <div>
                <label className="block text-xs font-semibold text-[var(--text-muted)] mb-1">Salary ($/year in millions)</label>
                <input
                  type="number"
                  step="0.1"
                  min="0.5"
                  value={bidSalary}
                  onChange={e => setBidSalary(e.target.value)}
                  className="w-full rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] px-3 py-2 text-sm"
                  placeholder="e.g. 5.0"
                />
                <p className="text-xs text-[var(--text-muted)] mt-1">Market value: ${(freeAgent.market_value / 1_000_000).toFixed(1)}M/yr</p>
              </div>
              <div>
                <label className="block text-xs font-semibold text-[var(--text-muted)] mb-1">Contract Length (years)</label>
                <select
                  value={bidYears}
                  onChange={e => setBidYears(e.target.value)}
                  className="w-full rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] px-3 py-2 text-sm"
                >
                  {[1,2,3,4,5,6,7].map(y => <option key={y} value={y}>{y} year{y > 1 ? 's' : ''}</option>)}
                </select>
              </div>
            </div>
            <div className="flex gap-2 mt-5">
              <button
                onClick={() => setShowBidDialog(false)}
                className="flex-1 rounded-lg border border-[var(--border)] px-4 py-2 text-sm font-medium hover:bg-[var(--bg-elevated)] transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleSign}
                disabled={signing}
                className="flex-1 rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-500 transition-colors disabled:opacity-50"
              >
                {signing ? 'Submitting...' : 'Submit Bid'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// --- Reusable sub-components ---

function DetailLine({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline gap-4">
      <span className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] w-24 shrink-0">{label}</span>
      <span className="text-sm font-medium text-[var(--text-primary)]">{value}</span>
    </div>
  );
}

function InfoCard({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-4">
      <p className="text-xs text-[var(--text-muted)] mb-1">{label}</p>
      <p className="text-lg font-bold text-[var(--text-primary)]">{value}</p>
      {sub && <p className="text-xs text-[var(--text-secondary)] mt-0.5">{sub}</p>}
    </div>
  );
}

function StatsBlock({ label, data, position }: { label: string; data: Record<string, number> | null; position: string }) {
  if (!data || Object.keys(data).length === 0) {
    return <p className="text-center text-[var(--text-secondary)] py-8">No stats available.</p>;
  }

  const posStatCols = getStatsForPosition(position);

  return (
    <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-5 sm:p-6">
      <h3 className="text-sm font-bold uppercase tracking-wider text-[var(--text-muted)] mb-4">{label}</h3>
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
        {posStatCols.map(([key, colLabel]) => {
          const val = data[key];
          if (val == null) return null;
          const isPct = key.endsWith('_pct');
          return (
            <div key={key} className="py-2 border-b border-[var(--border)]">
              <p className="text-xs text-[var(--text-muted)] mb-1">{colLabel}</p>
              <p className="text-2xl font-bold text-[var(--text-primary)]">{isPct ? `${val}%` : val}</p>
            </div>
          );
        })}
      </div>
      {data.games_played != null && (
        <p className="text-sm text-[var(--text-muted)] mt-4">
          {data.games_played} game{data.games_played !== 1 ? 's' : ''} played
        </p>
      )}
    </div>
  );
}

function CareerTable({
  years,
  position,
  careerTotals,
}: {
  years: (Record<string, unknown> & { season_year: number; team_abbr?: string; team_color?: string })[];
  position: string;
  careerTotals: Record<string, number> | null;
}) {
  const posStatCols = getStatsForPosition(position);

  return (
    <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
      <div className="p-5 sm:p-6 pb-0">
        <h3 className="text-sm font-bold uppercase tracking-wider text-[var(--text-muted)] mb-4">Career Stats</h3>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-[var(--border)] text-left">
              <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Year</th>
              <th className="px-3 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">TM</th>
              <th className="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">GP</th>
              {posStatCols.map(([, label]) => (
                <th key={label} className="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                  {label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {years.map((yr, yi) => (
              <tr key={`${yr.season_year}-${yr.team_abbr ?? yi}`} className="border-b border-[var(--border)] last:border-0 hover:bg-[var(--bg-elevated)] transition-colors">
                <td className="px-4 py-3 font-semibold text-[var(--text-primary)]">{yr.season_year}</td>
                <td className="px-3 py-3">
                  {yr.team_abbr ? (
                    <span
                      className="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold text-white"
                      style={{ backgroundColor: yr.team_color ?? 'var(--text-muted)' }}
                    >
                      {yr.team_abbr}
                    </span>
                  ) : (
                    <span className="text-[var(--text-muted)]">—</span>
                  )}
                </td>
                <td className="px-3 py-3 text-center">{Number(yr.games_played ?? 0)}</td>
                {posStatCols.map(([colKey, ]) => {
                  const val = yr[colKey];
                  const isPct = colKey.endsWith('_pct');
                  const display = val != null ? (isPct ? `${val}%` : String(val)) : '—';
                  return (
                    <td key={colKey} className={`px-3 py-3 text-center ${val != null && Number(val) > 0 ? 'font-semibold' : 'text-[var(--text-muted)]'}`}>
                      {display}
                    </td>
                  );
                })}
              </tr>
            ))}

            {/* Career totals row */}
            {careerTotals && (
              <tr className="border-t-2 border-[var(--border)] bg-[var(--bg-elevated)]">
                <td className="px-4 py-3 font-bold text-[var(--text-primary)]">Career</td>
                <td className="px-3 py-3"></td>
                <td className="px-3 py-3 text-center font-bold">{careerTotals.games_played ?? 0}</td>
                {posStatCols.map(([colKey, ]) => {
                  const val = careerTotals[colKey];
                  const isPct = colKey.endsWith('_pct');
                  const display = val != null ? (isPct ? `${val}%` : String(val)) : '—';
                  return (
                    <td key={colKey} className="px-3 py-3 text-center font-bold">
                      {display}
                    </td>
                  );
                })}
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
