import { useState } from 'react';
import teamLogos from '@/assets/logos';
import { PlayerPhoto } from '@/components/PlayerPhoto';
import { useAuthStore } from '@/stores/authStore';
import { useRoster, useDepthChart, useAutoSetDepthChart, useUpdateDepthChart, useMoveToActive, useMoveToPracticeSquad, useMoveToIR, useReleasePlayer, useTeam } from '@/hooks/useApi';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
// FindTradeModal replaced by full-page TradeCenter at /trade/find/:playerId
import {
  SportsTabs,
  DataTable,
  ActionButton,
  Section,
  RatingBadge,
  EmptyBlock,
} from '@/components/ui/sports-ui';
import { ChevronDown, ChevronRight, UserPlus, ShieldAlert, ArrowUp, UserMinus, Wand2, Users, ClipboardList } from 'lucide-react';
import { toast } from 'sonner';
import type { Player } from '@/api/client';

const positionGroups = {
  Offense: ['QB1', 'RB1', 'WR1', 'WR2', 'SLOT', 'TE1', 'LT', 'LG', 'C', 'RG', 'RT'],
  Defense: ['LDE', 'RDE', 'DT1', 'DT2', 'MLB', 'WLB', 'SLB', 'CB1', 'CB2', 'FS', 'SS'],
  'Special Teams': ['K', 'P'],
};

