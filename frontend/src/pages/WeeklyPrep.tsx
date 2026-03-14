import { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import { useGame, useGamePlan, useSubmitGamePlan } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { toast } from 'sonner';
import { Swords, Shield, Check } from 'lucide-react';
import { TeamBadge } from '@/components/TeamBadge';

const offenseDescriptions: Record<string, { label: string; desc: string }> = {
  run_heavy: { label: 'Run Heavy', desc: 'Pound the rock. Control the clock and wear down the defense.' },
  balanced: { label: 'Balanced', desc: 'Mix of run and pass. Keep the defense guessing.' },
  pass_heavy: { label: 'Pass Heavy', desc: 'Air it out. Quick strikes and deep shots.' },
  no_huddle: { label: 'No Huddle', desc: 'Fast tempo. Keep the defense on its heels.' },
  ball_control: { label: 'Ball Control', desc: 'Short passes and runs. Minimize turnovers, move the chains.' },
};

const defenseDescriptions: Record<string, { label: string; desc: string }> = {
  base_43: { label: '4-3 Base', desc: 'Standard formation. Balanced run and pass defense.' },
  '34': { label: '3-4', desc: 'Extra linebacker. Good for versatile pass rushing.' },
  blitz: { label: 'Blitz', desc: 'Bring the pressure. High risk, high reward.' },
  prevent: { label: 'Prevent', desc: 'Protect the deep ball. Give up underneath.' },
  zone: { label: 'Zone', desc: 'Zone coverage. Disguise assignments and jump routes.' },
};

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

  if (!game) return <p className="text-[var(--text-secondary)]">Loading game...</p>;

  const isHome = game.home_team_id === team?.id;
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

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div>
        <h1 className="font-display text-2xl">Week {game.week} Game Plan</h1>
        <p className="text-sm text-[var(--text-secondary)]">
          {isHome ? 'vs' : '@'} {opponent?.city} {opponent?.name}
          {game.weather && game.weather !== 'clear' && game.weather !== 'dome' && ` — ${game.weather}`}
        </p>
      </div>

      {/* Scouting Report */}
      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardHeader>
          <CardTitle className="font-display text-base">Scouting Report</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 gap-4 text-sm">
            <div>
              <p className="text-[var(--text-muted)] text-xs uppercase">Opponent</p>
              <p className="flex items-center gap-2 mt-1">
                <TeamBadge
                  abbreviation={opponent?.abbreviation}
                  primaryColor={opponent?.primary_color}
                  secondaryColor={opponent?.secondary_color}
                  size="md"
                />
                <span className="font-semibold">{opponent?.city} {opponent?.name}</span>
              </p>
              <p className="text-xs text-[var(--text-secondary)] mt-0.5">
                {opponent?.wins}-{opponent?.losses} — Rating: {opponent?.overall_rating}
              </p>
            </div>
            <div>
              <p className="text-[var(--text-muted)] text-xs uppercase">Location</p>
              <p className="mt-1">{isHome ? 'Home' : 'Away'}</p>
              <p className="text-xs text-[var(--text-secondary)] mt-0.5">{game.weather ?? 'Clear'}</p>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Offensive Scheme */}
      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 font-display text-base">
            <Swords className="h-4 w-4 text-[var(--accent-blue)]" /> Offensive Scheme
          </CardTitle>
        </CardHeader>
        <CardContent className="grid gap-2">
          {Object.entries(offenseDescriptions).map(([key, { label, desc }]) => (
            <button
              key={key}
              onClick={() => setOffense(key)}
              className={`flex items-center gap-3 rounded-md border p-3 text-left transition-colors ${
                offense === key
                  ? 'border-[var(--accent-blue)] bg-[var(--accent-blue)]/10'
                  : 'border-[var(--border)] hover:border-[var(--text-muted)]'
              }`}
            >
              <div className="flex-1">
                <p className="text-sm font-semibold">{label}</p>
                <p className="text-xs text-[var(--text-secondary)]">{desc}</p>
              </div>
              {offense === key && <Check className="h-4 w-4 text-[var(--accent-blue)]" />}
            </button>
          ))}
        </CardContent>
      </Card>

      {/* Defensive Scheme */}
      <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 font-display text-base">
            <Shield className="h-4 w-4 text-[var(--accent-red)]" /> Defensive Scheme
          </CardTitle>
        </CardHeader>
        <CardContent className="grid gap-2">
          {Object.entries(defenseDescriptions).map(([key, { label, desc }]) => (
            <button
              key={key}
              onClick={() => setDefense(key)}
              className={`flex items-center gap-3 rounded-md border p-3 text-left transition-colors ${
                defense === key
                  ? 'border-[var(--accent-red)] bg-[var(--accent-red)]/10'
                  : 'border-[var(--border)] hover:border-[var(--text-muted)]'
              }`}
            >
              <div className="flex-1">
                <p className="text-sm font-semibold">{label}</p>
                <p className="text-xs text-[var(--text-secondary)]">{desc}</p>
              </div>
              {defense === key && <Check className="h-4 w-4 text-[var(--accent-red)]" />}
            </button>
          ))}
        </CardContent>
      </Card>

      <Button onClick={handleSubmit} disabled={submitPlan.isPending} className="w-full">
        {submitPlan.isPending ? 'Submitting...' : alreadySubmitted ? 'Update Game Plan' : 'Lock In Game Plan'}
      </Button>
    </div>
  );
}
