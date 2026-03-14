import { useAuthStore } from '@/stores/authStore';
import { useLeaders } from '@/hooks/useApi';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/empty-state';
import { BarChart3 } from 'lucide-react';

const categoryLabels: Record<string, string> = {
  pass_yards: 'Passing Yards',
  rush_yards: 'Rushing Yards',
  rec_yards: 'Receiving Yards',
  pass_tds: 'Passing TDs',
  rush_tds: 'Rushing TDs',
  rec_tds: 'Receiving TDs',
  receptions: 'Receptions',
  tackles: 'Tackles',
  sacks: 'Sacks',
  interceptions_def: 'Interceptions',
};

export default function Leaders() {
  const league = useAuthStore((s) => s.league);
  const { data, isLoading, isError } = useLeaders(league?.id);

  if (isLoading) {
    return (
      <div className="space-y-6">
        <h1 className="font-display text-2xl">League Leaders</h1>
        <div className="grid gap-4 md:grid-cols-2">
          {[1, 2, 3, 4].map((i) => (
            <Card key={i} className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardContent className="p-6">
                <div className="animate-pulse space-y-3">
                  <div className="h-4 w-24 rounded bg-[var(--bg-elevated)]" />
                  <div className="h-3 w-full rounded bg-[var(--bg-elevated)]" />
                  <div className="h-3 w-full rounded bg-[var(--bg-elevated)]" />
                  <div className="h-3 w-3/4 rounded bg-[var(--bg-elevated)]" />
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    );
  }

  if (isError || !data || Object.keys(data).length === 0) {
    return (
      <div className="space-y-6">
        <h1 className="font-display text-2xl">League Leaders</h1>
        <EmptyState
          icon={BarChart3}
          title="No stats available yet"
          description="Play some games to see league leaders in passing, rushing, receiving, and more."
          actionLabel="Go to Schedule"
          actionHref="/schedule"
        />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <h1 className="font-display text-2xl">League Leaders</h1>
      <div className="grid gap-4 md:grid-cols-2">
        {Object.entries(data).map(([cat, catData]) => {
          const label = (catData as any)?.label ?? categoryLabels[cat] ?? cat;
          const players: any[] = Array.isArray(catData) ? catData : (catData as any)?.players ?? [];
          return (
            <Card key={cat} className="border-[var(--border)] bg-[var(--bg-surface)]">
              <CardHeader className="pb-2">
                <CardTitle className="font-display text-sm">{label}</CardTitle>
              </CardHeader>
              <CardContent className="space-y-2">
                {players.map((l, i) => (
                  <div key={l.player_id} className="flex items-center gap-3 text-sm">
                    <span className="w-5 text-center text-xs text-[var(--text-muted)]">{i + 1}</span>
                    <span className="flex-1 truncate">{l.first_name} {l.last_name}</span>
                    <Badge variant="outline" className="text-[10px]">{l.position}</Badge>
                    <span className="text-xs text-[var(--text-muted)]">{l.team}</span>
                    <span className="w-16 text-right font-mono font-semibold">{l.total}</span>
                  </div>
                ))}
                {players.length === 0 && (
                  <p className="text-xs text-[var(--text-muted)]">No data yet.</p>
                )}
              </CardContent>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
