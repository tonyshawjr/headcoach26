import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { motion } from 'framer-motion';
import { Trophy, Star, Shield, Award, Crown, Users } from 'lucide-react';
import { PlayerPhoto } from '@/components/PlayerPhoto';
import { TeamLogo } from '@/components/TeamLogo';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { PageLayout, PageHeader, Section, EmptyBlock } from '@/components/ui/sports-ui';

/* ================================================================
   TYPES — matches /api/standings/award-history response
   ================================================================ */

interface AwardEntry {
  winner_id: number;
  winner_name: string;
  team_abbr?: string;
  team_color?: string;
  position?: string;
  overall_rating?: number;
  image_url?: string;
}

interface SeasonAwards {
  season_year: number;
  mvp: AwardEntry | null;
  opoy: AwardEntry | null;
  dpoy: AwardEntry | null;
  coty: AwardEntry | null;
  all_league_first: AwardEntry[];
  all_league_second: AwardEntry[];
  gridiron_classic: AwardEntry[];
}

/* ================================================================
   CONSTANTS
   ================================================================ */

const GOLD = '#D4A017';
const SILVER = '#9CA3AF';

const OFFENSE_POS = new Set(['QB', 'RB', 'FB', 'WR', 'TE', 'OT', 'OG', 'C', 'LT', 'LG', 'RG', 'RT', 'OL']);
const SPECIAL_POS = new Set(['K', 'P', 'KR', 'PR', 'LS']);

function unitOf(pos?: string): 'Offense' | 'Defense' | 'Special Teams' {
  if (!pos) return 'Defense';
  const up = pos.toUpperCase();
  if (OFFENSE_POS.has(up)) return 'Offense';
  if (SPECIAL_POS.has(up)) return 'Special Teams';
  return 'Defense';
}

/* ================================================================
   MAJOR AWARD CARD
   ================================================================ */

function MajorAwardCard({ label, entry, icon: Icon, accentColor, delay }: {
  label: string;
  entry: AwardEntry | null;
  icon: React.ElementType;
  accentColor: string;
  delay: number;
}) {
  const navigate = useNavigate();
  if (!entry) return null;

  return (
    <motion.div
      initial={{ opacity: 0, y: 16 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, delay }}
      className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden cursor-pointer hover:border-[var(--text-muted)] transition-colors"
      onClick={() => {
        if (label === 'Coach of the Year') {
          const teamId = (entry as Record<string, unknown>).coach_team_id as number | undefined;
          if (teamId) navigate(`/team/${teamId}`);
        } else if (entry.winner_id) {
          navigate(`/player/${entry.winner_id}`);
        }
      }}
    >
      <div className="h-[3px] w-full" style={{ backgroundColor: accentColor }} />
      <div className="p-5">
        <div className="flex items-center gap-2 mb-4">
          <Icon className="h-4 w-4" style={{ color: accentColor }} />
          <span className="text-xs font-bold uppercase tracking-[0.14em]" style={{ color: accentColor }}>
            {label}
          </span>
        </div>

        <div className="flex items-center gap-4">
          <PlayerPhoto imageUrl={entry.image_url} size={56} className="rounded-lg" />
          <div className="flex-1 min-w-0">
            <p className="text-lg font-bold text-[var(--text-primary)] truncate">{entry.winner_name}</p>
            <div className="flex items-center gap-2 mt-1">
              {entry.position && (
                <Badge variant="outline" className="text-[10px] px-1.5 py-0">{entry.position}</Badge>
              )}
              {entry.team_abbr && (
                <TeamLogo
                  abbreviation={entry.team_abbr}
                  primaryColor={entry.team_color}
                  size="xs"
                />
              )}
              {entry.overall_rating && (
                <span className="font-stat text-sm text-[var(--text-secondary)]">{entry.overall_rating} OVR</span>
              )}
            </div>
          </div>
        </div>
      </div>
    </motion.div>
  );
}

/* ================================================================
   ALL-LEAGUE TEAM SECTION
   ================================================================ */

