import { Card } from '@/components/ui/card';
import { useNavigate } from 'react-router-dom';
import { TeamLogo } from '@/components/TeamLogo';
import type { Game } from '@/api/client';

interface GameCardProps {
  game: Game;
  myTeamId?: number;
}

export function GameCard({ game, myTeamId }: GameCardProps) {
  const navigate = useNavigate();
  const home = game.home_team;
  const away = game.away_team;
  const simulated = game.is_simulated;
  const isMyGame = myTeamId && (game.home_team_id === myTeamId || game.away_team_id === myTeamId);
  const awayWon = simulated && (game.away_score ?? 0) > (game.home_score ?? 0);
  const homeWon = simulated && (game.home_score ?? 0) > (game.away_score ?? 0);

  return (
    <Card
      className={`group relative cursor-pointer overflow-hidden border-[var(--border)] bg-[var(--bg-surface)] transition-all hover:bg-[var(--bg-elevated)] ${
        isMyGame ? 'ring-1 ring-[var(--accent-blue)]/40' : ''
      }`}
      onClick={() => navigate(simulated ? `/box-score/${game.id}` : `/game-plan/${game.id}`)}
    >
      {/* Top accent — uses away team color */}
      <div
        className="h-[2px] w-full"
        style={{
          background: isMyGame
            ? 'var(--accent-blue)'
            : `linear-gradient(90deg, ${away?.primary_color ?? '#333'}, ${home?.primary_color ?? '#333'})`,
        }}
      />

      <div className="p-3">
        {/* Status badge */}
        <div className="mb-2 flex items-center justify-between">
          {simulated ? (
            <span className="text-[10px] font-bold uppercase tracking-widest text-[var(--text-muted)]">Final</span>
          ) : (
            <span className="text-[10px] font-bold uppercase tracking-widest text-[var(--text-muted)]">WK {game.week}</span>
          )}
          {game.weather && game.weather !== 'clear' && game.weather !== 'dome' && (
            <span className="text-[10px] text-[var(--text-muted)]">{game.weather}</span>
          )}
        </div>

        {/* Away team row */}
        <div className={`flex items-center gap-2.5 rounded px-1.5 py-1 ${awayWon ? 'bg-white/[0.03]' : ''}`}>
          <TeamLogo
            abbreviation={away?.abbreviation}
            primaryColor={away?.primary_color}
            secondaryColor={away?.secondary_color}
            size="xs"
          />
          <span className={`flex-1 text-[13px] ${awayWon ? 'font-bold text-[var(--text-primary)]' : 'text-[var(--text-secondary)]'}`}>
            {away?.abbreviation ?? 'AWY'}
          </span>
          {simulated && (
            <span className={`font-stat text-sm ${awayWon ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'}`}>
              {game.away_score}
            </span>
          )}
        </div>

        {/* Divider */}
        <div className="my-0.5 ml-6 border-t border-[var(--border)]/50" />

        {/* Home team row */}
        <div className={`flex items-center gap-2.5 rounded px-1.5 py-1 ${homeWon ? 'bg-white/[0.03]' : ''}`}>
          <TeamLogo
            abbreviation={home?.abbreviation}
            primaryColor={home?.primary_color}
            secondaryColor={home?.secondary_color}
            size="xs"
          />
          <span className={`flex-1 text-[13px] ${homeWon ? 'font-bold text-[var(--text-primary)]' : 'text-[var(--text-secondary)]'}`}>
            {home?.abbreviation ?? 'HME'}
          </span>
          {simulated && (
            <span className={`font-stat text-sm ${homeWon ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'}`}>
              {game.home_score}
            </span>
          )}
        </div>

        {/* Action hint for unplayed games */}
        {!simulated && isMyGame && (
          <p className="mt-1.5 text-center text-[10px] font-medium text-[var(--accent-blue)] opacity-70 group-hover:opacity-100 transition-opacity">
            Tap to prepare
          </p>
        )}
      </div>
    </Card>
  );
}
