import { Card, CardContent } from '@/components/ui/card';
import type { LucideIcon } from 'lucide-react';

interface StatCardProps {
  title: string;
  value: string | number;
  subtitle?: string;
  icon?: LucideIcon;
  trend?: 'up' | 'down' | 'neutral';
  color?: string;
}

export function StatCard({ title, value, subtitle, icon: Icon, trend, color }: StatCardProps) {
  const trendColor = trend === 'up' ? 'text-green-400' : trend === 'down' ? 'text-red-400' : 'text-[var(--text-secondary)]';
  const accentColor = color || 'var(--accent-blue)';

  return (
    <Card className="relative overflow-hidden border-[var(--border)] bg-[var(--bg-surface)]">
      {/* Top accent line — ESPN lower-third style */}
      <div className="h-[2px] w-full" style={{ background: `linear-gradient(90deg, ${accentColor}, transparent)` }} />
      <CardContent className="p-4">
        <div className="flex items-start justify-between">
          <div className="space-y-1">
            <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]">{title}</p>
            <p className="font-stat text-3xl leading-none" style={{ color: accentColor }}>{value}</p>
            {subtitle && <p className={`text-[11px] font-medium ${trendColor}`}>{subtitle}</p>}
          </div>
          {Icon && (
            <div
              className="flex h-9 w-9 items-center justify-center rounded"
              style={{ backgroundColor: `${accentColor}15` }}
            >
              <Icon className="h-4 w-4" style={{ color: accentColor }} />
            </div>
          )}
        </div>
      </CardContent>
    </Card>
  );
}
