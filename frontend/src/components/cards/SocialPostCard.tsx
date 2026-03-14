import { Card } from '@/components/ui/card';
import { Heart, Repeat2 } from 'lucide-react';
import type { SocialPost } from '@/api/client';

const avatarColors: Record<string, string> = {
  player: 'bg-blue-600',
  fan: 'bg-green-600',
  analyst: 'bg-purple-600',
  team: 'bg-red-600',
};

function formatCount(n: number) {
  if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
  return String(n);
}

export function SocialPostCard({ post }: { post: SocialPost }) {
  return (
    <Card className="border-[var(--border)] bg-[var(--bg-surface)] p-4">
      <div className="flex gap-3">
        <div className={`h-9 w-9 shrink-0 rounded-full ${avatarColors[post.avatar_type] ?? 'bg-gray-600'} flex items-center justify-center text-xs font-bold text-white`}>
          {(post.display_name ?? '?').charAt(0)}
        </div>
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-1.5">
            <span className="text-sm font-semibold">{post.display_name}</span>
            <span className="text-xs text-[var(--text-muted)]">{post.handle}</span>
          </div>
          <p className="mt-1 text-sm leading-relaxed">{post.body}</p>
          <div className="mt-2 flex gap-4 text-xs text-[var(--text-muted)]">
            <span className="flex items-center gap-1">
              <Heart className="h-3 w-3" /> {formatCount(post.likes ?? 0)}
            </span>
            <span className="flex items-center gap-1">
              <Repeat2 className="h-3 w-3" /> {formatCount(post.reposts ?? 0)}
            </span>
          </div>
        </div>
      </div>
    </Card>
  );
}
