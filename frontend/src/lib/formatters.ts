/**
 * Format a snake_case stat key into a human-readable display name.
 */
export function formatStatName(key: string): string {
  const display = key
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());

  // Apply specific overrides for clarity
  const overrides: Record<string, string> = {
    'Bc Vision': 'Ball Carrier Vision',
    'Throw Accuracy Deep': 'Throw Acc. Deep',
    'Throw Accuracy Mid': 'Throw Acc. Mid',
    'Throw Accuracy Short': 'Throw Acc. Short',
    'Pass Block Finesse': 'Pass Block Finesse',
    'Pass Block Power': 'Pass Block Power',
    'Run Block Finesse': 'Run Block Finesse',
    'Run Block Power': 'Run Block Power',
  };

  return overrides[display] ?? display;
}

/**
 * Convert a height in total inches to feet'inches" format.
 */
export function formatHeight(inches: number): string {
  const feet = Math.floor(inches / 12);
  const remaining = inches % 12;
  return `${feet}'${remaining}"`;
}

/**
 * Return a Tailwind text color class based on a 0-99 rating value.
 */
export function ratingColor(rating: number): string {
  if (rating >= 90) return 'text-green-400';
  if (rating >= 80) return 'text-blue-400';
  if (rating >= 70) return 'text-yellow-400';
  return 'text-red-400';
}

/**
 * Return a Tailwind background color class based on a 0-99 rating value.
 * Used for progress bar fills.
 */
export function ratingBgColor(rating: number): string {
  if (rating >= 90) return 'bg-green-500';
  if (rating >= 80) return 'bg-blue-500';
  if (rating >= 70) return 'bg-yellow-500';
  return 'bg-red-500';
}

/**
 * Human-readable label for each rating category.
 */
export const CATEGORY_LABELS: Record<string, string> = {
  physical: 'Physical',
  ball_carrier: 'Ball Carrier',
  receiving: 'Receiving',
  blocking: 'Blocking',
  defense: 'Defense',
  quarterback: 'Quarterback',
  kicking: 'Kicking',
};
