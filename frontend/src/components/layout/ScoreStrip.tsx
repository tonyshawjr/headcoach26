import { useRef } from 'react';
import { Link } from 'react-router-dom';
import { useSchedule } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { ChevronLeft, ChevronRight } from 'lucide-react';

interface GameTeam {
  abbreviation?: string;
  primary_color?: string;
}

interface Game {
  id: number;
  week: number;
  is_simulated: boolean;
  away_score?: number | null;
  home_score?: number | null;
  away_team?: GameTeam;
  home_team?: GameTeam;
  away_team_id?: number;
  home_team_id?: number;
}

export function ScoreStrip() {
  const league = useAuthStore((s) => s.league);
  const team = useAuthStore((s) => s.team);
  const { data: schedule } = useSchedule(league?.id);
  const scrollRef = useRef<HTMLDivElement>(null);

  const currentWeek = league?.current_week ?? 0;
  const weekGames: Game[] = schedule?.[String(currentWeek)] ?? [];

  if (!weekGames.length) return null;

  const scroll = (dir: number) => {
    scrollRef.current?.scrollBy({ left: dir * 220, behavior: 'smooth' });
  };

  return (
    <div className="bg-[#1a1a1a] border-b border-white/10">
      <div className="mx-auto flex max-w-[1400px] items-center">
        {/* Left scroll arrow */}
        <button
          onClick={() => scroll(-1)}
          className="flex shrink-0 items-center justify-center border-r border-white/10 px-2 py-2 text-white/40 transition-colors hover:bg-white/5 hover:text-white"
          aria-label="Scroll left"
        >
          <ChevronLeft className="h-4 w-4" />
        </button>

        {/* Week label */}
        <div className="hidden shrink-0 border-r border-white/10 px-4 py-2 sm:block">
          <span className="text-[10px] font-bold uppercase tracking-wider text-white/50">
            {league?.phase === 'regular' ? `Week ${currentWeek}` : league?.phase}
          </span>
        </div>

        {/* Scrollable games */}
        <div
          ref={scrollRef}
          className="flex flex-1 items-stretch overflow-x-auto"
          style={{ scrollbarWidth: 'none', msOverflowStyle: 'none' }}
        >
          <style>{`.score-strip-scroll::-webkit-scrollbar { display: none; }`}</style>
          {weekGames.map((game, i) => {
            const isMyGame =
              team && (game.away_team_id === team.id || game.home_team_id === team.id);
            const awayWon = game.is_simulated && (game.away_score ?? 0) > (game.home_score ?? 0);
            const homeWon = game.is_simulated && (game.home_score ?? 0) > (game.away_score ?? 0);

            return (
              <Link
                key={game.id}
                to={game.is_simulated ? `/box-score/${game.id}` : `/game-plan/${game.id}`}
                className={`group relative flex shrink-0 items-center border-r border-white/10 px-4 py-1.5 transition-colors hover:bg-white/5 ${
                  i === 0 ? 'border-l border-white/10' : ''
                }`}
              >
                {/* My game accent */}
                {isMyGame && (
                  <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-[#2188FF]" />
                )}

                {/* Status label */}
                <div className="mr-3 flex flex-col items-center justify-center">
                  <span
                    className={`text-[9px] font-bold uppercase tracking-wide ${
                      game.is_simulated ? 'text-white/40' : 'text-[#ffcc00]'
                    }`}
                  >
                    {game.is_simulated ? 'Final' : `Wk ${game.week}`}
                  </span>
                </div>

                {/* Teams + Scores */}
                <div className="flex flex-col gap-0.5">
                  {/* Away team */}
                  <div className="flex items-center gap-2">
                    <div
                      className="h-2 w-2 rounded-full"
                      style={{ backgroundColor: game.away_team?.primary_color ?? '#666' }}
                    />
                    <span
                      className={`w-8 text-[11px] font-semibold ${
                        game.is_simulated && !awayWon ? 'text-white/40' : 'text-white'
                      }`}
                    >
                      {game.away_team?.abbreviation ?? 'AWY'}
                    </span>
                    <span
                      className={`w-5 text-right font-stat text-xs ${
                        game.is_simulated && !awayWon ? 'text-white/40' : 'text-white'
                      }`}
                    >
                      {game.is_simulated ? game.away_score : ''}
                    </span>
                  </div>
                  {/* Home team */}
                  <div className="flex items-center gap-2">
                    <div
                      className="h-2 w-2 rounded-full"
                      style={{ backgroundColor: game.home_team?.primary_color ?? '#666' }}
                    />
                    <span
                      className={`w-8 text-[11px] font-semibold ${
                        game.is_simulated && !homeWon ? 'text-white/40' : 'text-white'
                      }`}
                    >
                      {game.home_team?.abbreviation ?? 'HME'}
                    </span>
                    <span
                      className={`w-5 text-right font-stat text-xs ${
                        game.is_simulated && !homeWon ? 'text-white/40' : 'text-white'
                      }`}
                    >
                      {game.is_simulated ? game.home_score : ''}
                    </span>
                  </div>
                </div>
              </Link>
            );
          })}
        </div>

        {/* Scroll arrow */}
        <button
          onClick={() => scroll(1)}
          className="flex shrink-0 items-center justify-center border-l border-white/10 px-2 py-2 text-white/40 transition-colors hover:bg-white/5 hover:text-white"
          aria-label="Scroll right"
        >
          <ChevronRight className="h-4 w-4" />
        </button>
      </div>
    </div>
  );
}
