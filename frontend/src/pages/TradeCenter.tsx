import { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { motion, AnimatePresence } from 'framer-motion';
import { ArrowLeftRight, ArrowLeft, TrendingUp, TrendingDown, Shield, Star, Gem, User, Target, Handshake, Loader2, AlertCircle, RefreshCw } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TeamLogo } from '@/components/TeamLogo';
import { PlayerPhoto } from '@/components/PlayerPhoto';
import { toast } from 'sonner';
import type { FindTradeResult, TradeOpportunity, TradePackageSide } from '@/api/client';
import {
  PageLayout,
  PageHeader,
  SectionHeader,
  ActionButton,
  EmptyBlock,
} from '@/components/ui/sports-ui';

/* ================================================================
   CONSTANTS & HELPERS
   ================================================================ */

const interestConfig: Record<string, { bg: string; text: string; border: string; label: string }> = {
  high: { bg: 'bg-green-500/15', text: 'text-green-400', border: 'border-green-500/25', label: 'HIGH INTEREST' },
  medium: { bg: 'bg-yellow-500/15', text: 'text-yellow-400', border: 'border-yellow-500/25', label: 'MEDIUM INTEREST' },
  low: { bg: 'bg-gray-500/15', text: 'text-gray-400', border: 'border-gray-500/25', label: 'LOW INTEREST' },
};

const modeConfig: Record<string, { color: string; label: string; icon: typeof TrendingUp }> = {
  contender: { color: 'text-emerald-400', label: 'Contender', icon: TrendingUp },
  competitive: { color: 'text-blue-400', label: 'Competitive', icon: Target },
  rebuilding: { color: 'text-orange-400', label: 'Rebuilding', icon: TrendingDown },
};

/** OVR tier label — never rely on color alone */
function ratingTier(r: number): string {
  if (r >= 90) return 'ELITE';
  if (r >= 80) return 'STAR';
  if (r >= 70) return 'SOLID';
  return '';
}

/** Determine if a hex color is light or dark — returns true if light */
function isLightColor(hex?: string): boolean {
  if (!hex) return false;
  const c = hex.replace('#', '');
  if (c.length < 6) return false;
  const r = parseInt(c.substring(0, 2), 16);
  const g = parseInt(c.substring(2, 4), 16);
  const b = parseInt(c.substring(4, 6), 16);
  // Perceived luminance formula
  const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return luminance > 0.55;
}

function devTraitIcon(trait?: string): { icon: typeof Star; label: string; color: string } | null {
  if (!trait) return null;
  const t = trait.toLowerCase();
  if (t === 'star' || t === 'superstar' || t === 'x-factor' || t === 'elite')
    return { icon: Star, label: 'Elite Dev', color: 'text-yellow-400' };
  if (t === 'hidden' || t === 'hidden_dev')
    return { icon: Gem, label: 'Hidden Dev', color: 'text-purple-400' };
  if (t === 'high')
    return { icon: TrendingUp, label: 'High Dev', color: 'text-emerald-400' };
  if (t === 'average' || t === 'normal')
    return { icon: User, label: 'Normal Dev', color: 'text-[var(--text-muted)]' };
  if (t === 'limited' || t === 'low')
    return { icon: TrendingDown, label: 'Limited Dev', color: 'text-[var(--text-muted)]' };
  return null;
}

/** Position-based key stat label */
function keyStatLabel(position: string): string {
  const map: Record<string, string> = {
    QB: 'Accuracy', WR: 'Speed', HB: 'Vision', RB: 'Vision',
    TE: 'Catching', OL: 'Pass Block', LT: 'Pass Block', LG: 'Pass Block',
    C: 'Pass Block', RG: 'Run Block', RT: 'Run Block',
    DL: 'Block Shed', DE: 'Finesse', DT: 'Power',
    MLB: 'Pursuit', LOLB: 'Speed', ROLB: 'Speed', LB: 'Pursuit',
    CB: 'Man Cov', FS: 'Zone Cov', SS: 'Zone Cov', S: 'Zone Cov',
    K: 'Kick Acc', P: 'Kick Pow',
  };
  return map[position] ?? 'Overall';
}

/* ================================================================
   PLAYER MINI-CARD
   Rich mini-card for players inside trade packages.
   Shows OVR, name, position, age, dev trait, key stat, contract.
   ================================================================ */

interface MiniCardPlayer {
  id: number;
  name: string;
  position: string;
  overall_rating: number;
  age: number;
  is_selected?: boolean;
  fills_need?: boolean;
  dev_trait?: string;
  key_stat?: number;
  key_stat_label?: string;
  salary?: number;
  contract_year?: number;
  image_url?: string;
  team_color?: string;
}

