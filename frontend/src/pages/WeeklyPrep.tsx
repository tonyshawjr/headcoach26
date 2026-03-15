import { useState, useEffect, useMemo } from 'react';
import { useParams } from 'react-router-dom';
import { useGame, useGamePlan, useSubmitGamePlan } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { toast } from 'sonner';
import { Swords, Shield, Check, Zap, AlertTriangle, ClipboardList } from 'lucide-react';
import { TeamLogo } from '@/components/TeamLogo';
import {
  PageLayout,
  PageHeader,
  Section,
  ActionButton,
  StatCard,
  ContentGrid,
  MainColumn,
  SidebarColumn,
  Hero,
  SidePanel,
} from '@/components/ui/sports-ui';

// Matchup matrix from SimEngine: [offense][defense] => modifier
// Positive = offense has advantage, Negative = defense has advantage
const MATCHUP_MATRIX: Record<string, Record<string, number>> = {
  run_heavy:    { base_43: 0, '34': 2, blitz: 3, prevent: -1, zone: -2 },
  balanced:     { base_43: 0, '34': 0, blitz: 1, prevent: 1, zone: 0 },
  pass_heavy:   { base_43: 1, '34': 0, blitz: -3, prevent: 2, zone: -1 },
  no_huddle:    { base_43: 2, '34': 1, blitz: -2, prevent: 3, zone: 1 },
  ball_control: { base_43: 1, '34': 1, blitz: 2, prevent: -2, zone: -1 },
};

// Derive strengths/weaknesses from the matchup matrix
function getSchemeMatchups(type: 'offense' | 'defense', key: string) {
  const strong: string[] = [];
  const weak: string[] = [];

  if (type === 'offense') {
    // For offense: check how it does against each defense
    const row = MATCHUP_MATRIX[key];
    if (row) {
      for (const [def, mod] of Object.entries(row)) {
        if (mod >= 2) strong.push(defenseSchemes[def]?.label ?? def);
        else if (mod <= -2) weak.push(defenseSchemes[def]?.label ?? def);
      }
    }
  } else {
    // For defense: check how each offense does against it
    for (const [off, row] of Object.entries(MATCHUP_MATRIX)) {
      const mod = row[key] ?? 0;
      // Negative mod = offense struggles = defense is strong vs it
      if (mod <= -2) strong.push(offenseSchemes[off]?.label ?? off);
      else if (mod >= 2) weak.push(offenseSchemes[off]?.label ?? off);
    }
  }

  return { strong, weak };
}

const offenseSchemes: Record<string, { label: string; desc: string }> = {
  run_heavy: { label: 'Run Heavy', desc: 'Pound the rock. Control the clock and wear down the defense.' },
  balanced: { label: 'Balanced', desc: 'Mix of run and pass. Keep the defense guessing.' },
  pass_heavy: { label: 'Pass Heavy', desc: 'Air it out. Quick strikes and deep shots.' },
  no_huddle: { label: 'No Huddle', desc: 'Fast tempo. Keep the defense on its heels.' },
  ball_control: { label: 'Ball Control', desc: 'Short passes and runs. Minimize turnovers, move the chains.' },
};

const defenseSchemes: Record<string, { label: string; desc: string }> = {
  base_43: { label: '4-3 Base', desc: 'Standard formation. Balanced run and pass defense.' },
  '34': { label: '3-4', desc: 'Extra linebacker. Good for versatile pass rushing.' },
  blitz: { label: 'Blitz', desc: 'Bring the pressure. High risk, high reward.' },
  prevent: { label: 'Prevent', desc: 'Protect the deep ball. Give up underneath.' },
  zone: { label: 'Zone', desc: 'Zone coverage. Disguise assignments and jump routes.' },
};

// Find the best offensive scheme against a given defense key
function getRecommendedOffense(defenseKey: string): string | null {
  let best = '';
  let bestMod = -Infinity;
  for (const [off, row] of Object.entries(MATCHUP_MATRIX)) {
    const mod = row[defenseKey] ?? 0;
    if (mod > bestMod) {
      bestMod = mod;
      best = off;
    }
  }
  return bestMod > 0 ? best : null;
}

// Find the best defensive scheme against a given offense key
function getRecommendedDefense(offenseKey: string): string | null {
  const row = MATCHUP_MATRIX[offenseKey];
  if (!row) return null;
  let best = '';
  let bestMod = Infinity;
  for (const [def, mod] of Object.entries(row)) {
    if (mod < bestMod) {
      bestMod = mod;
      best = def;
    }
  }
  return bestMod < 0 ? best : null;
}

