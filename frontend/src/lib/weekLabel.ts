/**
 * Convert a raw week number + phase into a human-readable label.
 *
 * Regular season: "Week 1" through "Week 18"
 * Playoffs: "Wild Card Weekend", "Divisional Round", "Conference Championship", "The Big Game"
 * Offseason: "Offseason"
 */
export function weekLabel(week: number, phase?: string): string {
  if (phase === 'offseason') return 'Offseason';
  if (phase === 'preseason') return 'Preseason';

  // Regular season weeks 1-18
  if (week >= 1 && week <= 18) return `Week ${week}`;

  // Playoff weeks
  if (week === 19) return 'Wild Card Weekend';
  if (week === 20) return 'Divisional Round';
  if (week === 21) return 'Conference Championship';
  if (week === 22) return 'The Big Game';

  // Fallback
  if (week > 22) return 'Offseason';
  return `Week ${week}`;
}

/**
 * Short version for tight spaces (nav buttons, badges)
 */
export function weekLabelShort(week: number, phase?: string): string {
  if (phase === 'offseason') return 'Offseason';
  if (phase === 'preseason') return 'Preseason';

  if (week >= 1 && week <= 18) return `Week ${week}`;
  if (week === 19) return 'Wild Card';
  if (week === 20) return 'Divisional';
  if (week === 21) return 'Conf. Championship';
  if (week === 22) return 'The Big Game';

  if (week > 22) return 'Offseason';
  return `Week ${week}`;
}