function PlayerMiniCard({
  player,
  onNavigate,
  isNew,
}: {
  player: MiniCardPlayer;
  onNavigate?: (id: number) => void;
  isNew?: boolean;
}) {
  const dev = devTraitIcon(player.dev_trait);

  const tier = ratingTier(player.overall_rating);

  return (
    <motion.div
      initial={isNew ? { opacity: 0, scale: 0.95, y: -4 } : false}
      animate={{ opacity: 1, scale: 1, y: 0 }}
      transition={{ duration: 0.4 }}
      className={`
        group relative rounded-lg border bg-[var(--bg-elevated)] overflow-hidden transition-all
        ${isNew
          ? 'border-green-500/40 shadow-[0_0_12px_rgba(34,197,94,0.15)]'
          : 'border-[var(--border)] hover:border-[var(--text-muted)]'
        }
      `}
    >
      {/* New item glow strip */}
      {isNew && (
        <motion.div
          initial={{ opacity: 1 }}
          animate={{ opacity: 0 }}
          transition={{ duration: 2.5, delay: 0.5 }}
          className="absolute inset-0 bg-green-500/5 pointer-events-none"
        />
      )}

      <div className="flex items-stretch min-h-[64px]">
        {/* Player photo — flush bottom and sides, no padding, team color gradient bg */}
        <div
          className="relative shrink-0 w-16 overflow-hidden flex items-end justify-center"
          style={{
            background: player.team_color
              ? `linear-gradient(180deg, ${player.team_color}40, ${player.team_color}90)`
              : 'linear-gradient(180deg, var(--bg-primary), var(--bg-elevated))',
          }}
        >
          {player.image_url ? (
            <img
              src={player.image_url}
              alt=""
              className="w-full object-cover object-top"
              style={{ minHeight: '100%' }}
            />
          ) : (
            <svg
              viewBox="0 0 80 80"
              className="text-white/20"
              style={{ width: 44, height: 44, marginBottom: 2 }}
            >
              <circle cx="40" cy="28" r="14" fill="currentColor" />
              <path d="M12 72c0-15.5 12.5-28 28-28s28 12.5 28 28" fill="currentColor" />
            </svg>
          )}
          {/* OVR badge — solid dark background, always readable */}
          <div className="absolute bottom-0 right-0 flex items-center justify-center bg-black/90 px-1.5 py-0.5 rounded-tl">
            <span className="font-stat text-xs font-bold text-white leading-none">
              {player.overall_rating}
            </span>
          </div>
        </div>

        {/* Player info */}
        <div className="flex-1 min-w-0 p-2.5 flex items-center gap-2">
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-1.5">
              {player.is_selected && (
                <Star className="h-3 w-3 text-amber-400 shrink-0 fill-amber-400" aria-label="Selected player" />
              )}
              {onNavigate ? (
                <button
                  onClick={() => onNavigate(player.id)}
                  className="text-sm font-semibold truncate text-[var(--text-primary)] hover:text-[var(--accent-blue)] transition-colors text-left"
                  title={`View ${player.name} profile`}
                >
                  {player.name}
                </button>
              ) : (
                <span className="text-sm font-semibold truncate text-[var(--text-primary)]">
                  {player.name}
                </span>
              )}
            </div>

            <div className="flex items-center gap-2 mt-0.5 flex-wrap">
              <Badge variant="outline" className="text-[9px] px-1.5 py-0 h-4">
                {player.position}
              </Badge>
              <span className="text-[11px] text-[var(--text-muted)]">Age {player.age}</span>
              {tier && (
                <Badge variant="outline" className="text-[8px] px-1 py-0 h-4 border-white/20 text-white/80 bg-white/5">
                  {tier}
                </Badge>
              )}
              {dev && (
                <span className="flex items-center gap-0.5 text-[10px] text-[var(--text-secondary)]" title={dev.label}>
                  <dev.icon className="h-2.5 w-2.5" />
                  <span className="text-[9px]">{dev.label}</span>
                </span>
              )}
            </div>
          </div>

          {/* Right column: key stat + contract + fills need */}
          <div className="flex flex-col items-end gap-1 shrink-0">
            {player.key_stat !== undefined && (
              <div className="text-right">
                <span className="font-stat text-xs text-[var(--text-primary)]">{player.key_stat}</span>
                <span className="text-[9px] text-[var(--text-muted)] ml-1">
                  {player.key_stat_label ?? keyStatLabel(player.position)}
                </span>
              </div>
            )}
            {player.salary !== undefined && (
              <span className="text-[10px] text-[var(--text-muted)]">
                ${(player.salary / 1_000_000).toFixed(1)}M
                {player.contract_year ? `/${player.contract_year}yr` : ''}
              </span>
            )}
            {player.fills_need && (
              <Badge
                variant="outline"
                className="text-[8px] px-1.5 py-0 border-white/20 bg-white/5 text-white/80"
              >
                FILLS NEED
              </Badge>
            )}
          </div>
        </div>
      </div>
    </motion.div>
  );
}

/* ================================================================
   PICK MINI-CARD
   ================================================================ */

function PickMiniCard({
  pick,
  isNew,
}: {
  pick: { id: number; label: string; round: number; year: number; trade_value: number };
  isNew?: boolean;
}) {
  // Round-based accent color
  const roundColor = pick.round === 1 ? '#d97706' : pick.round === 2 ? '#9ca3af' : pick.round <= 4 ? '#78716c' : '#57534e';

  return (
    <motion.div
      initial={isNew ? { opacity: 0, scale: 0.95, y: -4 } : false}
      animate={{ opacity: 1, scale: 1, y: 0 }}
      transition={{ duration: 0.4 }}
      className={`
        flex items-stretch min-h-[64px] rounded-lg border overflow-hidden transition-all
        ${isNew
          ? 'border-green-500/40 shadow-[0_0_12px_rgba(34,197,94,0.15)]'
          : 'border-[var(--border)]'
        }
      `}
    >
      {/* Angled round badge — matches TeamBadge style */}
      <div
        className="relative shrink-0 w-16 flex items-center justify-center overflow-hidden"
        style={{ background: `linear-gradient(135deg, ${roundColor}cc, ${roundColor}60)` }}
      >
        <span
          className="font-display text-3xl font-black text-white/20 absolute select-none"
          style={{ transform: 'rotate(-15deg) scale(1.8)', letterSpacing: '-0.05em' }}
        >
          R{pick.round}
        </span>
      </div>

      {/* Pick info */}
      <div className="flex-1 min-w-0 p-2.5 flex items-center gap-2 bg-[var(--bg-elevated)]">
        <div className="flex-1 min-w-0">
          <p className="text-sm font-semibold text-[var(--text-primary)] truncate">{pick.label}</p>
          <p className="text-[11px] text-[var(--text-muted)]">Draft Pick</p>
        </div>
        <div className="text-right shrink-0">
          {pick.trade_value > 0 && (
            <p className="font-stat text-xs text-[var(--text-primary)]">{Math.round(pick.trade_value * 10) / 10}</p>
          )}
          <p className="text-[9px] text-[var(--text-muted)]">value</p>
        </div>
      </div>
    </motion.div>
  );
}

