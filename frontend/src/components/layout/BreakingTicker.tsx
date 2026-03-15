import { useTicker } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { useNavigate } from 'react-router-dom';

const SEP = '\u00A0\u00A0\u00A0\u00A0\u00A0\u00A0\u00A0\u00A0\u00A0\u00A0\u00A0\u00A0';

function TickerItemContent({ text, onNavigate }: { text: string; onNavigate: (path: string) => void }) {
  const parts = text.split(/(\[(?:player|team):\d+:[^\]]+\])/g);

  return (
    <>
      {parts.map((part, i) => {
        const playerMatch = part.match(/^\[player:(\d+):([^\]]+)\]$/);
        if (playerMatch) {
          return (
            <span
              key={i}
              className="font-bold text-white cursor-pointer hover:underline"
              onClick={() => onNavigate(`/player/${playerMatch[1]}`)}
            >
              {playerMatch[2]}
            </span>
          );
        }

        const teamMatch = part.match(/^\[team:(\d+):([^\]]+)\]$/);
        if (teamMatch) {
          return (
            <span
              key={i}
              className="font-bold text-white cursor-pointer hover:underline"
              onClick={() => onNavigate(`/my-team`)}
            >
              {teamMatch[2]}
            </span>
          );
        }

        const actionMatch = part.match(/^(TRADE|SIGNING|ROSTER|RELEASED|INJURY|BREAKING|UPDATE|WAIVER):\s*/);
        if (actionMatch) {
          return (
            <span key={i}>
              <span className="font-black text-white">{actionMatch[1]}:</span>
              {part.slice(actionMatch[0].length)}
            </span>
          );
        }

        return <span key={i}>{part}</span>;
      })}
    </>
  );
}

export function BreakingTicker() {
  const navigate = useNavigate();
  const league = useAuthStore((s) => s.league);
  const { data: items } = useTicker(league?.id);

  if (!items?.length) return null;

  const duration = Math.max(10, items.length * 2);

  return (
    <>
      <style>{`
        @keyframes tickerScroll {
          0% { transform: translateX(0); }
          100% { transform: translateX(-50%); }
        }
        .ticker-track {
          animation: tickerScroll ${duration}s linear infinite;
        }
        .ticker-track:hover {
          animation-play-state: paused;
        }
      `}</style>
      <div
        className="fixed bottom-0 left-0 right-0 z-50 h-9 overflow-hidden border-t border-white/5"
        style={{
          background: 'linear-gradient(to bottom, #111 0%, #0a0a0a 100%)',
          boxShadow: '0 -2px 10px rgba(0,0,0,0.5)',
        }}
      >
        <div className="flex h-full items-center">
          <span className="z-10 shrink-0 bg-[var(--accent-red)] px-4 text-[10px] font-black uppercase tracking-[0.25em] text-white h-full flex items-center">
            HC26
          </span>
          <div className="relative flex-1 overflow-hidden">
            <div className="ticker-track flex whitespace-nowrap">
              <span
                className="px-4 text-xs tracking-wide"
                style={{
                  color: '#39ff14',
                  textShadow: '0 0 4px rgba(57, 255, 20, 0.5)',
                  fontFamily: "'JetBrains Mono', 'Fira Code', 'SF Mono', monospace",
                }}
              >
                {items.map((item, i) => (
                  <span key={i}>
                    {i > 0 && <span className="opacity-30">{SEP}</span>}
                    <TickerItemContent text={item.text} onNavigate={navigate} />
                  </span>
                ))}
                <span className="opacity-30">{SEP}</span>
                {items.map((item, i) => (
                  <span key={`dup-${i}`}>
                    {i > 0 && <span className="opacity-30">{SEP}</span>}
                    <TickerItemContent text={item.text} onNavigate={navigate} />
                  </span>
                ))}
              </span>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
