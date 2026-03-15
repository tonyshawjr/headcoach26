/**
 * TeamBadge — replaces emoji logos with a styled abbreviation badge
 * that uses the team's primary/secondary colors from the database.
 *
 * Sizes: xs (16px), sm (24px), md (32px), lg (48px)
 */
interface TeamBadgeProps {
  abbreviation?: string;
  primaryColor?: string;
  secondaryColor?: string;
  size?: 'xs' | 'sm' | 'md' | 'lg';
  className?: string;
}

const sizeMap = {
  xs: { box: 'h-4 w-4 text-[7px]', ring: 1 },
  sm: { box: 'h-6 w-6 text-[9px]', ring: 1 },
  md: { box: 'h-8 w-8 text-[11px]', ring: 2 },
  lg: { box: 'h-12 w-12 text-sm', ring: 2 },
};

export function TeamBadge({
  abbreviation = '??',
  primaryColor = '#2188FF',
  secondaryColor = '#FFFFFF',
  size = 'sm',
  className = '',
}: TeamBadgeProps) {
  const { box } = sizeMap[size];

  return (
    <div
      className={`inline-flex shrink-0 items-center justify-center rounded font-bold uppercase leading-none tracking-tight ${box} ${className}`}
      style={{
        background: `linear-gradient(135deg, ${primaryColor}, ${secondaryColor ?? primaryColor})`,
        color: '#FFFFFF',
        textShadow: '0 1px 2px rgba(0,0,0,0.4)',
        boxShadow: `0 0 0 1px ${primaryColor}40`,
      }}
    >
      {abbreviation.slice(0, 3)}
    </div>
  );
}