/* ================================================================
   TRADE VALUE BAR
   Visual comparison bar showing relative value of each side.
   ================================================================ */

function TradeValueBar({ youValue, theyValue }: { youValue: number; theyValue: number }) {
  const total = youValue + theyValue;
  if (total === 0) return null;
  const youPct = Math.round((youValue / total) * 100);
  const theyPct = 100 - youPct;
  const diff = theyValue - youValue;
  const isFavorable = diff > 0;
  const diffRounded = Math.round(diff * 10) / 10;
  const diffLabel = diffRounded > 0 ? `+${diffRounded}` : String(diffRounded);

  return (
    <div className="space-y-1.5">
      <div className="flex items-center justify-between text-[10px]">
        <span className="text-red-400 font-semibold">You Send: {Math.round(youValue * 10) / 10}</span>
        <span
          className={`font-stat text-xs ${isFavorable ? 'text-green-400' : diff < 0 ? 'text-red-400' : 'text-[var(--text-muted)]'}`}
        >
          {diffLabel} value
        </span>
        <span className="text-green-400 font-semibold">They Send: {Math.round(theyValue * 10) / 10}</span>
      </div>
      <div className="flex h-2 w-full rounded-full overflow-hidden bg-[var(--bg-primary)]">
        <div
          className="h-full bg-red-500/60 transition-all duration-500"
          style={{ width: `${youPct}%` }}
        />
        <div
          className="h-full bg-green-500/60 transition-all duration-500"
          style={{ width: `${theyPct}%` }}
        />
      </div>
      <div className="flex justify-between text-[9px] text-[var(--text-muted)]">
        <span>{youPct}%</span>
        <span>{theyPct}%</span>
      </div>
    </div>
  );
}

/* ================================================================
   PACKAGE SIDE (column within a trade opportunity)
   ================================================================ */

function PackageColumn({
  side,
  label,
  labelColor,
  onPlayerClick,
  newItemIds,
}: {
  side: TradePackageSide;
  label: string;
  labelColor: string;
  onPlayerClick?: (id: number) => void;
  newItemIds?: Set<number>;
}) {
  return (
    <div className="flex-1 min-w-0 space-y-2">
      <p className={`text-[10px] font-bold uppercase tracking-[0.14em] ${labelColor}`}>
        {label}
      </p>
      <div className="space-y-2">
        {side.players.map((p) => (
          <PlayerMiniCard
            key={p.id}
            player={p}
            onNavigate={onPlayerClick}
            isNew={newItemIds?.has(p.id)}
          />
        ))}
        {side.picks.map((pk) => (
          <PickMiniCard
            key={`pick-${pk.id}`}
            pick={pk}
            isNew={newItemIds?.has(pk.id + 100000)}
          />
        ))}
      </div>
      <p className="text-[11px] text-[var(--text-muted)] font-mono pt-1 border-t border-[var(--border)]">
        Total Value: <span className="font-stat text-[var(--text-primary)]">{Math.round((side.total_value ?? 0) * 10) / 10}</span>
      </p>
    </div>
  );
}

/* ================================================================
   TRADE OPPORTUNITY CARD
   Full card for each interested team with trade packages.
   ================================================================ */