export default function WeeklyPrep() {
  const { id } = useParams<{ id: string }>();
  const gameId = Number(id);
  const team = useAuthStore((s) => s.team);

  const { data: game } = useGame(gameId);
  const { data: planData } = useGamePlan(gameId);
  const submitPlan = useSubmitGamePlan(gameId);

  const [offense, setOffense] = useState('balanced');
  const [defense, setDefense] = useState('base_43');

  // Sync form when plan data loads
  useEffect(() => {
    if (planData?.my_plan) {
      setOffense(planData.my_plan.offense);
      setDefense(planData.my_plan.defense);
    }
  }, [planData]);

  // Derive opponent tendency recommendations
  const opponentTendency = planData?.opponent_plan; // only revealed after sim
  const recommendedOffense = useMemo(() => {
    if (!opponentTendency?.defense) return null;
    return getRecommendedOffense(opponentTendency.defense);
  }, [opponentTendency]);
  const recommendedDefense = useMemo(() => {
    if (!opponentTendency?.offense) return null;
    return getRecommendedDefense(opponentTendency.offense);
  }, [opponentTendency]);

  if (!game) return <p className="text-[var(--text-secondary)]">Loading game...</p>;

  const isHome = game.home_team_id === team?.id;
  const myTeam = isHome ? game.home_team : game.away_team;
  const opponent = isHome ? game.away_team : game.home_team;
  const alreadySubmitted = !!planData?.my_plan;

  const handleSubmit = async () => {
    try {
      await submitPlan.mutateAsync({ offense, defense });
      toast.success('Game plan submitted!');
    } catch (e: unknown) {
      toast.error(e instanceof Error ? e.message : 'Failed to submit');
    }
  };

  const myColor = myTeam?.primary_color ?? 'var(--accent-blue)';
  const oppColor = opponent?.primary_color ?? 'var(--accent-red)';

  return (
    <PageLayout
      hero={
        <Hero colorLeft={myColor} colorRight={oppColor}>
          <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-6">
            {/* Your Team */}
            <div className="text-center">
              <div className="flex items-center justify-center gap-2 mb-2">
                {myTeam && (
                  <TeamLogo
                    abbreviation={myTeam.abbreviation}
                    primaryColor={myTeam.primary_color}
                    secondaryColor={myTeam.secondary_color}
                    size="md"
                  />
                )}
                <span className="font-display text-sm sm:text-base">{myTeam?.city} {myTeam?.name}</span>
              </div>
              <p className="font-stat text-3xl sm:text-4xl" style={{ color: myColor }}>
                {myTeam?.overall_rating ?? '--'}
                <span className="text-xs text-[var(--text-muted)] ml-1">OVR</span>
              </p>
              <p className="text-xs text-[var(--text-secondary)] mt-1">
                {myTeam?.wins}-{myTeam?.losses}{myTeam?.ties ? `-${myTeam.ties}` : ''}
              </p>
            </div>

            {/* VS Divider */}
            <div className="text-center">
              <p className="font-display text-xl text-[var(--text-muted)]">vs</p>
              <p className="text-[10px] text-[var(--text-muted)] uppercase font-bold tracking-widest">
                {isHome ? 'Home' : 'Away'}
              </p>
            </div>

            {/* Opponent */}
            <div className="text-center">
              <div className="flex items-center justify-center gap-2 mb-2">
                {opponent && (
                  <TeamLogo
                    abbreviation={opponent.abbreviation}
                    primaryColor={opponent.primary_color}
                    secondaryColor={opponent.secondary_color}
                    size="md"
                  />
                )}
                <span className="font-display text-sm sm:text-base">{opponent?.city} {opponent?.name}</span>
              </div>
              <p className="font-stat text-3xl sm:text-4xl" style={{ color: oppColor }}>
                {opponent?.overall_rating ?? '--'}
                <span className="text-xs text-[var(--text-muted)] ml-1">OVR</span>
              </p>
              <p className="text-xs text-[var(--text-secondary)] mt-1">
                {opponent?.wins}-{opponent?.losses}{opponent?.ties ? `-${opponent.ties}` : ''}
              </p>
            </div>
          </div>

          {game.weather && game.weather !== 'clear' && game.weather !== 'dome' && (
            <div className="mt-4 flex items-center justify-center gap-1.5 text-xs text-yellow-400">
              <AlertTriangle className="h-3.5 w-3.5" />
              <span className="capitalize font-semibold">{game.weather}</span> conditions — rushing schemes get a boost
            </div>
          )}
        </Hero>
      }
    >
      <PageHeader
        title={`Week ${game.week} Game Plan`}
        subtitle={`${isHome ? 'vs' : '@'} ${opponent?.city} ${opponent?.name}${game.weather && game.weather !== 'clear' && game.weather !== 'dome' ? ` — ${game.weather}` : ''}`}
        icon={ClipboardList}
        accentColor={myColor}
      />

      <ContentGrid layout="main-sidebar">
        <MainColumn>
          {/* Offensive Scheme */}
          <Section title="Offensive Scheme" accentColor="var(--accent-blue)" delay={0.05}>
            <div className="space-y-2">
              {Object.entries(offenseSchemes).map(([key, { label, desc }]) => {
                const { strong, weak } = getSchemeMatchups('offense', key);
                const isRecommended = recommendedOffense === key;
                return (
                  <button
                    key={key}
                    onClick={() => setOffense(key)}
                    className={`flex items-start gap-3 rounded-lg border p-3 text-left transition-all w-full ${
                      offense === key
                        ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10 shadow-sm'
                        : 'border-[var(--border)] hover:border-[var(--text-muted)] hover:bg-[var(--bg-elevated)]/30'
                    }`}
                  >
                    <div className="mt-0.5">
                      <Swords className={`h-4 w-4 ${offense === key ? 'text-[var(--accent-blue)]' : 'text-[var(--text-muted)]'}`} />
                    </div>
                    <div className="flex-1">
                      <div className="flex items-center gap-2">
                        <p className="text-sm font-semibold">{label}</p>
                        {isRecommended && (
                          <span className="text-[9px] font-bold uppercase tracking-wider bg-green-500/20 text-green-400 border border-green-500/30 px-1.5 py-0 rounded">
                            RECOMMENDED
                          </span>
                        )}
                      </div>
                      <p className="text-xs text-[var(--text-secondary)] mt-0.5">{desc}</p>
                      {(strong.length > 0 || weak.length > 0) && (
                        <div className="flex flex-wrap gap-x-4 gap-y-0.5 mt-1.5">
                          {strong.length > 0 && (
                            <p className="text-[10px] text-green-400">
                              + Strong vs: {strong.join(', ')}
                            </p>
                          )}
                          {weak.length > 0 && (
                            <p className="text-[10px] text-red-400">
                              - Weak vs: {weak.join(', ')}
                            </p>
                          )}
                        </div>
                      )}
                    </div>
                    {offense === key && <Check className="h-4 w-4 text-[var(--accent-blue)] mt-0.5 shrink-0" />}
                  </button>
                );
              })}
            </div>
          </Section>

          {/* Defensive Scheme */}
          <Section title="Defensive Scheme" accentColor="var(--accent-red)" delay={0.1}>
            <div className="space-y-2">
              {Object.entries(defenseSchemes).map(([key, { label, desc }]) => {
                const { strong, weak } = getSchemeMatchups('defense', key);
                const isRecommended = recommendedDefense === key;
                return (
                  <button
                    key={key}
                    onClick={() => setDefense(key)}
                    className={`flex items-start gap-3 rounded-lg border p-3 text-left transition-all w-full ${
                      defense === key
                        ? 'border-[var(--accent-red)] bg-[var(--accent-red)]/10 shadow-sm'
                        : 'border-[var(--border)] hover:border-[var(--text-muted)] hover:bg-[var(--bg-elevated)]/30'
                    }`}
                  >
                    <div className="mt-0.5">
                      <Shield className={`h-4 w-4 ${defense === key ? 'text-[var(--accent-red)]' : 'text-[var(--text-muted)]'}`} />
                    </div>
                    <div className="flex-1">
                      <div className="flex items-center gap-2">
                        <p className="text-sm font-semibold">{label}</p>
                        {isRecommended && (
                          <span className="text-[9px] font-bold uppercase tracking-wider bg-green-500/20 text-green-400 border border-green-500/30 px-1.5 py-0 rounded">
                            RECOMMENDED
                          </span>
                        )}
                      </div>
                      <p className="text-xs text-[var(--text-secondary)] mt-0.5">{desc}</p>
                      {(strong.length > 0 || weak.length > 0) && (
                        <div className="flex flex-wrap gap-x-4 gap-y-0.5 mt-1.5">
                          {strong.length > 0 && (
                            <p className="text-[10px] text-green-400">
                              + Shuts down: {strong.join(', ')}
                            </p>
                          )}
                          {weak.length > 0 && (
                            <p className="text-[10px] text-red-400">
                              - Vulnerable to: {weak.join(', ')}
                            </p>
                          )}
                        </div>
                      )}
                    </div>
                    {defense === key && <Check className="h-4 w-4 text-[var(--accent-red)] mt-0.5 shrink-0" />}
                  </button>
                );
              })}
            </div>
          </Section>

          {/* Submit Button */}
          <ActionButton
            onClick={handleSubmit}
            disabled={submitPlan.isPending}
            fullWidth
            size="lg"
            accentColor={myColor}
          >
            {submitPlan.isPending ? 'Submitting...' : alreadySubmitted ? 'Update Game Plan' : 'Lock In Game Plan'}
          </ActionButton>
        </MainColumn>

        <SidebarColumn>
          {/* Intel Report */}
          <SidePanel title="Intel Report" accentColor="var(--accent-gold)" delay={0.15}>
            {opponentTendency ? (
              <div className="space-y-3">
                <div className="grid grid-cols-1 gap-2">
                  <StatCard
                    label="Their Offense"
                    value={offenseSchemes[opponentTendency.offense]?.label ?? opponentTendency.offense}
                    accentColor={oppColor}
                  />
                  <StatCard
                    label="Their Defense"
                    value={defenseSchemes[opponentTendency.defense]?.label ?? opponentTendency.defense}
                    accentColor={oppColor}
                  />
                </div>
                {recommendedOffense && (
                  <div className="rounded-lg border border-green-500/30 bg-green-500/5 p-3">
                    <p className="text-[10px] font-bold uppercase tracking-widest text-green-400 mb-0.5">Recommended Offense</p>
                    <p className="text-sm font-semibold text-green-400">{offenseSchemes[recommendedOffense]?.label ?? recommendedOffense}</p>
                    <p className="text-[10px] text-[var(--text-muted)] mt-0.5">Exploits their {defenseSchemes[opponentTendency.defense]?.label} defense</p>
                  </div>
                )}
                {recommendedDefense && (
                  <div className="rounded-lg border border-green-500/30 bg-green-500/5 p-3">
                    <p className="text-[10px] font-bold uppercase tracking-widest text-green-400 mb-0.5">Recommended Defense</p>
                    <p className="text-sm font-semibold text-green-400">{defenseSchemes[recommendedDefense]?.label ?? recommendedDefense}</p>
                    <p className="text-[10px] text-[var(--text-muted)] mt-0.5">Counters their {offenseSchemes[opponentTendency.offense]?.label} offense</p>
                  </div>
                )}
              </div>
            ) : (
              <div className="py-3 text-center">
                <Zap className="h-5 w-5 text-[var(--text-muted)] mx-auto mb-2" />
                <p className="text-sm text-[var(--text-muted)]">
                  Opponent tendencies unknown. Game plan intel is revealed after simulation.
                </p>
              </div>
            )}
          </SidePanel>

          {/* Current Selections Summary */}
          <SidePanel title="Your Game Plan" accentColor={myColor} delay={0.2}>
            <div className="space-y-3">
              <div>
                <p className="text-[10px] font-bold uppercase tracking-widest text-[var(--text-muted)] mb-1">Offense</p>
                <div className="flex items-center gap-2">
                  <Swords className="h-3.5 w-3.5 text-[var(--accent-blue)]" />
                  <span className="text-sm font-semibold">{offenseSchemes[offense]?.label ?? offense}</span>
                </div>
                <p className="text-[11px] text-[var(--text-secondary)] mt-0.5 ml-[22px]">
                  {offenseSchemes[offense]?.desc}
                </p>
              </div>
              <div className="h-px bg-[var(--border)]" />
              <div>
                <p className="text-[10px] font-bold uppercase tracking-widest text-[var(--text-muted)] mb-1">Defense</p>
                <div className="flex items-center gap-2">
                  <Shield className="h-3.5 w-3.5 text-[var(--accent-red)]" />
                  <span className="text-sm font-semibold">{defenseSchemes[defense]?.label ?? defense}</span>
                </div>
                <p className="text-[11px] text-[var(--text-secondary)] mt-0.5 ml-[22px]">
                  {defenseSchemes[defense]?.desc}
                </p>
              </div>
              {alreadySubmitted && (
                <>
                  <div className="h-px bg-[var(--border)]" />
                  <div className="flex items-center gap-1.5 text-xs text-green-400">
                    <Check className="h-3.5 w-3.5" />
                    <span className="font-semibold">Plan submitted</span>
                  </div>
                </>
              )}
            </div>
          </SidePanel>
        </SidebarColumn>
      </ContentGrid>
    </PageLayout>
  );
}