function AllLeagueSection({ title, players, accentColor, delay }: {
  title: string;
  players: AwardEntry[];
  accentColor: string;
  delay: number;
}) {
  const navigate = useNavigate();
  if (players.length === 0) return null;

  // Group by unit
  const grouped: Record<string, AwardEntry[]> = { Offense: [], Defense: [], 'Special Teams': [] };
  players.forEach((p) => {
    const unit = unitOf(p.position);
    grouped[unit].push(p);
  });

  return (
    <Section title={title} accentColor={accentColor} delay={delay}>
      {Object.entries(grouped).map(([unit, unitPlayers]) => {
        if (unitPlayers.length === 0) return null;
        return (
          <div key={unit} className="mb-4">
            <p className="text-xs font-bold uppercase tracking-[0.14em] text-[var(--text-muted)] mb-2">{unit}</p>
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {unitPlayers.map((p) => (
                <div
                  key={p.winner_id}
                  className="flex items-center gap-3 rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-3 cursor-pointer hover:bg-[var(--bg-elevated)] transition-colors"
                  onClick={() => navigate(`/player/${p.winner_id}`)}
                >
                  <PlayerPhoto imageUrl={p.image_url} size={36} className="rounded" />
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-semibold text-[var(--text-primary)] truncate">{p.winner_name}</p>
                    <div className="flex items-center gap-1.5">
                      <Badge variant="outline" className="text-[9px] px-1 py-0 h-4">{p.position}</Badge>
                      {p.team_abbr && (
                        <span className="text-[10px] text-[var(--text-muted)]">{p.team_abbr}</span>
                      )}
                      {p.overall_rating && (
                        <span className="font-stat text-[10px] text-[var(--text-secondary)]">{p.overall_rating}</span>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        );
      })}
    </Section>
  );
}

/* ================================================================
   GRIDIRON CLASSIC SECTION
   ================================================================ */

function GridironClassicSection({ players, delay }: { players: AwardEntry[]; delay: number }) {
  const navigate = useNavigate();
  if (players.length === 0) return null;

  // Group by team assignment from stats JSON (set by AllProEngine)
  const teams: Record<string, AwardEntry[]> = {};
  players.forEach((p) => {
    // stats might be a string or already parsed — the award-history endpoint doesn't include stats
    // Fall back to alternating split if no team info
    const teamName = (p as Record<string, unknown>).team_label as string | undefined;
    if (teamName) {
      if (!teams[teamName]) teams[teamName] = [];
      teams[teamName].push(p);
    }
  });

  let teamA: AwardEntry[];
  let teamB: AwardEntry[];
  let captainA: string;
  let captainB: string;

  const teamNames = Object.keys(teams);
  if (teamNames.length >= 2) {
    teamA = teams[teamNames[0]];
    teamB = teams[teamNames[1]];
    captainA = teamNames[0].replace('Team ', '');
    captainB = teamNames[1].replace('Team ', '');
  } else {
    // Fallback: alternate split ensuring equal teams
    teamA = [];
    teamB = [];
    players.forEach((p, i) => {
      if (i % 2 === 0) teamA.push(p);
      else teamB.push(p);
    });
    captainA = teamA[0]?.winner_name?.split(' ').pop() ?? 'A';
    captainB = teamB[0]?.winner_name?.split(' ').pop() ?? 'B';
  }

  function PlayerRow({ p }: { p: AwardEntry }) {
    return (
      <div
        className="flex items-center gap-2 px-4 py-2 border-b border-[var(--border)] last:border-0 cursor-pointer hover:bg-[var(--bg-elevated)] transition-colors"
        onClick={() => navigate(`/player/${p.winner_id}`)}
      >
        <Badge variant="outline" className="text-[9px] px-1 py-0 h-4 w-8 justify-center">{p.position}</Badge>
        <span className="text-sm font-medium text-[var(--text-primary)] flex-1 truncate">{p.winner_name}</span>
        {p.team_abbr && <span className="text-[10px] text-[var(--text-muted)]">{p.team_abbr}</span>}
      </div>
    );
  }

  return (
    <Section title="Gridiron Classic" accentColor="var(--accent-blue)" delay={delay}>
      <div className="grid gap-4 md:grid-cols-2">
        <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
          <div className="flex items-center gap-2 px-4 py-2.5 border-b border-[var(--border)] bg-[var(--bg-elevated)]/40">
            <Crown className="h-3.5 w-3.5 text-[var(--accent-blue)]" />
            <span className="text-xs font-bold uppercase tracking-[0.14em] text-[var(--accent-blue)]">
              Team {captainA}
            </span>
          </div>
          {teamA.map((p) => <PlayerRow key={p.winner_id} p={p} />)}
        </div>
        <div className="rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden">
          <div className="flex items-center gap-2 px-4 py-2.5 border-b border-[var(--border)] bg-[var(--bg-elevated)]/40">
            <Crown className="h-3.5 w-3.5 text-[var(--accent-gold)]" />
            <span className="text-xs font-bold uppercase tracking-[0.14em] text-[var(--accent-gold)]">
              Team {captainB}
            </span>
          </div>
          {teamB.map((p) => <PlayerRow key={p.winner_id} p={p} />)}
        </div>
      </div>
    </Section>
  );
}

/* ================================================================
   MAIN PAGE
   ================================================================ */

export default function LeagueAwards() {
  const [allYears, setAllYears] = useState<SeasonAwards[]>([]);
  const [selectedYear, setSelectedYear] = useState<string>('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetch('/api/standings/award-history', { credentials: 'include' })
      .then((r) => r.json())
      .then((data) => {
        const years = (data.history ?? []) as SeasonAwards[];
        setAllYears(years);
        if (years.length > 0) {
          setSelectedYear(String(years[0].season_year));
        }
      })
      .catch(() => setAllYears([]))
      .finally(() => setLoading(false));
  }, []);

  const current = allYears.find((y) => String(y.season_year) === selectedYear);

  return (
    <PageLayout>
      <PageHeader
        title="League Awards"
        icon={Trophy}
        accentColor={GOLD}
        actions={
          allYears.length > 0 ? (
            <Select value={selectedYear} onValueChange={setSelectedYear}>
              <SelectTrigger className="w-36">
                <SelectValue placeholder="Select Year" />
              </SelectTrigger>
              <SelectContent>
                {allYears.map((y) => (
                  <SelectItem key={y.season_year} value={String(y.season_year)}>
                    {y.season_year} Season
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          ) : undefined
        }
      />

      {loading ? (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {[1, 2, 3, 4].map((i) => (
              <div key={i} className="h-32 rounded-xl bg-[var(--bg-surface)] border border-[var(--border)] animate-pulse" />
            ))}
          </div>
        </div>
      ) : !current ? (
        <EmptyBlock
          icon={Trophy}
          title="No awards yet"
          description="Complete a full season to see award winners."
        />
      ) : (
        <div className="space-y-8">
          {/* Major Awards */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <MajorAwardCard label="Most Valuable Player" entry={current.mvp} icon={Crown} accentColor={GOLD} delay={0} />
            <MajorAwardCard label="Offensive Player of the Year" entry={current.opoy} icon={Star} accentColor={GOLD} delay={0.05} />
            <MajorAwardCard label="Defensive Player of the Year" entry={current.dpoy} icon={Shield} accentColor={GOLD} delay={0.1} />
            <MajorAwardCard label="Coach of the Year" entry={current.coty} icon={Award} accentColor={GOLD} delay={0.15} />
          </div>

          {/* All-League First Team */}
          <AllLeagueSection
            title="All-League First Team"
            players={current.all_league_first}
            accentColor={GOLD}
            delay={0.2}
          />

          {/* All-League Second Team */}
          <AllLeagueSection
            title="All-League Second Team"
            players={current.all_league_second}
            accentColor={SILVER}
            delay={0.25}
          />

          {/* Gridiron Classic */}
          <GridironClassicSection players={current.gridiron_classic} delay={0.3} />
        </div>
      )}
    </PageLayout>
  );
}