function OpportunityCard({
  opp,
  onSweeten,
  onAccept,
  onPlayerClick,
  isSweetening,
  isProposing,
  newItemIds,
}: {
  opp: TradeOpportunity;
  onSweeten: () => void;
  onAccept: () => void;
  onPlayerClick: (id: number) => void;
  isSweetening: boolean;
  isProposing: boolean;
  newItemIds?: Set<number>;
}) {
  const interest = interestConfig[opp.interest] ?? interestConfig.low;
  const mode = opp.team_mode ? modeConfig[opp.team_mode] : null;
  const ModeIcon = mode?.icon ?? Shield;
  const fairnessPct = Math.round(opp.fairness * 100);
  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35 }}
      className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden"
    >
      {/* Team color accent bar */}
      <div
        className="h-[3px] w-full"
        style={{
          background: `linear-gradient(90deg, ${opp.team.primary_color ?? '#3b82f6'}, ${opp.team.secondary_color ?? opp.team.primary_color ?? '#3b82f6'})`,
        }}
      />

      <div className="p-5 space-y-4">
        {/* -- Header: Team info + interest + mode -- */}
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-center gap-3">
            <TeamLogo
              abbreviation={opp.team.abbreviation}
              primaryColor={opp.team.primary_color}
              secondaryColor={opp.team.secondary_color}
              size="lg"
            />
            <div>
              <p className="text-base font-semibold text-[var(--text-primary)]">
                {opp.team.city} {opp.team.name}
              </p>
              <div className="flex items-center gap-2 mt-0.5">
                {mode && (
                  <span className={`flex items-center gap-1 text-[11px] font-medium ${mode.color}`}>
                    <ModeIcon className="h-3 w-3" />
                    {mode.label}
                  </span>
                )}
                {opp.reason && (
                  <span className="text-[11px] text-[var(--text-muted)]">
                    -- {opp.reason}
                  </span>
                )}
              </div>
            </div>
          </div>

          <Badge
            variant="outline"
            className={`text-[10px] font-bold uppercase tracking-wider shrink-0 ${interest.bg} ${interest.text} ${interest.border}`}
          >
            {interest.label}
          </Badge>
        </div>

        {/* -- Two-column trade layout -- */}
        <div className="grid grid-cols-2 gap-4">
          <PackageColumn
            side={opp.you_send}
            label="You Send"
            labelColor="text-red-400"
            onPlayerClick={onPlayerClick}
          />
          <PackageColumn
            side={opp.they_send}
            label="They Send"
            labelColor="text-green-400"
            onPlayerClick={onPlayerClick}
            newItemIds={newItemIds}
          />
        </div>

        {/* -- Trade value comparison bar -- */}
        <TradeValueBar youValue={opp.you_send.total_value} theyValue={opp.they_send.total_value} />

        {/* -- GM Analysis -- */}
        {opp.gm_note && (
          <div className="rounded-lg bg-[var(--bg-primary)] border border-[var(--border)] p-3">
            <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-[var(--accent-gold)] mb-1">
              Your GM&apos;s Take
            </p>
            <p className="text-xs leading-relaxed text-[var(--text-secondary)]">{opp.gm_note}</p>
          </div>
        )}

        {/* -- Footer: Fairness + actions -- */}
        <div className="flex items-center justify-between pt-2 border-t border-[var(--border)]">
          <div className="flex items-center gap-3">
            <span
              className={`font-stat text-sm ${
                fairnessPct >= 90 ? 'text-green-400'
                : fairnessPct >= 70 ? 'text-yellow-400'
                : 'text-red-400'
              }`}
            >
              {fairnessPct}%
            </span>
            <span className="text-[11px] text-[var(--text-muted)]">fairness</span>
          </div>

          <div className="flex items-center gap-2">
            <ActionButton
              variant="secondary"
              size="sm"
              icon={Handshake}
              onClick={onSweeten}
              disabled={isSweetening}
            >
              {isSweetening ? 'Asking...' : 'Sweeten Deal'}
            </ActionButton>
            <ActionButton
              variant="primary"
              size="sm"
              accentColor="#16a34a"
              onClick={onAccept}
              disabled={isProposing}
            >
              {isProposing ? 'Processing...' : 'Accept Trade'}
            </ActionButton>
          </div>
        </div>
      </div>
    </motion.div>
  );
}

/* ================================================================
   MAIN PAGE: TRADE CENTER
   Full-page replacement for FindTradeModal
   Route: /trade/find/:playerId
   ================================================================ */

