/**
 * PlayerPhoto — Consistent player headshot used across all player lists.
 *
 * Square, no border-radius, fills the cell edge-to-edge.
 * Shows real headshot (.webp) if available, otherwise a generic silhouette placeholder.
 * Use this everywhere players appear in tables, lists, cards, etc.
 */

interface PlayerPhotoProps {
  imageUrl?: string | null;
  firstName?: string;
  lastName?: string;
  /** Height/width in pixels. Defaults to 40. */
  size?: number;
  className?: string;
}

/** Only treat actual photo/image files as real images. Old initial-silhouette SVGs were stored as player_*.svg —
 *  new placeholder headshots from SVGMaker are stored as placeholder_*.svg and should display. */
function isRealPhoto(url: string): boolean {
  if (url.endsWith('.webp') || url.endsWith('.jpg') || url.endsWith('.jpeg') || url.endsWith('.png')) return true;
  // Allow SVGMaker placeholder headshots but not old silhouette SVGs
  if (url.endsWith('.svg') && url.includes('placeholder_')) return true;
  return false;
}

export function PlayerPhoto({
  imageUrl,
  size = 40,
  className = '',
}: PlayerPhotoProps) {
  const hasPhoto = imageUrl && isRealPhoto(imageUrl);

  if (hasPhoto) {
    return (
      <div
        className={`shrink-0 overflow-hidden bg-[var(--bg-elevated)] ${className}`}
        style={{ width: size, height: size, minWidth: size, minHeight: size }}
      >
        <img
          src={imageUrl}
          alt=""
          className="h-full w-full object-cover"
          onError={(e) => {
            // Replace with placeholder on load failure
            const el = e.target as HTMLImageElement;
            const parent = el.parentElement;
            if (parent) {
              el.remove();
              parent.innerHTML = placeholderSvg(size);
            }
          }}
        />
      </div>
    );
  }

  return <Placeholder size={size} className={className} />;
}

function placeholderSvg(size: number): string {
  const iconSize = Math.round(size * 0.55);
  return `<div style="width:${size}px;height:${size}px;display:flex;align-items:center;justify-content:center">
    <svg viewBox="0 0 80 80" style="width:${iconSize}px;height:${iconSize}px;opacity:0.35;color:var(--text-muted)">
      <circle cx="40" cy="28" r="14" fill="currentColor"/>
      <path d="M12 72c0-15.5 12.5-28 28-28s28 12.5 28 28" fill="currentColor"/>
    </svg>
  </div>`;
}

function Placeholder({ size, className = '' }: { size: number; className?: string }) {
  return (
    <div
      className={`shrink-0 flex items-center justify-center bg-[var(--bg-elevated)] ${className}`}
      style={{ width: size, height: size, minWidth: size, minHeight: size }}
    >
      <svg
        viewBox="0 0 80 80"
        className="text-[var(--text-muted)]"
        style={{ width: size * 0.55, height: size * 0.55, opacity: 0.35 }}
      >
        <circle cx="40" cy="28" r="14" fill="currentColor" />
        <path d="M12 72c0-15.5 12.5-28 28-28s28 12.5 28 28" fill="currentColor" />
      </svg>
    </div>
  );
}
