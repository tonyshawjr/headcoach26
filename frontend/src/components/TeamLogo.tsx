/**
 * TeamLogo — shows the SVG mascot logo if it exists,
 * falls back to TeamBadge (abbreviation in colored box) if not.
 *
 * Logos are stored at /images/logos/{ABBREVIATION}.svg
 */

import { useState } from 'react';
import { TeamBadge } from './TeamBadge';

interface TeamLogoProps {
  abbreviation?: string;
  primaryColor?: string;
  secondaryColor?: string;
  size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl';
  className?: string;
}

const sizeMap = {
  xs: 'h-6 w-6',
  sm: 'h-8 w-8',
  md: 'h-12 w-12',
  lg: 'h-16 w-16',
  xl: 'h-24 w-24',
};

export function TeamLogo({
  abbreviation = '??',
  primaryColor,
  secondaryColor,
  size = 'md',
  className = '',
}: TeamLogoProps) {
  const [imgFailed, setImgFailed] = useState(false);
  const src = `/images/logos/${abbreviation}.svg`;

  if (imgFailed) {
    // Map our sizes to TeamBadge sizes
    const badgeSize = size === 'xl' ? 'lg' : size === 'lg' ? 'lg' : size;
    return (
      <TeamBadge
        abbreviation={abbreviation}
        primaryColor={primaryColor}
        secondaryColor={secondaryColor}
        size={badgeSize as 'xs' | 'sm' | 'md' | 'lg'}
        className={className}
      />
    );
  }

  return (
    <img
      src={src}
      alt={abbreviation}
      className={`${sizeMap[size]} object-contain ${className}`}
      onError={() => setImgFailed(true)}
    />
  );
}