function formatInjuryType(type: string): string {
  const map: Record<string, string> = {
    shoulder: 'Shoulder', knee: 'Knee', ankle: 'Ankle', hand: 'Hand',
    ribs: 'Ribs', hamstring: 'Hamstring', back: 'Back', elbow: 'Elbow',
    concussion: 'Concussion',
  };
  return map[type] ?? type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function formatInjurySeverity(severity: string): string {
  const map: Record<string, string> = {
    day_to_day: 'Day-to-Day', short_term: 'Short-Term',
    long_term: 'Long-Term', season_ending: 'Season-Ending',
  };
  return map[severity] ?? severity.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function positionColor(pos: string): string {
  const offense = ['QB', 'RB', 'WR', 'TE', 'OT', 'OG', 'C', 'FB'];
  const defense = ['DE', 'DT', 'LB', 'CB', 'S', 'MLB', 'OLB', 'SS', 'FS'];
  if (offense.includes(pos)) return 'bg-blue-500/15 text-blue-400 border-blue-500/25';
  if (defense.includes(pos)) return 'bg-red-500/15 text-red-400 border-red-500/25';
  return 'bg-yellow-500/15 text-yellow-400 border-yellow-500/25';
}

export default function MyTeam() {
  const team = useAuthStore((s) => s.team);
  const { data: teamDetail } = useTeam(team?.id);
  const { data: roster, isLoading } = useRoster(team?.id);
  const { data: depthChart } = useDepthChart(team?.id);
  const autoSetMut = useAutoSetDepthChart(team?.id ?? 0);
  const updateDepthMut = useUpdateDepthChart(team?.id ?? 0);
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const defaultTab = searchParams.get('tab') === 'depth' ? 'depth' : 'roster';
  const [activeTab, setActiveTab] = useState(defaultTab);
  const [posFilter, setPosFilter] = useState('all');
  // Trade navigation — go to full-page Trade Center
  const [psExpanded, setPsExpanded] = useState(false);
  const [irExpanded, setIrExpanded] = useState(true);
  const [logoError, setLogoError] = useState(false);
  const logoPath = team?.abbreviation ? teamLogos[team.abbreviation] ?? null : null;

  // Depth chart swap state: click a player to select, click another at a compatible position to swap
  const [swapSelection, setSwapSelection] = useState<{ pos: string; basePos: string; slot: number; playerId: number; name: string } | null>(null);

  // Map depth chart group labels to their base player position for cross-group swaps
  const groupToBasePos: Record<string, string> = {
    QB1: 'QB', RB1: 'RB',
    WR1: 'WR', WR2: 'WR', SLOT: 'WR',
    TE1: 'TE',
    LT: 'OT', RT: 'OT',
    LG: 'OG', RG: 'OG',
    C: 'C',
    LDE: 'DE', RDE: 'DE',
    DT1: 'DT', DT2: 'DT',
    MLB: 'LB', WLB: 'LB', SLB: 'LB',
    CB1: 'CB', CB2: 'CB',
    FS: 'S', SS: 'S',
    K: 'K', P: 'P',
  };

  const moveToActive = useMoveToActive();
  const moveToPracticeSquad = useMoveToPracticeSquad();
  const moveToIR = useMoveToIR();
  const releasePlayer = useReleasePlayer();

  if (isLoading) return <p className="text-[var(--text-secondary)]">Loading roster...</p>;

  const players = roster?.active ?? [];
  const practiceSquadPlayers = roster?.practice_squad ?? [];
  const injuredReservePlayers = roster?.injured_reserve ?? [];
  const filtered = posFilter === 'all' ? players : players.filter((p) => p.position === posFilter);

  const positions = [...new Set(players.map((p) => p.position))].sort();

  const handleMoveToActive = (player: Player) => {
    moveToActive.mutate(player.id, {
      onSuccess: (data) => toast.success(data.message),
      onError: (err) => toast.error(err instanceof Error ? err.message : 'Failed to move player'),
    });
  };

  const handleMoveToPracticeSquad = (player: Player) => {
    moveToPracticeSquad.mutate(player.id, {
      onSuccess: (data) => toast.success(data.message),
      onError: (err) => toast.error(err instanceof Error ? err.message : 'Failed to move player'),
    });
  };

  const handleMoveToIR = (player: Player) => {
    moveToIR.mutate(player.id, {
      onSuccess: (data) => toast.success(data.message),
      onError: (err) => toast.error(err instanceof Error ? err.message : 'Failed to move player'),
    });
  };

  const handleRelease = (player: Player) => {
    if (!confirm(`Release ${player.first_name} ${player.last_name}? They will become a free agent.`)) return;
    releasePlayer.mutate(player.id, {
      onSuccess: (data) => toast.success(data.message || `${player.first_name} ${player.last_name} released to free agency`),
      onError: (err) => toast.error(err instanceof Error ? err.message : 'Failed to release player'),
    });
  };

  const teamColor = team?.primary_color ?? '#2188FF';
  const teamColor2 = (team as Record<string, unknown>)?.secondary_color as string ?? teamColor;

  /* ── Column defs for Active Roster DataTable ── */
  const activeColumns = [
    {
      key: 'jersey_number',
      label: '#',
      width: 'w-12',
      stat: true,
      render: (p: Player) => (
        <span className="text-xs text-[var(--text-muted)]">{p.jersey_number}</span>
      ),
    },
    {
      key: 'name',
      label: 'Player',
      render: (p: Player) => (
        <div className="flex items-center gap-3">
          <PlayerPhoto imageUrl={(p as any).image_url} firstName={p.first_name} lastName={p.last_name} size={36} />
          <span className="text-[13px] font-semibold tracking-tight text-[var(--text-primary)]">
            {p.first_name} <span className="font-bold">{p.last_name}</span>
          </span>
        </div>
      ),
    },
    {
      key: 'position',
      label: 'Pos',
      render: (p: Player) => (
        <span className={`inline-flex items-center justify-center rounded px-2 py-0.5 text-[10px] font-bold uppercase border ${positionColor(p.position)}`}>
          {p.position}
        </span>
      ),
    },
    {
      key: 'age',
      label: 'Age',
      render: (p: Player) => (
        <span className="text-sm text-[var(--text-secondary)]">{p.age}</span>
      ),
    },
    {
      key: 'overall_rating',
      label: 'OVR',
      render: (p: Player) => <RatingBadge rating={p.overall_rating} size="sm" />,
    },
    {
      key: 'potential',
      label: 'Potential',
      render: (p: Player) => <DevelopmentBadge potential={p.potential} />,
    },
    {
      key: 'status',
      label: 'Status',
      render: (p: Player) =>
        p.injury ? (
          <span className="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-semibold bg-red-500/10 text-red-400 border border-red-500/20">
            {formatInjuryType(p.injury.type)} — {formatInjurySeverity(p.injury.severity)} ({p.injury.weeks_remaining}w)
          </span>
        ) : (
          <span className="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-semibold bg-green-500/10 text-green-400 border border-green-500/20">
            Active
          </span>
        ),
    },
    {
      key: 'actions',
      label: 'Actions',
      width: 'w-40',
      render: (p: Player) => (
        <div className="flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
          <ActionButton
            size="sm"
            variant="ghost"
            onClick={() => {
              navigate(`/trade/find/${p.id}`);
            }}
          >
            Trade
          </ActionButton>
          <ActionButton
            size="sm"
            variant="ghost"
            className="text-yellow-400 hover:text-yellow-300"
            onClick={() => handleMoveToPracticeSquad(p)}
          >
            PS
          </ActionButton>
          {p.injury && (
            <ActionButton
              size="sm"
              variant="ghost"
              className="text-orange-400 hover:text-orange-300"
              onClick={() => handleMoveToIR(p)}
            >
              IR
            </ActionButton>
          )}
          <ActionButton
            size="sm"
            variant="ghost"
            className="text-red-400 hover:text-red-300"
            onClick={() => handleRelease(p)}
          >
            Cut
          </ActionButton>
        </div>
      ),
    },
  ];

  /* ── Column defs for Practice Squad DataTable ── */
  const psColumns = [
    {
      key: 'jersey_number',
      label: '#',
      width: 'w-12',
      stat: true,
      render: (p: Player) => (
        <span className="text-xs text-[var(--text-muted)]">{p.jersey_number}</span>
      ),
    },
    {
      key: 'name',
      label: 'Player',
      render: (p: Player) => (
        <div className="flex items-center gap-3">
          <PlayerPhoto imageUrl={(p as any).image_url} firstName={p.first_name} lastName={p.last_name} size={36} />
          <span className="text-[13px] font-semibold tracking-tight text-[var(--text-primary)]">
            {p.first_name} <span className="font-bold">{p.last_name}</span>
          </span>
        </div>
      ),
    },
    {
      key: 'position',
      label: 'Pos',
      render: (p: Player) => (
        <span className={`inline-flex items-center justify-center rounded px-2 py-0.5 text-[10px] font-bold uppercase border ${positionColor(p.position)}`}>
          {p.position}
        </span>
      ),
    },
    {
      key: 'age',
      label: 'Age',
      render: (p: Player) => (
        <span className="text-sm text-[var(--text-secondary)]">{p.age}</span>
      ),
    },
    {
      key: 'overall_rating',
      label: 'OVR',
      render: (p: Player) => <RatingBadge rating={p.overall_rating} size="sm" />,
    },
    {
      key: 'potential',
      label: 'Potential',
      render: (p: Player) => <DevelopmentBadge potential={p.potential} />,
    },
    {
      key: 'status',
      label: 'Status',
      render: () => (
        <span className="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-semibold bg-yellow-500/10 text-yellow-400 border border-yellow-500/20">
          Practice Squad
        </span>
      ),
    },
    {
      key: 'actions',
      label: 'Actions',
      width: 'w-36',
      render: (p: Player) => (
        <div className="flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
          <ActionButton
            size="sm"
            variant="ghost"
            className="text-green-400 hover:text-green-300"
            icon={ArrowUp}
            onClick={() => handleMoveToActive(p)}
          >
            Promote
          </ActionButton>
          <ActionButton
            size="sm"
            variant="ghost"
            className="text-red-400 hover:text-red-300"
            icon={UserMinus}
            onClick={() => handleRelease(p)}
          >
            Release
          </ActionButton>
        </div>
      ),
    },
  ];

  /* ── Column defs for Injured Reserve DataTable ── */
  const irColumns = [
    {
      key: 'jersey_number',
      label: '#',
      width: 'w-12',
      stat: true,
      render: (p: Player) => (
        <span className="text-xs text-[var(--text-muted)]">{p.jersey_number}</span>
      ),
    },
    {
      key: 'name',
      label: 'Player',
      render: (p: Player) => (
        <div className="flex items-center gap-3">
          <PlayerPhoto imageUrl={(p as any).image_url} firstName={p.first_name} lastName={p.last_name} size={36} />
          <span className="text-[13px] font-semibold tracking-tight text-[var(--text-primary)]">
            {p.first_name} <span className="font-bold">{p.last_name}</span>
          </span>
        </div>
      ),
    },
    {
      key: 'position',
      label: 'Pos',
      render: (p: Player) => (
        <span className={`inline-flex items-center justify-center rounded px-2 py-0.5 text-[10px] font-bold uppercase border ${positionColor(p.position)}`}>
          {p.position}
        </span>
      ),
    },
    {
      key: 'age',
      label: 'Age',
      render: (p: Player) => (
        <span className="text-sm text-[var(--text-secondary)]">{p.age}</span>
      ),
    },
    {
      key: 'overall_rating',
      label: 'OVR',
      render: (p: Player) => <RatingBadge rating={p.overall_rating} size="sm" />,
    },
    {
      key: 'injury',
      label: 'Injury',
      render: (p: Player) =>
        p.injury ? (
          <div className="space-y-0.5">
            <span className="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-semibold bg-red-500/10 text-red-400 border border-red-500/20">
              {formatInjuryType(p.injury.type)}
            </span>
            <p className="text-[10px] text-[var(--text-muted)]">{formatInjurySeverity(p.injury.severity)}</p>
          </div>
        ) : (
          <span className="text-xs text-[var(--text-muted)]">Healthy</span>
        ),
    },
    {
      key: 'recovery',
      label: 'Recovery',
      render: (p: Player) =>
        p.injury ? (
          p.injury.weeks_remaining > 0 ? (
            <span className="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-semibold bg-red-500/10 text-red-400 border border-red-500/20">
              {p.injury.weeks_remaining}w remaining
            </span>
          ) : (
            <span className="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-semibold bg-green-500/10 text-green-400 border border-green-500/20">
              Ready
            </span>
          )
        ) : (
          <span className="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-semibold bg-green-500/10 text-green-400 border border-green-500/20">
            Ready
          </span>
        ),
    },
    {
      key: 'actions',
      label: 'Actions',
      width: 'w-28',
      render: (p: Player) => {
        const canActivate = !p.injury || p.injury.weeks_remaining === 0;
        return (
          <div className="flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
            <ActionButton
              size="sm"
              variant="ghost"
              className={canActivate ? 'text-green-400 hover:text-green-300' : 'text-[var(--text-muted)] cursor-not-allowed'}
              disabled={!canActivate}
              icon={ArrowUp}
              onClick={() => {
                if (canActivate) handleMoveToActive(p);
              }}
            >
              Activate
            </ActionButton>
            <ActionButton
              size="sm"
              variant="ghost"
              className="text-red-400 hover:text-red-300"
              onClick={() => handleRelease(p)}
            >
              Release
            </ActionButton>
          </div>
        );
      },
    },
  ];

  /* ── Depth chart swap handler ── */
  function handleDepthChartClick(pos: string, slot: number, entry: { player_id: number; name: string; overall_rating: number }) {
    const basePos = groupToBasePos[pos] ?? pos;

    if (!swapSelection) {
      setSwapSelection({ pos, basePos, slot: slot + 1, playerId: entry.player_id, name: entry.name });
      return;
    }

    const clickedBasePos = basePos;

    if (swapSelection.basePos !== clickedBasePos) {
      // Incompatible positions — start new selection
      setSwapSelection({ pos, basePos, slot: slot + 1, playerId: entry.player_id, name: entry.name });
      toast.info(`Can't swap ${swapSelection.basePos} with ${clickedBasePos}. Select compatible positions.`);
      return;
    }

    if (swapSelection.playerId === entry.player_id) {
      setSwapSelection(null);
      return;
    }

    // Execute swap — each player goes to the other's position group + slot
    const changes = [
      { position_group: swapSelection.pos, slot: swapSelection.slot, player_id: entry.player_id },
      { position_group: pos, slot: slot + 1, player_id: swapSelection.playerId },
    ];

    updateDepthMut.mutate(changes, {
      onSuccess: () => {
        toast.success(`Swapped ${swapSelection.name} and ${entry.name}`);
        setSwapSelection(null);
      },
      onError: (err) => {
        toast.error(err.message);
        setSwapSelection(null);
      },
    });
  }

  /* ── Column defs for Depth Chart DataTable ── */
  const depthColumns = [
    {
      key: 'pos',
      label: 'Pos',
      width: 'w-16',
      render: (row: { pos: string; entries: Array<{ player_id: number; name: string; overall_rating: number }> }) => (
        <span className="font-display text-xs uppercase text-[var(--text-muted)]">{row.pos}</span>
      ),
    },
    ...[0, 1].map((slot) => ({
      key: `slot_${slot}`,
      label: slot === 0 ? 'Starter' : 'Backup',
      render: (row: { pos: string; entries: Array<{ player_id: number; name: string; overall_rating: number }> }) => {
        const e = row.entries[slot];
        if (!e) return <span className="text-[var(--text-muted)]">--</span>;

        const rowBasePos = groupToBasePos[row.pos] ?? row.pos;
        const isSelected = swapSelection?.playerId === e.player_id;
        const isSwappable = swapSelection !== null && swapSelection.basePos === rowBasePos && swapSelection.playerId !== e.player_id;

        return (
          <span
            className={`cursor-pointer transition-all rounded px-1.5 py-0.5 inline-flex items-center gap-1.5 ${
              isSelected
                ? 'bg-[var(--accent-blue)]/20 ring-1 ring-[var(--accent-blue)] text-[var(--accent-blue)]'
                : isSwappable
                  ? 'bg-[var(--accent-blue)]/5 hover:bg-[var(--accent-blue)]/15 border border-dashed border-[var(--accent-blue)]/30'
                  : 'hover:text-[var(--accent-blue)]'
            }`}
            onClick={(ev) => {
              ev.stopPropagation();
              handleDepthChartClick(row.pos, slot, e);
            }}
            title={isSelected ? 'Selected — click another player at this position to swap' : isSwappable ? `Click to swap with ${swapSelection?.name}` : 'Click to select for swap'}
          >
            <span className="text-sm font-medium">{e.name}</span>{' '}
            <RatingBadge rating={e.overall_rating} size="sm" />
          </span>
        );
      },
    })),
  ];

  const sortedFiltered = [...filtered].sort((a, b) => b.overall_rating - a.overall_rating);
  const sortedPS = [...practiceSquadPlayers].sort((a, b) => b.overall_rating - a.overall_rating);
  const sortedIR = [...injuredReservePlayers].sort((a, b) => b.overall_rating - a.overall_rating);

  const rankings = (teamDetail as any)?.rankings;
  const ordinal = (n: number) => {
    const s = ['th', 'st', 'nd', 'rd'];
    const v = n % 100;
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
  };

  return (
    <div className="space-y-6 -mt-6">
      {/* ESPN-style Team Header — same as TeamRoster page */}
      <div
        className="-mx-4 sm:-mx-6"
        style={{ width: '100vw', marginLeft: 'calc(-50vw + 50%)' }}
      >
        <div className="h-1" style={{ backgroundColor: teamColor }} />

        <div className="bg-[var(--bg-surface)]">
          <div className="flex items-stretch" style={{ minHeight: '180px' }}>
            {/* Abbreviation area with angled strips */}
            <div className="relative shrink-0 w-56 sm:w-72 hidden sm:block overflow-hidden">
              <div
                className="absolute inset-0"
                style={{
                  background: `linear-gradient(135deg, ${teamColor} 0%, ${teamColor2} 100%)`,
                }}
              />
              <div className="absolute inset-0 flex items-center justify-center overflow-hidden" style={{ zIndex: 5 }}>
                {logoPath && !logoError ? (
                  <img
                    key={logoPath}
                    src={logoPath}
                    alt=""
                    className="w-[220px] sm:w-[280px] opacity-30 select-none pointer-events-none"
                    style={{ transform: 'rotate(-6deg) translateY(5px)', filter: 'brightness(2)' }}
                    onError={() => setLogoError(true)}
                  />
                ) : (
                  <span
                    className="text-[120px] sm:text-[160px] font-black text-white/15 tracking-wider leading-none select-none"
                    style={{ transform: 'rotate(-12deg) translateY(10px)' }}
                  >
                    {team?.abbreviation}
                  </span>
                )}
              </div>
              <div className="absolute -top-2 -bottom-2 left-[15%] w-[6px]" style={{ background: 'rgba(255,255,255,0.15)', transform: 'skewX(-8deg)', zIndex: 8 }} />
              <div className="absolute -top-2 -bottom-2 right-[20px] w-[10px]" style={{ background: `${teamColor}66`, transform: 'skewX(-8deg)', boxShadow: '-2px 0 4px rgba(0,0,0,0.1)', zIndex: 10 }} />
              <div className="absolute -top-2 -bottom-2 right-[-8px] w-[35px]" style={{ background: 'var(--bg-surface)', transform: 'skewX(-8deg)', boxShadow: '-4px 0 10px rgba(0,0,0,0.15)', zIndex: 12 }} />
            </div>

            {/* Name + OVR */}
            <div className="flex-1 min-w-0 px-8 py-8 flex flex-col justify-center">
              <div className="flex items-start gap-6">
                <div className="flex-1 min-w-0">
                  <h1 className="leading-none">
                    <span className="block text-xl sm:text-2xl font-normal text-[var(--text-secondary)]">{team?.city}</span>
                    <span className="block text-3xl sm:text-5xl font-black text-[var(--text-primary)] uppercase tracking-tight">{team?.name}</span>
                  </h1>
                  <p className="text-sm text-[var(--text-secondary)] mt-3">
                    {team?.conference} {team?.division} &middot; {team?.wins}-{team?.losses}{team?.ties ? `-${team.ties}` : ''}
                  </p>
                </div>
                <div className="shrink-0 flex items-center gap-3">
                  <div className="flex flex-col items-center bg-[var(--bg-elevated)] border border-[var(--border)] rounded-lg px-5 py-3">
                    <span className="text-4xl font-bold text-[var(--text-primary)] leading-none">{(teamDetail as Record<string, unknown>)?.overall_rating as number ?? team?.overall_rating ?? '--'}</span>
                    <span className="text-[9px] font-bold uppercase tracking-widest text-[var(--text-muted)] mt-1">OVR</span>
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <div className="flex items-center gap-2 bg-[var(--bg-elevated)] border border-[var(--border)] rounded-md px-3 py-1.5">
                      <span className="font-stat text-lg font-bold text-[var(--text-primary)] leading-none">{(teamDetail as Record<string, unknown>)?.offense_rating as number ?? '--'}</span>
                      <span className="text-[9px] font-bold uppercase tracking-widest text-[var(--text-muted)]">OFF</span>
                    </div>
                    <div className="flex items-center gap-2 bg-[var(--bg-elevated)] border border-[var(--border)] rounded-md px-3 py-1.5">
                      <span className="font-stat text-lg font-bold text-[var(--text-primary)] leading-none">{(teamDetail as Record<string, unknown>)?.defense_rating as number ?? '--'}</span>
                      <span className="text-[9px] font-bold uppercase tracking-widest text-[var(--text-muted)]">DEF</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            {/* Team details column */}
            <div className="hidden md:flex flex-col justify-center gap-3 px-8 py-6 border-l border-[var(--border)] min-w-[200px]">
              <DetailLine label="ROSTER" value={`${players.length} active`} />
              <DetailLine label="MORALE" value={`${team?.morale ?? '--'}`} />
              <DetailLine label="P. SQUAD" value={`${practiceSquadPlayers.length}`} />
              <DetailLine label="IR" value={`${injuredReservePlayers.length}`} />
            </div>

            {/* Rankings */}
            {rankings && rankings.pass_rank && (
              <div className="hidden lg:flex flex-col shrink-0 min-w-[320px]">
                <div className="px-4 py-1.5 text-center text-xs font-bold uppercase tracking-wider text-white" style={{ backgroundColor: teamColor }}>
                  Team Rankings
                </div>
                <div className="flex-1 flex items-center justify-center gap-6 px-6 py-4">
                  <div className="text-center">
                    <p className="text-2xl font-bold text-[var(--text-primary)] uppercase">{ordinal(rankings.pass_rank)}</p>
                    <p className="text-[11px] text-[var(--text-muted)]">PasY/G ({rankings.pass_ypg})</p>
                  </div>
                  <div className="text-center">
                    <p className="text-2xl font-bold text-[var(--text-primary)] uppercase">{ordinal(rankings.rush_rank)}</p>
                    <p className="text-[11px] text-[var(--text-muted)]">RusY/G ({rankings.rush_ypg})</p>
                  </div>
                  <div className="text-center">
                    <p className="text-2xl font-bold text-[var(--text-primary)] uppercase">{ordinal(rankings.total_rank)}</p>
                    <p className="text-[11px] text-[var(--text-muted)]">Yds/G ({rankings.total_ypg})</p>
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Dark info strip */}
        {(() => {
          const coach = useAuthStore.getState().coach;
          const capTotal = (team as unknown as Record<string, unknown>)?.salary_cap as number ?? 0;
          const capUsed = (team as unknown as Record<string, unknown>)?.cap_used as number ?? 0;
          const capSpace = capTotal - capUsed;
          const formatCap = (n: number) => {
            if (Math.abs(n) >= 1_000_000) return `$${(n / 1_000_000).toFixed(1)}M`;
            if (Math.abs(n) >= 1_000) return `$${(n / 1_000).toFixed(0)}K`;
            return `$${n}`;
          };
          return (
            <div className="bg-[#222222] text-white">
              <div className="flex items-center justify-between px-8 py-3.5">
                <div className="flex items-center gap-6 text-sm">
                  {coach && (
                    <span className="text-white/70">
                      Coach: <span className="font-semibold text-white">{coach.name}</span>
                    </span>
                  )}
                  <span className="text-white/70">
                    Cap Space: <span className={`font-semibold ${capSpace > 0 ? 'text-white' : 'text-red-400'}`}>{formatCap(capSpace)}</span>
                  </span>
                </div>
                {team?.streak && (
                  <span className="text-sm text-white/70">
                    Streak: <span className="font-semibold text-white">{team.streak}</span>
                  </span>
                )}
              </div>
            </div>
          );
        })()}
      </div>

      {/* Tab Navigation */}
      <SportsTabs
        tabs={[
          { key: 'roster', label: 'Roster', icon: Users },
          { key: 'depth', label: 'Depth Chart', icon: ClipboardList },
        ]}
        activeTab={activeTab}
        onChange={setActiveTab}
        variant="underline"
        accentColor={teamColor}
      />

      {/* ── Roster Tab ── */}
      {activeTab === 'roster' && (
        <div className="mt-5 space-y-5">
          {/* Position Filter */}
          <div className="flex items-center gap-3">
            <Select value={posFilter} onValueChange={(v) => v && setPosFilter(v)}>
              <SelectTrigger className="w-32 border-[var(--border)] bg-[var(--bg-elevated)]">
                <SelectValue placeholder="Position" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All</SelectItem>
                {positions.map((p) => (
                  <SelectItem key={p} value={p}>{p}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <span className="text-xs font-medium text-[var(--text-muted)]">
              {filtered.length} players
            </span>
          </div>

          {/* Active Roster Table */}
          <Section title="Active Roster" accentColor={teamColor}>
            <DataTable<Player>
              columns={activeColumns}
              data={sortedFiltered}
              accentColor={teamColor}
              onRowClick={(p) => navigate(`/player/${p.id}`)}
              rowKey={(p) => p.id}
              striped
              emptyMessage="No players match the current filter"
            />
          </Section>

          {/* Practice Squad Collapsible */}
          <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
            <button
              className="flex w-full items-center gap-2 px-4 py-2.5 border-b border-[var(--border)] bg-[var(--bg-elevated)]/50 text-left"
              onClick={() => setPsExpanded(!psExpanded)}
            >
              {psExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
              <UserPlus className="h-4 w-4 text-yellow-500" />
              <span className="font-display text-xs uppercase tracking-[0.15em]">Practice Squad</span>
              <span className="ml-2 inline-flex items-center rounded px-2 py-0.5 text-[10px] font-semibold bg-yellow-500/10 text-yellow-400 border border-yellow-500/20">
                {practiceSquadPlayers.length}/16
              </span>
            </button>
            {psExpanded && (
              sortedPS.length === 0 ? (
                <EmptyBlock
                  icon={UserPlus}
                  title="No Practice Squad Players"
                  description="Move players from the active roster to the practice squad to develop them."
                />
              ) : (
                <DataTable<Player>
                  columns={psColumns}
                  data={sortedPS}
                  onRowClick={(p) => navigate(`/player/${p.id}`)}
                  rowKey={(p) => p.id}
                  emptyMessage="No players on the practice squad"
                />
              )
            )}
          </div>

          {/* Injured Reserve Collapsible */}
          <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
            <button
              className="flex w-full items-center gap-2 px-4 py-2.5 border-b border-[var(--border)] bg-[var(--bg-elevated)]/50 text-left"
              onClick={() => setIrExpanded(!irExpanded)}
            >
              {irExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
              <ShieldAlert className="h-4 w-4 text-red-500" />
              <span className="font-display text-xs uppercase tracking-[0.15em]">Injured Reserve</span>
              <span className="ml-2 inline-flex items-center rounded px-2 py-0.5 text-[10px] font-semibold bg-red-500/10 text-red-400 border border-red-500/20">
                {injuredReservePlayers.length}
              </span>
            </button>
            {irExpanded && (
              sortedIR.length === 0 ? (
                <EmptyBlock
                  icon={ShieldAlert}
                  title="No Players on IR"
                  description="Players placed on injured reserve will appear here."
                />
              ) : (
                <DataTable<Player>
                  columns={irColumns}
                  data={sortedIR}
                  onRowClick={(p) => navigate(`/player/${p.id}`)}
                  rowKey={(p) => p.id}
                  emptyMessage="No players on injured reserve"
                />
              )
            )}
          </div>
        </div>
      )}

      {/* ── Depth Chart Tab ── */}
      {activeTab === 'depth' && (
        <div className="mt-5 space-y-5">
          {/* Swap instruction banner */}
          {swapSelection && (
            <div className="flex items-center justify-between rounded-lg border border-[var(--accent-blue)]/30 bg-[var(--accent-blue)]/5 px-4 py-2.5">
              <p className="text-sm text-[var(--text-secondary)]">
                <span className="font-semibold text-[var(--accent-blue)]">{swapSelection.name}</span> selected
                — click any <span className="font-semibold">{swapSelection.basePos}</span> to swap
              </p>
              <button
                onClick={() => setSwapSelection(null)}
                className="text-xs text-[var(--text-muted)] hover:text-[var(--text-primary)] px-2 py-1"
              >
                Cancel
              </button>
            </div>
          )}

          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-[var(--text-muted)]">
              {depthChart ? Object.values(depthChart).flat().length : 0} positions filled
              {' '}&middot; Click two players at the same position to swap
            </span>
            <ActionButton
              size="sm"
              variant="secondary"
              icon={Wand2}
              disabled={autoSetMut.isPending}
              onClick={() => {
                autoSetMut.mutate(undefined, {
                  onSuccess: () => toast.success('Depth chart set to best available players'),
                  onError: (err) => toast.error(err.message),
                });
              }}
            >
              {autoSetMut.isPending ? 'Setting...' : 'Auto-Set Best Lineup'}
            </ActionButton>
          </div>

          {Object.entries(positionGroups).map(([group, groupPositions]) => {
            const rows = groupPositions.map((pos) => ({
              pos,
              entries: (depthChart?.[pos] ?? []) as Array<{ player_id: number; name: string; overall_rating: number }>,
            }));

            return (
              <Section key={group} title={group} accentColor={teamColor}>
                <DataTable
                  columns={depthColumns}
                  data={rows}
                  rowKey={(row) => row.pos}
                  accentColor={teamColor}
                />
              </Section>
            );
          })}
        </div>
      )}

    </div>
  );
}

function DetailLine({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline gap-4">
      <span className="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] w-20 shrink-0">{label}</span>
      <span className="text-sm font-medium text-[var(--text-primary)]">{value}</span>
    </div>
  );
}

function DevelopmentBadge({ potential }: { potential: string }) {
  const colors: Record<string, string> = {
    elite: 'bg-yellow-500/15 text-yellow-400 border-yellow-500/25',
    high: 'bg-blue-500/15 text-blue-400 border-blue-500/25',
    average: 'bg-gray-500/15 text-gray-400 border-gray-500/25',
    limited: 'bg-red-500/15 text-red-400 border-red-500/25',
    // Legacy values
    superstar: 'bg-yellow-500/15 text-yellow-400 border-yellow-500/25',
    star: 'bg-blue-500/15 text-blue-400 border-blue-500/25',
    normal: 'bg-gray-500/15 text-gray-400 border-gray-500/25',
    slow: 'bg-red-500/15 text-red-400 border-red-500/25',
  };
  const labels: Record<string, string> = {
    elite: 'Elite', high: 'High', average: 'Average', limited: 'Limited',
    superstar: 'Elite', star: 'High', normal: 'Average', slow: 'Limited',
  };
  return (
    <span className={`inline-flex items-center rounded px-2 py-0.5 text-[10px] font-semibold border ${colors[potential] ?? 'bg-gray-500/15 text-gray-400 border-gray-500/25'}`}>
      {labels[potential] ?? potential}
    </span>
  );
}
