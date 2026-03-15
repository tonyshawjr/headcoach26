/**
 * ArticleHeroImage — generates a dynamic background for article hero cards.
 *
 * Combines three approaches:
 * 1. Team-color gradients using primary/secondary colors from the DB
 * 2. SVG sports patterns (field lines, hash marks, geometric shapes)
 * 3. Player photo overlay when a player_id is available
 *
 * Falls back gracefully: player photo → team gradient + pattern → generic gradient
 */

import { useTeam } from '@/hooks/useApi';

interface Props {
  teamId?: number | null;
  playerId?: number | null;
  articleType?: string;
  className?: string;
}

/* Local American football photos — no people (Pexels/Unsplash, free license) */
const stockPhotos = [
  '/images/heroes/field1.jpg', // football on yard lines
  '/images/heroes/field2.jpg', // football on turf close-up
  '/images/heroes/field3.jpg', // football on grass
  '/images/heroes/field5.jpg', // grass-level stadium view
];

/** Pick a consistent photo based on article ID so it doesn't change on re-render */
function pickPhoto(seed: number): string {
  return stockPhotos[Math.abs(seed) % stockPhotos.length];
}

/* Pattern SVGs by article type */
function getPattern(type: string, color1: string, color2: string): string {
  const c1 = encodeURIComponent(color1);
  const c2 = encodeURIComponent(color2);

  // Hash tick marks along edges — like the field sideline markers
  // Short horizontal ticks on left/right edges, spaced evenly
  switch (type) {
    case 'game_recap':
      // Full field: yard lines + edge ticks
      return `url("data:image/svg+xml,%3Csvg width='200' height='60' xmlns='http://www.w3.org/2000/svg'%3E%3Cline x1='0' y1='30' x2='200' y2='30' stroke='${c1}' stroke-width='0.5' opacity='0.08'/%3E%3Cline x1='0' y1='0' x2='12' y2='0' stroke='${c1}' stroke-width='2' opacity='0.15'/%3E%3Cline x1='188' y1='0' x2='200' y2='0' stroke='${c1}' stroke-width='2' opacity='0.15'/%3E%3Cline x1='0' y1='59' x2='12' y2='59' stroke='${c1}' stroke-width='2' opacity='0.15'/%3E%3Cline x1='188' y1='59' x2='200' y2='59' stroke='${c1}' stroke-width='2' opacity='0.15'/%3E%3C/svg%3E")`;

    case 'power_rankings':
      // Edge ticks only — clean ranking look
      return `url("data:image/svg+xml,%3Csvg width='200' height='40' xmlns='http://www.w3.org/2000/svg'%3E%3Cline x1='0' y1='0' x2='10' y2='0' stroke='${c2}' stroke-width='2' opacity='0.12'/%3E%3Cline x1='190' y1='0' x2='200' y2='0' stroke='${c2}' stroke-width='2' opacity='0.12'/%3E%3Cline x1='0' y1='39' x2='10' y2='39' stroke='${c2}' stroke-width='2' opacity='0.12'/%3E%3Cline x1='190' y1='39' x2='200' y2='39' stroke='${c2}' stroke-width='2' opacity='0.12'/%3E%3C/svg%3E")`;

    case 'column':
      // Subtle edge ticks, wider spacing
      return `url("data:image/svg+xml,%3Csvg width='200' height='50' xmlns='http://www.w3.org/2000/svg'%3E%3Cline x1='0' y1='0' x2='8' y2='0' stroke='${c1}' stroke-width='1.5' opacity='0.1'/%3E%3Cline x1='192' y1='0' x2='200' y2='0' stroke='${c1}' stroke-width='1.5' opacity='0.1'/%3E%3Cline x1='0' y1='49' x2='8' y2='49' stroke='${c1}' stroke-width='1.5' opacity='0.1'/%3E%3Cline x1='192' y1='49' x2='200' y2='49' stroke='${c1}' stroke-width='1.5' opacity='0.1'/%3E%3C/svg%3E")`;

    default:
      // Standard hash ticks
      return `url("data:image/svg+xml,%3Csvg width='200' height='45' xmlns='http://www.w3.org/2000/svg'%3E%3Cline x1='0' y1='0' x2='10' y2='0' stroke='${c1}' stroke-width='2' opacity='0.1'/%3E%3Cline x1='190' y1='0' x2='200' y2='0' stroke='${c1}' stroke-width='2' opacity='0.1'/%3E%3Cline x1='0' y1='44' x2='10' y2='44' stroke='${c1}' stroke-width='2' opacity='0.1'/%3E%3Cline x1='190' y1='44' x2='200' y2='44' stroke='${c1}' stroke-width='2' opacity='0.1'/%3E%3C/svg%3E")`;
  }
}

export function ArticleHeroImage({ teamId, articleType = 'game_recap', className = '', articleId }: Omit<Props, 'playerId'> & { articleId?: number }) {
  const { data: teamData } = useTeam(teamId ?? undefined);

  const team = teamData as { primary_color?: string; secondary_color?: string } | undefined;
  const color1 = team?.primary_color ?? '#2563eb';
  const color2 = team?.secondary_color ?? '#1a1a2e';

  const pattern = getPattern(articleType, color1, color2);
  const stockUrl = pickPhoto(articleId ?? 0);

  return (
    <div
      className={`absolute inset-0 ${className}`}
      style={{
        background: `linear-gradient(135deg, ${color1}cc 0%, ${color2}cc 50%, ${color1}66 100%)`,
      }}
    >
      {/* Stock photo background — low opacity, blended with team colors */}
      <img
        src={stockUrl}
        alt=""
        className="absolute inset-0 h-full w-full object-cover opacity-20 mix-blend-luminosity"
        loading="lazy"
        onError={(e) => { (e.target as HTMLImageElement).style.display = 'none'; }}
      />

      {/* SVG pattern overlay */}
      <div
        className="absolute inset-0"
        style={{ backgroundImage: pattern, backgroundRepeat: 'repeat' }}
      />

      {/* Bottom gradient for text readability */}
      <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-black/30 to-transparent" />
    </div>
  );
}
