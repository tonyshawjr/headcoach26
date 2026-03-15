import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useBoxScore, useGameArticles } from '@/hooks/useApi';
import { PlayerPhoto } from '@/components/PlayerPhoto';
import { TeamBadge } from '@/components/TeamBadge';
import { motion } from 'framer-motion';
import type { GameLogEntry } from '@/api/client';

export default function BoxScore() {
  const { id } = useParams<{ id: string }>();
  const gameId = Number(id);
  const { data, isLoading } = useBoxScore(gameId);
  const { data: articles } = useGameArticles(gameId);
  const [statsTab, setStatsTab] = useState<'away' | 'home'>('away');
  const [statsSide, setStatsSide] = useState<'offense' | 'defense'>('offense');
  const [mainTab, setMainTab] = useState<'stats' | 'plays' | 'players' | 'recaps'>('stats');

  if (isLoading) return <p className="text-[var(--text-secondary)] p-8">Loading box score...</p>;
  if (!data) return <p className="text-[var(--text-secondary)] p-8">Game not found.</p>;

  const { game, home, away } = data;
  const homeTeam = game.home_team;
  const awayTeam = game.away_team;
  const homeWon = (game.home_score ?? 0) > (game.away_score ?? 0);

  const awayTotals = away?.totals ?? {} as Record<string, number>;
  const homeTotals = home?.totals ?? {} as Record<string, number>;

  return (
    <div className="space-y-6">
      {/* ── Scoreboard — ESPN-style angled team banners ── */}
      <div className="rounded-xl border border-[var(--border)] overflow-hidden">
        {/* Week / Game info bar */}
        <div className="bg-[var(--bg-elevated)] px-4 py-2 text-center">
          <span className="text-xs font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">
            {game.game_type === 'playoff' ? 'Playoff Game' : `Week ${game.week}`}
            {game.weather && game.weather !== 'clear' && game.weather !== 'dome' ? ` · ${game.weather}` : ''}
            {' · '}Final
          </span>
        </div>

        {/* Team banners with scores */}
        <div className="relative flex items-stretch" style={{ minHeight: 120 }}>
          {/* Away team — left side (clickable → team page) */}
          <Link
            to={`/team/${game.away_team_id}`}
            className="relative flex-1 flex items-center overflow-hidden cursor-pointer"
            style={{
              background: `linear-gradient(135deg, ${awayTeam?.primary_color ?? '#333'}, ${awayTeam?.secondary_color ?? awayTeam?.primary_color ?? '#333'})`,
              clipPath: 'polygon(0 0, 100% 0, 92% 100%, 0 100%)',
            }}
          >
            {/* Large angled abbreviation — far left */}
            <div className="relative z-10 pl-5 sm:pl-8 py-4 shrink-0">
              <span
                className="font-display font-black text-white/15 select-none block"
                style={{ fontSize: '80px', lineHeight: 0.85, letterSpacing: '-0.04em' }}
              >
                {awayTeam?.abbreviation ?? ''}
              </span>
            </div>

            {/* Team name + winner */}
            <div className="relative z-10 flex-1 px-4 py-4">
              <p className="text-sm text-white/70 font-medium">{awayTeam?.city}</p>
              <p className="font-display text-lg sm:text-xl font-black text-white uppercase tracking-tight">
                {awayTeam?.name}
              </p>
              {!homeWon && <p className="text-[10px] font-bold uppercase tracking-widest text-white/60 mt-0.5">Winner</p>}
            </div>

            {/* Score — right side of away banner */}
            <motion.div
              initial={{ scale: 0.8, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              transition={{ delay: 0.15 }}
              className="relative z-10 pr-14 sm:pr-20 shrink-0"
            >
              <span className={`font-stat text-5xl sm:text-6xl font-bold ${!homeWon ? 'text-white' : 'text-white/50'}`}>
                {game.away_score ?? 0}
              </span>
            </motion.div>
          </Link>

          {/* Home team — right side (clickable → team page) */}
          <Link
            to={`/team/${game.home_team_id}`}
            className="relative flex-1 flex items-center overflow-hidden cursor-pointer"
            style={{
              background: `linear-gradient(135deg, ${homeTeam?.secondary_color ?? homeTeam?.primary_color ?? '#333'}, ${homeTeam?.primary_color ?? '#333'})`,
              clipPath: 'polygon(8% 0, 100% 0, 100% 100%, 0 100%)',
              marginLeft: '-4%',
            }}
          >
            {/* Score — left side of home banner */}
            <motion.div
              initial={{ scale: 0.8, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              transition={{ delay: 0.15 }}
              className="relative z-10 pl-14 sm:pl-20 shrink-0"
            >
              <span className={`font-stat text-5xl sm:text-6xl font-bold ${homeWon ? 'text-white' : 'text-white/50'}`}>
                {game.home_score ?? 0}
              </span>
            </motion.div>

            {/* Team name + winner */}
            <div className="relative z-10 flex-1 px-4 py-4 text-right">
              <p className="text-sm text-white/70 font-medium">{homeTeam?.city}</p>
              <p className="font-display text-lg sm:text-xl font-black text-white uppercase tracking-tight">
                {homeTeam?.name}
              </p>
              {homeWon && <p className="text-[10px] font-bold uppercase tracking-widest text-white/60 mt-0.5">Winner</p>}
            </div>

            {/* Large angled abbreviation — far right */}
            <div className="relative z-10 pr-5 sm:pr-8 py-4 shrink-0">
              <span
                className="font-display font-black text-white/15 select-none block text-right"
                style={{ fontSize: '80px', lineHeight: 0.85, letterSpacing: '-0.04em' }}
              >
                {homeTeam?.abbreviation ?? ''}
              </span>
            </div>
          </Link>
        </div>

        {/* Turning point */}
        {game.turning_point && (
          <div className="px-6 py-3 bg-[var(--bg-surface)] border-t border-[var(--border)]">
            <p className="text-sm text-[var(--text-secondary)]">
              <span className="font-semibold text-[var(--text-primary)]">Turning Point</span>
              {' — '}
              {game.turning_point}
            </p>
          </div>
        )}
      </div>

      {/* ── Main Tabs ── */}
      <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
        {/* Tab bar */}
        <div className="flex border-b-2 border-[var(--border)]">
          {([
            { key: 'stats', label: 'Team Stats' },
            ...(data.game_log && data.game_log.length > 0 ? [{ key: 'plays', label: 'Play-by-Play' }] : []),
            { key: 'players', label: 'Player Stats' },
            ...(articles && articles.length > 0 ? [{ key: 'recaps', label: 'Recaps' }] : []),
          ] as { key: typeof mainTab; label: string }[]).map((tab) => (
            <button
              key={tab.key}
              onClick={() => setMainTab(tab.key)}
              className={`relative flex-1 px-4 py-3 text-xs font-bold uppercase tracking-[0.1em] transition-colors ${
                mainTab === tab.key
                  ? 'text-[var(--text-primary)]'
                  : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
              }`}
            >
              {tab.label}
              {mainTab === tab.key && (
                <motion.div
                  layoutId="boxscore-tab"
                  className="absolute bottom-0 left-0 right-0 h-[3px] rounded-t-full bg-[var(--accent-blue)]"
                  transition={{ type: 'spring', stiffness: 500, damping: 35 }}
                />
              )}
            </button>
          ))}
        </div>

        {/* Tab content */}
        {mainTab === 'stats' && (
          <div className="p-5 sm:p-6">
            <div className="space-y-0">
              <ComparisonRow
                label="Passing Yards"
                awayVal={awayTotals.pass_yards ?? 0}
                homeVal={homeTotals.pass_yards ?? 0}
                awayTeam={awayTeam?.abbreviation ?? 'AWY'}
                homeTeam={homeTeam?.abbreviation ?? 'HME'}
              />
              <ComparisonRow
                label="Rushing Yards"
                awayVal={awayTotals.rush_yards ?? 0}
                homeVal={homeTotals.rush_yards ?? 0}
              />
              <ComparisonRow
                label="Total Yards"
                awayVal={awayTotals.total_yards ?? 0}
                homeVal={homeTotals.total_yards ?? 0}
              />
              <ComparisonRow
                label="Turnovers"
                awayVal={awayTotals.turnovers ?? 0}
                homeVal={homeTotals.turnovers ?? 0}
              />
              <ComparisonRow
                label="Sacks"
                awayVal={awayTotals.sacks ?? 0}
                homeVal={homeTotals.sacks ?? 0}
              />
              <ComparisonRow
                label="Penalties"
                awayVal={`${awayTotals.penalties ?? 0}-${awayTotals.penalty_yards ?? 0}`}
                homeVal={`${homeTotals.penalties ?? 0}-${homeTotals.penalty_yards ?? 0}`}
              />
              <ComparisonRow
                label="3rd Down %"
                awayVal={`${awayTotals.third_down_pct ?? 0}%`}
                homeVal={`${homeTotals.third_down_pct ?? 0}%`}
                isLast
              />
            </div>
          </div>
        )}

        {mainTab === 'plays' && data.game_log && data.game_log.length > 0 && (
          <PlayByPlayViewer
            gameLog={data.game_log}
            homeTeam={homeTeam}
            awayTeam={awayTeam}
          />
        )}

        {mainTab === 'players' && (
          <>
            <div className="px-5 pt-4 sm:px-6">
              {/* Team selector */}
              <div className="flex items-center justify-between border-b border-[var(--border)]">
                <div className="flex gap-1">
                  <button
                    onClick={() => setStatsTab('away')}
                    className={`px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition-colors ${
                      statsTab === 'away'
                        ? 'border-[var(--accent-blue)] text-[var(--text-primary)]'
                        : 'border-transparent text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
                    }`}
                  >
                    {awayTeam?.abbreviation ?? 'Away'}
                  </button>
                  <button
                    onClick={() => setStatsTab('home')}
                    className={`px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition-colors ${
                      statsTab === 'home'
                        ? 'border-[var(--accent-blue)] text-[var(--text-primary)]'
                        : 'border-transparent text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
                    }`}
                  >
                    {homeTeam?.abbreviation ?? 'Home'}
                  </button>
                </div>
                {/* Offense / Defense toggle */}
                <div className="flex rounded-lg border border-[var(--border)] bg-[var(--bg-elevated)] p-0.5">
                  <button
                    onClick={() => setStatsSide('offense')}
                    className={`px-3 py-1.5 rounded-md text-xs font-bold uppercase tracking-wider transition-all ${
                      statsSide === 'offense'
                        ? 'bg-[var(--accent-blue)] text-white shadow-sm'
                        : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'
                    }`}
                  >
                    Offense
                  </button>
                  <button
                    onClick={() => setStatsSide('defense')}
                    className={`px-3 py-1.5 rounded-md text-xs font-bold uppercase tracking-wider transition-all ${
                      statsSide === 'defense'
                        ? 'bg-[var(--accent-blue)] text-white shadow-sm'
                        : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'
                    }`}
                  >
                    Defense
                  </button>
                </div>
              </div>
            </div>
            <div className="overflow-x-auto">
              <PlayerStatsTable
                players={statsTab === 'away' ? (away?.players ?? []) : (home?.players ?? [])}
                side={statsSide}
              />
            </div>
          </>
        )}

        {mainTab === 'recaps' && articles && articles.length > 0 && (
          <div className="p-5 sm:p-6 space-y-4">
            {articles.map((article: { id: number; headline: string; author_name?: string; body: string }) => (
              <div
                key={article.id}
                className="rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] p-5"
              >
                <h3 className="text-lg font-bold text-[var(--text-primary)] leading-snug">{article.headline}</h3>
                {article.author_name && (
                  <p className="mt-1 text-sm text-[var(--text-muted)]">
                    By {article.author_name}
                  </p>
                )}
                <div className="mt-4 space-y-3 leading-relaxed text-[var(--text-secondary)]">
                  {article.body.split('\n\n').map((para: string, i: number) => (
                    <p key={i}>{para}</p>
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

/* ── Stat Comparison Row ── */

function ComparisonRow({
  label,
  awayVal,
  homeVal,
  awayTeam: _awayTeam,
  homeTeam: _homeTeam,
  isLast,
}: {
  label: string;
  awayVal: string | number;
  homeVal: string | number;
  awayTeam?: string;
  homeTeam?: string;
  isLast?: boolean;
}) {
  return (
    <div className={`flex items-center justify-between py-3 ${!isLast ? 'border-b border-[var(--border)]' : ''}`}>
      <span className="w-20 text-right font-semibold text-[var(--text-primary)]">{awayVal}</span>
      <span className="flex-1 text-center text-sm text-[var(--text-secondary)]">{label}</span>
      <span className="w-20 text-left font-semibold text-[var(--text-primary)]">{homeVal}</span>
    </div>
  );
}

/* ── Player Stats Table ── */

type PlayerRecord = { id: number; name: string; position: string; [k: string]: string | number };

const offenseCols = [
  { key: 'cmp_att',    label: 'C/A',  full: 'Completions / Attempts' },
  { key: 'pass_yards', label: 'PYD',  full: 'Passing Yards' },
  { key: 'pass_tds',   label: 'PTD',  full: 'Passing Touchdowns' },
  { key: 'interceptions', label: 'INT', full: 'Interceptions Thrown' },
  { key: 'rush_attempts', label: 'CAR', full: 'Carries' },
  { key: 'rush_yards', label: 'RYD',  full: 'Rushing Yards' },
  { key: 'rush_tds',   label: 'RTD',  full: 'Rushing Touchdowns' },
  { key: 'targets',    label: 'TGT',  full: 'Targets' },
  { key: 'receptions', label: 'REC',  full: 'Receptions' },
  { key: 'rec_yards',  label: 'REYD', full: 'Receiving Yards' },
  { key: 'rec_tds',    label: 'RETD', full: 'Receiving Touchdowns' },
] as const;

const defenseCols = [
  { key: 'tackles',    label: 'TKL',  full: 'Tackles' },
  { key: 'sacks',      label: 'SCK',  full: 'Sacks' },
  { key: 'interceptions_def', label: 'INT', full: 'Interceptions' },
  { key: 'forced_fumbles', label: 'FF', full: 'Forced Fumbles' },
  { key: 'penalties',  label: 'PEN',  full: 'Penalties' },
  { key: 'penalty_yards', label: 'PNYD', full: 'Penalty Yards' },
] as const;

const offensePositions = new Set(['QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C', 'K', 'P']);

function PlayerStatsTable({ players, side }: { players: PlayerRecord[]; side: 'offense' | 'defense' }) {
  const isOffense = side === 'offense';
  const cols = isOffense ? offenseCols : defenseCols;

  // Show all players for the selected side — no activity filter
  const filtered = players.filter((p) => {
    const isOffPos = offensePositions.has(p.position);
    return isOffense ? isOffPos : !isOffPos;
  });

  // Sort: QBs first, then RBs, then WR/TE for offense; DE, DT, LB, CB, S for defense
  const posOrder: Record<string, number> = { QB: 0, RB: 1, WR: 2, TE: 3, K: 4, P: 5, DE: 0, DT: 1, LB: 2, CB: 3, S: 4 };
  const sorted = [...filtered].sort((a, b) => (posOrder[a.position] ?? 10) - (posOrder[b.position] ?? 10));

  if (sorted.length === 0) {
    return <p className="p-6 text-center text-[var(--text-secondary)]">No {side} stats recorded</p>;
  }

  return (
    <table className="w-full text-sm">
      <thead>
        <tr className="border-b border-[var(--border)] text-left">
          <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
            Player
          </th>
          <th className="px-3 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
            Pos
          </th>
          {cols.map((c) => (
            <th key={c.key} className="px-3 py-3 text-center text-xs text-[var(--text-muted)]" title={c.full}>
              <span className="font-semibold uppercase tracking-wider">{c.label}</span>
              <span className="block text-[9px] font-normal normal-case tracking-normal mt-0.5 leading-none">{c.full}</span>
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {sorted.map((p) => (
          <tr key={p.id} className="border-b border-[var(--border)] last:border-0 hover:bg-[var(--bg-elevated)] transition-colors">
            <td className="py-0 px-4">
              <Link to={`/player/${p.id}`} className="flex items-center gap-3 font-medium text-[var(--accent-blue)] hover:underline">
                <PlayerPhoto imageUrl={(p as any).image_url} size={36} />
                {p.name}
              </Link>
            </td>
            <td className="px-3 py-3 text-[var(--text-secondary)]">{p.position}</td>
            {cols.map((c) => {
              if (c.key === 'cmp_att') {
                const comp = Number(p['pass_completions'] ?? 0);
                const att = Number(p['pass_attempts'] ?? 0);
                if (att === 0) return <td key={c.key} className="px-3 py-3 text-center text-[var(--text-muted)]">—</td>;
                return <td key={c.key} className="px-3 py-3 text-center font-semibold">{comp}/{att}</td>;
              }
              const v = Number(p[c.key] ?? 0);
              return (
                <td key={c.key} className={`px-3 py-3 text-center ${v > 0 ? 'font-semibold' : 'text-[var(--text-muted)]'}`}>
                  {v || '—'}
                </td>
              );
            })}
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/* ── Play-by-Play Viewer ── */

function formatClock(seconds: number): string {
  const m = Math.floor(Math.max(0, seconds) / 60);
  const s = Math.max(0, seconds) % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

function yardLineLabel(yardLine: number, possession: string): string {
  if (yardLine === 50) return '50';
  if (yardLine > 50) return `OPP ${100 - yardLine}`;
  return `OWN ${yardLine}`;
}

function playTypeColor(type: string): string {
  switch (type) {
    case 'completion': return 'bg-green-500';
    case 'incomplete': return 'bg-[var(--text-muted)]';
    case 'run': return 'bg-blue-400';
    case 'sack': return 'bg-red-400';
    case 'interception': return 'bg-red-500';
    case 'field_goal': return 'bg-yellow-400';
    case 'penalty': return 'bg-yellow-500';
    case 'injury': return 'bg-red-400';
    case 'substitution': return 'bg-blue-400';
    case 'timeout': return 'bg-[var(--text-muted)]';
    case 'halftime_adjustment': return 'bg-purple-400';
    case 'kick_return_td':
    case 'punt_return_td': return 'bg-yellow-400';
    default: return 'bg-[var(--text-muted)]';
  }
}

function buildPlayDescription(entry: GameLogEntry): string {
  const p = entry.play;
  const player = p.player ?? '';
  const target = p.target ?? '';
  const defender = p.defender ?? '';
  const yards = p.yards;

  switch (p.type) {
    case 'completion':
      return `${player} pass complete to ${target} for ${yards} yds${p.depth ? ` (${p.depth})` : ''}${defender ? ` [${defender}]` : ''}`;
    case 'incomplete':
      return `${player} pass incomplete${target ? ` intended for ${target}` : ''}${p.depth ? ` (${p.depth})` : ''}${defender ? ` [${defender}]` : ''}`;
    case 'run':
      return `${player} rush for ${yards} yds${defender ? ` [${defender}]` : ''}`;
    case 'sack':
      return `${player} sacked for ${yards} yds${defender ? ` by ${defender}` : ''}`;
    case 'interception':
      return `${player} pass INTERCEPTED${defender ? ` by ${defender}` : ''}${target ? ` (intended ${target})` : ''}`;
    case 'field_goal':
      return `${player} ${p.distance ?? '??'}-yard field goal ${p.made ? 'GOOD' : 'NO GOOD'}`;
    case 'penalty':
    case 'injury':
    case 'substitution':
    case 'timeout':
    case 'halftime_adjustment':
    case 'kick_return_td':
    case 'punt_return_td':
    case 'kick_return':
    case 'punt_return':
    case 'muffed_punt':
    case 'onside_kick':
      return entry.note ?? '';
    default:
      return entry.note ?? `${p.type} — ${yards} yds`;
  }
}

function isHighlightPlay(entry: GameLogEntry): boolean {
  return entry.key_play === true;
}

interface TeamInfo {
  abbreviation?: string;
  primary_color?: string;
  secondary_color?: string;
  city?: string;
  name?: string;
}

function PlayByPlayViewer({
  gameLog,
  homeTeam,
  awayTeam,
}: {
  gameLog: GameLogEntry[];
  homeTeam?: TeamInfo;
  awayTeam?: TeamInfo;
}) {
  const [filterQuarter, setFilterQuarter] = useState<number | 'all'>('all');
  const [keyPlaysOnly, setKeyPlaysOnly] = useState(false);

  // Detect real overtime: last Q4 entry has tied score
  const lastQ4 = [...gameLog].reverse().find((e) => e.quarter === 4);
  const hadOvertime = lastQ4 ? lastQ4.home_score === lastQ4.away_score : false;

  // Filter out phantom Q5 entries if there was no real OT
  const validLog = hadOvertime ? gameLog : gameLog.filter((e) => e.quarter <= 4);

  // Group by quarter
  const quarters = [...new Set(validLog.map((e) => e.quarter))].sort();

  const filtered = validLog.filter((e) => {
    if (filterQuarter !== 'all' && e.quarter !== filterQuarter) return false;
    if (keyPlaysOnly && !e.key_play) return false;
    return true;
  });

  return (
    <div className="border-t border-[var(--border)]">
      {/* Filters */}
      <div className="flex items-center justify-between px-5 py-3 border-b border-[var(--border)] bg-[var(--bg-elevated)]/40">
        <div className="flex items-center gap-1">
          <button
            onClick={() => setFilterQuarter('all')}
            className={`px-3 py-1.5 rounded text-xs font-bold uppercase tracking-wider transition-colors ${
              filterQuarter === 'all'
                ? 'bg-[var(--accent-blue)] text-white'
                : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'
            }`}
          >
            All
          </button>
          {quarters.map((q) => (
            <button
              key={q}
              onClick={() => setFilterQuarter(q)}
              className={`px-3 py-1.5 rounded text-xs font-bold uppercase tracking-wider transition-colors ${
                filterQuarter === q
                  ? 'bg-[var(--accent-blue)] text-white'
                  : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'
              }`}
            >
              {q <= 4 ? `Q${q}` : 'OT'}
            </button>
          ))}
        </div>
        <button
          onClick={() => setKeyPlaysOnly(!keyPlaysOnly)}
          className={`px-3 py-1.5 rounded text-xs font-bold uppercase tracking-wider transition-colors ${
            keyPlaysOnly
              ? 'bg-[var(--accent-gold)] text-white'
              : 'text-[var(--text-muted)] hover:text-[var(--text-primary)] border border-[var(--border)]'
          }`}
        >
          Key Plays
        </button>
      </div>

      {/* Play list */}
      <div className="max-h-[600px] overflow-y-auto">
        {filtered.map((entry, i) => {
          const dotColor = playTypeColor(entry.play.type);
          const highlight = isHighlightPlay(entry);
          const isPenalty = entry.note?.includes('PENALTY');
          const isScoring = entry.note?.toLowerCase().includes('touchdown') || (entry.play.type === 'field_goal' && entry.play.made);
          const isInjury = entry.play.type === 'injury';
          const possTeam = entry.possession === 'home' ? homeTeam : awayTeam;

          return (
            <div
              key={i}
              className={`flex items-start gap-3 px-5 py-2.5 border-b border-[var(--border)] last:border-0 ${
                isScoring
                  ? 'bg-green-500/5 border-l-2 border-l-green-500'
                  : isPenalty
                    ? 'bg-yellow-500/5 border-l-2 border-l-yellow-500'
                    : isInjury
                      ? 'bg-red-500/5 border-l-2 border-l-red-500'
                      : highlight
                        ? 'bg-[var(--bg-elevated)]/60 border-l-2 border-l-[var(--accent-blue)]'
                        : ''
              }`}
            >
              {/* Quarter & Clock */}
              <div className="w-14 shrink-0 text-right">
                <span className="text-[10px] font-bold uppercase tracking-wider text-[var(--text-muted)]">
                  {entry.quarter <= 4 ? `Q${entry.quarter}` : 'OT'}
                </span>
                <p className="font-stat text-xs text-[var(--text-secondary)]">
                  {formatClock(entry.clock)}
                </p>
              </div>

              {/* Play type dot */}
              <div className="w-3 shrink-0 pt-2">
                <div className={`h-2 w-2 rounded-full ${dotColor}`} />
              </div>

              {/* Situation & Description */}
              <div className="flex-1 min-w-0">
                {/* Situation line */}
                <div className="flex items-center gap-2 mb-0.5">
                  {possTeam && (
                    <span
                      className="inline-block h-2.5 w-2.5 rounded-full shrink-0"
                      style={{ backgroundColor: possTeam.primary_color ?? 'var(--accent-blue)' }}
                      title={`${possTeam.city} ${possTeam.name}`}
                    />
                  )}
                  {entry.down > 0 && entry.play.type !== 'penalty' && entry.play.type !== 'injury' && entry.play.type !== 'timeout' && entry.play.type !== 'halftime_adjustment' && entry.play.type !== 'substitution' && (
                    <span className="text-[10px] font-semibold text-[var(--text-muted)]">
                      {entry.down}&{entry.distance} at {yardLineLabel(entry.yard_line, entry.possession)}
                    </span>
                  )}
                </div>

                {/* Play description */}
                <p className={`text-sm leading-snug ${highlight ? 'font-semibold text-[var(--text-primary)]' : 'text-[var(--text-secondary)]'}`}>
                  {entry.note && (entry.play.type === 'penalty' || entry.play.type === 'injury' || entry.play.type === 'substitution' || entry.play.type === 'timeout' || entry.play.type === 'halftime_adjustment')
                    ? entry.note
                    : buildPlayDescription(entry)
                  }
                </p>

                {/* Note for scoring/turnover plays */}
                {entry.note && entry.play.type !== 'penalty' && entry.play.type !== 'injury' && entry.play.type !== 'substitution' && entry.play.type !== 'timeout' && entry.play.type !== 'halftime_adjustment' && (
                  <p className={`text-[11px] mt-0.5 font-semibold ${
                    isScoring ? 'text-green-400' : 'text-[var(--accent-blue)]'
                  }`}>
                    {entry.note}
                  </p>
                )}
              </div>

              {/* Score */}
              <div className="w-16 shrink-0 text-right">
                <span className="font-stat text-xs text-[var(--text-secondary)]">
                  {entry.away_score}-{entry.home_score}
                </span>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