export default function TradeCenter() {
  const { playerId: playerIdParam } = useParams<{ playerId: string }>();
  const navigate = useNavigate();
  const team = useAuthStore((s) => s.team);
  const myTeamId = (team as Record<string, unknown>)?.id as number | undefined;
  const myTeamColor = (team as Record<string, unknown>)?.primary_color as string | undefined;
  const playerId = playerIdParam ? Number(playerIdParam) : null;

  const [status, setStatus] = useState<'idle' | 'loading' | 'error' | 'success'>('idle');
  const [result, setResult] = useState<FindTradeResult | null>(null);
  const [mode, setMode] = useState<'shop' | 'acquire'>('shop');
  const [acquireResult, setAcquireResult] = useState<Record<string, unknown> | null>(null);
  const [errorMsg, setErrorMsg] = useState('');

  // Track newly sweetened items for highlight animation
  const [newItems, setNewItems] = useState<Map<number, Set<number>>>(new Map());

  const [proposing, setProposing] = useState<number | null>(null);
  const [sweetening, setSweetening] = useState<number | null>(null);

  const fetchOpportunities = useCallback(async (pid: number) => {
    setStatus('loading');
    setResult(null);
    setAcquireResult(null);
    setErrorMsg('');
    try {
      // First check if this is our player or another team's player
      const playerRes = await fetch(`/api/players/${pid}`, { credentials: 'include' });
      const playerData = await playerRes.json();
      const playerTeamId = playerData?.team?.id ?? playerData?.player?.team_id ?? playerData?.team_id;
      const isMyPlayer = playerTeamId && myTeamId && Number(playerTeamId) === Number(myTeamId);

      if (isMyPlayer) {
        // Shopping our player to other teams
        setMode('shop');
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
      } else {
        // Acquiring another team's player — show packages from our roster
        setMode('acquire');
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
        setAcquireResult(data as Record<string, unknown>);
        // Also set result.player for the banner
        if (data.player) {
          setResult({ player: data.player, opportunities: [], team_needs: [], leverage: { interested_teams: 0, multiplier: 1 } } as FindTradeResult);
        }
        setStatus('success');
      }
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : 'Network error');
      setStatus('error');
    }
  }, [myTeamId]);

  useEffect(() => {
    if (playerId) {
      fetchOpportunities(playerId);
    }
  }, [playerId, fetchOpportunities]);

  /* ---------- Sweeten: update inline, no full refetch ---------- */

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
        toast.success(data.message || 'Deal sweetened!');

        // Update the opportunity in-place with the sweetened asset
        const newItemSet = new Set<number>();

        setResult((prev) => {
          if (!prev) return prev;
          return {
            ...prev,
            opportunities: prev.opportunities.map((o) => {
              if (o.team.id !== opp.team.id) return o;
              const newOpp = { ...o, they_send: { ...o.they_send } };

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
                newItemSet.add(data.added_player.id);
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
                newItemSet.add(data.added_pick.id + 100000);
              }

              return newOpp;
            }),
          };
        });

        // Track new items for highlight animation, clear after 3s
        if (newItemSet.size > 0) {
          setNewItems((prev) => new Map(prev).set(opp.team.id, newItemSet));
          setTimeout(() => {
            setNewItems((prev) => {
              const next = new Map(prev);
              next.delete(opp.team.id);
              return next;
            });
          }, 3000);
        }
      } else {
        toast.info(data.reason || "They can't improve this offer.");
      }
    } catch {
      toast.error('Network error');
    } finally {
      setSweetening(null);
    }
  };

  /* ---------- Accept Trade ---------- */

  const handleAccept = async (opp: TradeOpportunity) => {
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
      if (tradeResult?.status === 'completed' || tradeResult?.status === 'accepted') {
        toast.success(tradeResult.message || 'Trade accepted! Players have been swapped.', { duration: 5000 });
        navigate('/my-team');
      } else if (tradeResult?.status === 'rejected') {
        toast.error(tradeResult.message || 'Trade rejected by the other team.');
      } else if (tradeResult?.status === 'counter') {
        toast.info(tradeResult.message || 'The other team wants to counter-offer.');
        navigate('/trades');
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

  /* ---------- Navigation ---------- */

  const goToPlayer = (id: number) => navigate(`/player/${id}`);

  /* ================================================================
     RENDER
     ================================================================ */

  return (
    <PageLayout>
      {/* Back button */}
      <button
        onClick={() => navigate(-1)}
        className="inline-flex items-center gap-1.5 text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors mb-2"
      >
        <ArrowLeft className="h-4 w-4" />
        Back
      </button>

      <PageHeader
        title="Trade Center"
        subtitle={
          status === 'loading'
            ? 'Working the phones...'
            : status === 'success' && result
              ? undefined
              : undefined
        }
        icon={ArrowLeftRight}
        actions={
          status === 'success' && playerId ? (
            <ActionButton
              variant="secondary"
              size="sm"
              icon={RefreshCw}
              onClick={() => fetchOpportunities(playerId)}
            >
              Refresh
            </ActionButton>
          ) : undefined
        }
      />

      {/* ---- Loading State ---- */}
      {status === 'loading' && (
        <div className="flex flex-col items-center justify-center py-24 gap-4">
          <Loader2 className="h-10 w-10 animate-spin text-[var(--accent-blue)]" />
          <div className="text-center">
            <p className="text-sm font-semibold text-[var(--text-primary)]">Scanning League</p>
            <p className="text-xs text-[var(--text-secondary)] mt-1">
              Checking all 32 front offices for interest...
            </p>
          </div>
        </div>
      )}

      {/* ---- Error State ---- */}
      {status === 'error' && (
        <div className="flex flex-col items-center py-16 gap-4">
          <div className="flex h-14 w-14 items-center justify-center rounded-full bg-red-500/10">
            <AlertCircle className="h-7 w-7 text-red-400" />
          </div>
          <p className="text-sm text-red-400">Failed to find trade opportunities.</p>
          {errorMsg && <p className="text-xs text-red-400/70 max-w-md text-center">{errorMsg}</p>}
          <ActionButton
            variant="secondary"
            size="sm"
            icon={RefreshCw}
            onClick={() => playerId && fetchOpportunities(playerId)}
          >
            Try Again
          </ActionButton>
        </div>
      )}

      {/* ---- Success: Two-Column Layout ---- */}
      {status === 'success' && result && (
        <div className="grid gap-6 lg:grid-cols-[380px_1fr] xl:grid-cols-[420px_1fr]">

          {/* ==== LEFT COLUMN: Player Being Shopped ==== */}
          <div className="space-y-5">
            {/* --- ESPN-Style Player Banner --- */}
            <motion.div
              initial={{ opacity: 0, x: -16 }}
              animate={{ opacity: 1, x: 0 }}
              transition={{ duration: 0.4 }}
              className="rounded-xl border border-[var(--border)] overflow-hidden"
            >
              {/* Banner: large photo left, info right, team color gradient bg */}
              {(() => {
                const bannerColor = mode === 'acquire'
                  ? ((acquireResult as Record<string, unknown>)?.team as Record<string, unknown>)?.primary_color as string | undefined ?? myTeamColor
                  : myTeamColor;
                const bannerColor2 = mode === 'acquire'
                  ? ((acquireResult as Record<string, unknown>)?.team as Record<string, unknown>)?.secondary_color as string | undefined ?? bannerColor
                  : (team as Record<string, unknown>)?.secondary_color as string | undefined ?? bannerColor;
                const light = isLightColor(bannerColor);
                const textPrimary = light ? 'text-gray-900' : 'text-white';
                const textSecondary = light ? 'text-gray-700' : 'text-white/80';
                const textMuted = light ? 'text-gray-500' : 'text-white/40';
                const badgeBg = light ? 'bg-black/10 border-black/20 text-gray-900' : 'bg-black/40 border-white/20 text-white';
                const placeholderColor = light ? 'text-black/15' : 'text-white/20';
                return (
                  <div
                    className="relative flex items-end"
                    style={{
                      background: bannerColor
                        ? `linear-gradient(135deg, ${bannerColor}, ${bannerColor2 ?? bannerColor})`
                        : 'linear-gradient(135deg, var(--bg-elevated), var(--bg-surface))',
                      minHeight: 200,
                    }}
                  >
                    {/* Large player photo — flush bottom-left, no padding */}
                    <div className="shrink-0 w-[160px] self-stretch flex items-end overflow-hidden">
                      {result.player.image_url ? (
                        <img
                          src={result.player.image_url}
                          alt={result.player.name}
                          className="w-full object-cover object-top"
                          style={{ minHeight: '100%' }}
                        />
                      ) : (
                        <div className="w-full h-full flex items-end justify-center pb-2">
                          <svg viewBox="0 0 80 80" className={placeholderColor} style={{ width: 100, height: 100 }}>
                            <circle cx="40" cy="28" r="14" fill="currentColor" />
                            <path d="M12 72c0-15.5 12.5-28 28-28s28 12.5 28 28" fill="currentColor" />
                          </svg>
                        </div>
                      )}
                    </div>

                    {/* Player info — right side of banner */}
                    <div className="flex-1 p-5 flex flex-col justify-end">
                      <Badge
                        variant="outline"
                        className={`w-fit mb-2 text-[10px] font-bold ${badgeBg}`}
                      >
                        {result.player.position}
                      </Badge>
                      <h2 className={`font-display text-2xl ${textPrimary} drop-shadow-lg leading-tight`}>
                        {result.player.name}
                      </h2>
                      <div className={`flex items-center gap-3 mt-1.5 text-sm ${textSecondary}`}>
                        <span>Age {result.player.age}</span>
                        <span className={textMuted}>|</span>
                        <span>Trade Value: <span className={`font-stat font-bold ${textPrimary}`}>{Math.round((result.player.trade_value ?? 0) * 10) / 10}</span></span>
                      </div>
                    </div>

                    {/* OVR block — top right corner, solid and readable */}
                    <div className="absolute top-4 right-4 flex flex-col items-center justify-center h-16 w-16 rounded-xl bg-black/85 border border-white/15 shadow-lg">
                      <span className="font-stat text-2xl font-bold text-white leading-none">
                        {result.player.overall_rating}
                      </span>
                      {ratingTier(result.player.overall_rating) && (
                        <span className="text-[8px] font-bold uppercase tracking-wider text-white/60 mt-0.5">
                          {ratingTier(result.player.overall_rating)}
                        </span>
                      )}
                    </div>
                  </div>
                );
              })()}

              {/* Stats bar below banner */}
              <div className="bg-[var(--bg-surface)] px-5 py-3 grid grid-cols-3 gap-3 border-t border-[var(--border)]">
                <div className="text-center">
                  <p className="text-[9px] font-bold uppercase tracking-wider text-[var(--text-muted)]">OVR</p>
                  <p className="font-stat text-lg text-[var(--text-primary)]">{result.player.overall_rating}</p>
                </div>
                <div className="text-center">
                  <p className="text-[9px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Age</p>
                  <p className="font-stat text-lg text-[var(--text-primary)]">{result.player.age}</p>
                </div>
                <div className="text-center">
                  <p className="text-[9px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Value</p>
                  <p className="font-stat text-lg text-[var(--accent-blue)]">{Math.round((result.player.trade_value ?? 0) * 10) / 10}</p>
                </div>
              </div>
            </motion.div>

            {/* --- Your Team Needs --- */}
            {result.team_needs && result.team_needs.length > 0 && (
              <motion.div
                initial={{ opacity: 0, x: -16 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ duration: 0.4, delay: 0.1 }}
                className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden"
              >
                <div className="flex items-center gap-2 px-5 py-3 border-b border-[var(--border)] bg-[var(--bg-elevated)]/40">
                  <Target className="h-3.5 w-3.5 text-emerald-400" />
                  <h3 className="text-[10px] font-bold uppercase tracking-[0.15em] text-emerald-400">
                    Your Team Needs
                  </h3>
                </div>
                <div className="p-4 space-y-2">
                  {result.team_needs.map((need, i) => (
                    <div
                      key={need.position}
                      className="flex items-center justify-between rounded-lg bg-[var(--bg-primary)] border border-[var(--border)] px-3 py-2"
                    >
                      <div className="flex items-center gap-2">
                        <span className="flex h-5 w-5 items-center justify-center rounded bg-emerald-500/15 text-[10px] font-bold text-emerald-400">
                          {i + 1}
                        </span>
                        <span className="text-sm font-semibold text-[var(--text-primary)]">{need.position}</span>
                      </div>
                      <div className="flex items-center gap-3 text-[11px] text-[var(--text-muted)]">
                        <span>
                          Roster: <span className="text-[var(--text-secondary)]">{need.roster_count}/{need.ideal_count}</span>
                        </span>
                        {need.best_overall > 0 && (
                          <span>
                            Best: <span className="text-[var(--text-primary)] font-semibold">{need.best_overall} OVR</span>
                          </span>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              </motion.div>
            )}

            {/* --- Quick Tips --- */}
            <motion.div
              initial={{ opacity: 0, x: -16 }}
              animate={{ opacity: 1, x: 0 }}
              transition={{ duration: 0.4, delay: 0.2 }}
              className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-4"
            >
              <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-[var(--text-muted)] mb-2">
                Trade Tips
              </p>
              <ul className="space-y-1.5 text-xs text-[var(--text-secondary)] leading-relaxed">
                <li className="flex gap-2">
                  <span className="text-[var(--accent-blue)] shrink-0">--</span>
                  Look for deals that fill a roster need, not just equal value.
                </li>
                <li className="flex gap-2">
                  <span className="text-[var(--accent-blue)] shrink-0">--</span>
                  &quot;Sweeten Deal&quot; asks the team to add more to their side.
                </li>
                <li className="flex gap-2">
                  <span className="text-[var(--accent-blue)] shrink-0">--</span>
                  Rebuilding teams value draft picks higher. Contenders want proven talent.
                </li>
              </ul>
            </motion.div>
          </div>

          {/* ==== RIGHT COLUMN: Trade Partners (shop) or Packages (acquire) ==== */}
          <div className="space-y-4">
            {mode === 'shop' ? (
              <>
                <SectionHeader
                  title={`${result.opportunities.length} Trade Partner${result.opportunities.length !== 1 ? 's' : ''}`}
                  accentColor="var(--accent-blue)"
                />

                {result.opportunities.length === 0 ? (
                  <EmptyBlock
                    icon={ArrowLeftRight}
                    title="No trade partners found"
                    description={`No teams are currently interested in trading for ${result.player.name}. Try a different player or check back later.`}
                    action={{ label: 'Back to My Team', onClick: () => navigate('/my-team') }}
                  />
                ) : (
                  <AnimatePresence mode="popLayout">
                    <div className="space-y-4">
                      {result.opportunities.map((opp, i) => (
                        <motion.div
                          key={opp.team.id}
                          initial={{ opacity: 0, y: 16 }}
                          animate={{ opacity: 1, y: 0 }}
                          transition={{ duration: 0.35, delay: i * 0.06 }}
                        >
                          <OpportunityCard
                            opp={opp}
                            onSweeten={() => handleSweeten(opp)}
                            onAccept={() => handleAccept(opp)}
                            onPlayerClick={goToPlayer}
                            isSweetening={sweetening === opp.team.id}
                            isProposing={proposing === opp.team.id}
                            newItemIds={newItems.get(opp.team.id)}
                          />
                        </motion.div>
                      ))}
                    </div>
                  </AnimatePresence>
                )}
              </>
            ) : acquireResult ? (
              <>
                {/* Acquire mode: show packages from YOUR roster */}
                {(() => {
                  // eslint-disable-next-line @typescript-eslint/no-explicit-any
                  const acq = acquireResult as Record<string, any>;
                  const packages = (acq.packages ?? []) as Array<Record<string, any>>;
                  const oppTeam = acq.team as Record<string, any> | undefined;
                  const userColor = (acq.user_team_color ?? myTeamColor) as string | undefined;
                  const oppColor = (acq.opponent_team_color ?? oppTeam?.primary_color) as string | undefined;

                  return (
                    <>
                      <SectionHeader
                        title={acq.available ? `${packages.length} Package${packages.length !== 1 ? 's' : ''} Available` : 'Not Available'}
                        accentColor={acq.available ? 'var(--accent-blue)' : 'var(--accent-red)'}
                      />

                      {/* Opponent team info */}
                      {oppTeam && (
                        <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] p-4">
                          <div className="flex items-center gap-3">
                            <TeamLogo
                              abbreviation={oppTeam.abbreviation}
                              primaryColor={oppTeam.primary_color}
                              secondaryColor={oppTeam.secondary_color}
                              size="lg"
                            />
                            <div>
                              <p className="text-base font-semibold">{oppTeam.city} {oppTeam.name}</p>
                              <p className="text-xs text-[var(--text-muted)]">
                                {oppTeam.gm_personality && <span className="capitalize">{oppTeam.gm_personality} GM</span>}
                                {oppTeam.mode && <span> · {oppTeam.mode}</span>}
                              </p>
                            </div>
                            {acq.asking_price && (
                              <div className="ml-auto text-right">
                                <p className="text-[9px] font-bold uppercase tracking-wider text-[var(--text-muted)]">Asking Price</p>
                                <p className="font-stat text-lg text-[var(--text-primary)]">{Math.round(acq.asking_price * 10) / 10}</p>
                              </div>
                            )}
                          </div>
                          {acq.reason && (
                            <p className="text-xs text-[var(--text-secondary)] mt-2 italic">&ldquo;{acq.reason}&rdquo;</p>
                          )}
                        </div>
                      )}

                      {!acq.available ? (
                        <EmptyBlock
                          icon={Shield}
                          title="Player is untouchable"
                          description={acq.reason || 'This team is not willing to trade this player.'}
                          action={{ label: 'Go Back', onClick: () => navigate(-1) }}
                        />
                      ) : packages.length === 0 ? (
                        <EmptyBlock
                          icon={ArrowLeftRight}
                          title="No matching packages"
                          description="They'd consider trading this player, but we don't have enough matching assets to put together a deal."
                          action={{ label: 'Go Back', onClick: () => navigate(-1) }}
                        />
                      ) : (
                        <div className="space-y-4">
                          {packages.map((pkg, idx) => (
                            <motion.div
                              key={idx}
                              initial={{ opacity: 0, y: 16 }}
                              animate={{ opacity: 1, y: 0 }}
                              transition={{ duration: 0.35, delay: idx * 0.06 }}
                              className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden"
                            >
                              <div className="p-5 space-y-4">
                                <div className="flex items-center justify-between">
                                  <p className="text-sm font-bold text-[var(--text-primary)]">Option {idx + 1}</p>
                                  {pkg.fairness !== undefined && (
                                    <span className="font-stat text-sm text-[var(--text-muted)]">
                                      {Math.round((pkg.fairness as number) * 100)}% fair
                                    </span>
                                  )}
                                </div>

                                {/* Two-column: You Send | You Receive */}
                                <div className="grid grid-cols-2 gap-4">
                                  <div>
                                    <p className="text-[10px] font-bold uppercase tracking-widest text-red-400 mb-2">You Send</p>
                                    <div className="space-y-1.5">
                                      {pkg.players.map((p: Record<string, unknown>) => (
                                        <PlayerMiniCard key={p.id as number} player={{ ...(p as MiniCardPlayer), team_color: userColor }} onNavigate={goToPlayer} />
                                      ))}
                                      {pkg.picks.map((pk: Record<string, unknown>) => (
                                        <PickMiniCard key={`pick-${pk.id}`} pick={pk as { id: number; label: string; round: number; year: number; trade_value: number }} />
                                      ))}
                                    </div>
                                  </div>
                                  <div>
                                    <p className="text-[10px] font-bold uppercase tracking-widest text-emerald-400 mb-2">You Receive</p>
                                    <div className="space-y-1.5">
                                      {/* The target player */}
                                      <PlayerMiniCard player={{
                                        id: result.player.id,
                                        name: result.player.name,
                                        position: result.player.position,
                                        overall_rating: result.player.overall_rating,
                                        age: result.player.age,
                                        image_url: (result.player as Record<string, unknown>).image_url as string | undefined,
                                        dev_trait: (result.player as Record<string, unknown>).dev_trait as string | undefined,
                                        salary: (result.player as Record<string, unknown>).salary as number | undefined,
                                        contract_year: (result.player as Record<string, unknown>).contract_years as number | undefined,
                                        fills_need: (result.player as Record<string, unknown>).fills_need as boolean | undefined,
                                        team_color: oppColor,
                                      }} onNavigate={goToPlayer} />
                                      {/* Extra sweeteners from their side */}
                                      {(pkg.they_also_send_players ?? []).map((p: Record<string, unknown>) => (
                                        <PlayerMiniCard key={p.id as number} player={{ ...(p as MiniCardPlayer), team_color: oppColor }} onNavigate={goToPlayer} />
                                      ))}
                                      {(pkg.they_also_send_picks ?? []).map((pk: Record<string, unknown>) => (
                                        <PickMiniCard key={`tpk-${pk.id}`} pick={pk as { id: number; label: string; round: number; year: number; trade_value: number }} />
                                      ))}
                                    </div>
                                  </div>
                                </div>

                                {/* Value comparison bar */}
                                <TradeValueBar
                                  youValue={pkg.you_send_value ?? pkg.total_value}
                                  theyValue={pkg.you_get_value ?? (acq.asking_price ?? 0)}
                                />

                                {/* GM Note */}
                                {pkg.gm_note && (
                                  <div className="rounded-lg bg-[var(--bg-elevated)] border border-[var(--border)] p-3">
                                    <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-[var(--accent-gold)] mb-1">Your GM&apos;s Take</p>
                                    <p className="text-xs leading-relaxed text-[var(--text-secondary)]">{pkg.gm_note as string}</p>
                                  </div>
                                )}

                                <div className="flex items-center justify-end pt-2 border-t border-[var(--border)]">
                                  <ActionButton
                                    onClick={async () => {
                                      setProposing(idx);
                                      try {
                                        const reqPlayerIds = [result.player.id, ...(pkg.they_also_send_players ?? []).map((p: Record<string, unknown>) => p.id as number)];
                                        const reqPickIds = (pkg.they_also_send_picks ?? []).map((p: Record<string, unknown>) => p.id as number);
                                        const res = await fetch('/api/trades', {
                                          method: 'POST',
                                          credentials: 'include',
                                          headers: { 'Content-Type': 'application/json' },
                                          body: JSON.stringify({
                                            target_team_id: oppTeam?.id,
                                            offering_player_ids: pkg.players.map((p: Record<string, unknown>) => p.id),
                                            offering_pick_ids: pkg.picks.map((p: Record<string, unknown>) => p.id),
                                            requesting_player_ids: reqPlayerIds,
                                            requesting_pick_ids: reqPickIds,
                                            pre_agreed: true,
                                          }),
                                        });
                                        const data = await res.json();
                                        if (!res.ok) { toast.error(data?.error || 'Trade failed'); return; }
                                        const tradeResult = data?.trade ?? data?.data?.trade;
                                        toast.success(tradeResult?.message || 'Trade complete!', { duration: 5000 });
                                        navigate('/my-team');
                                      } catch { toast.error('Network error'); } finally { setProposing(null); }
                                    }}
                                    disabled={proposing !== null}
                                    accentColor="#16a34a"
                                  >
                                    {proposing === idx ? 'Processing...' : 'Make This Trade'}
                                  </ActionButton>
                                </div>
                              </div>
                            </motion.div>
                          ))}
                        </div>
                      )}
                    </>
                  );
                })()}
              </>
            ) : null}
          </div>
        </div>
      )}
    </PageLayout>
  );
}
