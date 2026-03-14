import { useNotifications, useMarkNotificationRead, useMarkAllRead } from '@/hooks/useApi';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { motion } from 'framer-motion';
import { Bell, Check, ArrowLeftRight, Trophy, Zap } from 'lucide-react';

interface Notification {
  id: number;
  type: string;
  title: string;
  body: string;
  is_read: boolean;
  created_at: string;
}

function typeIcon(type: string) {
  switch (type) {
    case 'trade':
      return <ArrowLeftRight className="h-4 w-4 text-[var(--accent-blue)]" />;
    case 'achievement':
    case 'award':
      return <Trophy className="h-4 w-4 text-yellow-400" />;
    case 'system':
    case 'alert':
      return <Zap className="h-4 w-4 text-orange-400" />;
    default:
      return <Bell className="h-4 w-4 text-[var(--accent-blue)]" />;
  }
}

function groupByDate(notifications: Notification[]): Record<string, Notification[]> {
  const groups: Record<string, Notification[]> = {};
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const yesterday = new Date(today.getTime() - 86400000);

  for (const n of notifications) {
    const d = new Date(n.created_at);
    const nDate = new Date(d.getFullYear(), d.getMonth(), d.getDate());

    let label: string;
    if (nDate.getTime() === today.getTime()) {
      label = 'Today';
    } else if (nDate.getTime() === yesterday.getTime()) {
      label = 'Yesterday';
    } else {
      label = 'Earlier';
    }

    if (!groups[label]) groups[label] = [];
    groups[label].push(n);
  }

  return groups;
}

function formatTime(dateStr: string) {
  const d = new Date(dateStr);
  const now = new Date();
  const diffMs = now.getTime() - d.getTime();
  const diffMins = Math.floor(diffMs / 60000);

  if (diffMins < 1) return 'just now';
  if (diffMins < 60) return `${diffMins}m ago`;

  const diffHours = Math.floor(diffMins / 60);
  if (diffHours < 24) return `${diffHours}h ago`;

  const diffDays = Math.floor(diffHours / 24);
  if (diffDays < 7) return `${diffDays}d ago`;

  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

export default function NotificationsPage() {
  const { data, isLoading } = useNotifications();
  const markReadMut = useMarkNotificationRead();
  const markAllMut = useMarkAllRead();

  const notifications = (data as Notification[] | undefined) ?? [];
  const unreadCount = notifications.filter((n) => !n.is_read).length;
  const grouped = groupByDate(notifications);
  const groupOrder = ['Today', 'Yesterday', 'Earlier'];

  function handleClick(n: Notification) {
    if (!n.is_read) {
      markReadMut.mutate(n.id);
    }
  }

  function handleMarkAll() {
    markAllMut.mutate();
  }

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="text-center">
          <Bell className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
          <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading notifications...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
      >
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--accent-blue)]/10">
              <Bell className="h-5 w-5 text-[var(--accent-blue)]" />
            </div>
            <div>
              <h1 className="font-display text-2xl">Notifications</h1>
              <p className="text-sm text-[var(--text-secondary)]">
                {unreadCount > 0 ? `${unreadCount} unread` : 'All caught up'}
              </p>
            </div>
          </div>
          {unreadCount > 0 && (
            <Button
              variant="outline"
              size="sm"
              onClick={handleMarkAll}
              disabled={markAllMut.isPending}
            >
              <Check className="mr-1 h-3.5 w-3.5" />
              Mark All Read
            </Button>
          )}
        </div>
      </motion.div>

      {/* Notifications */}
      {notifications.length === 0 ? (
        <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
          <CardContent className="flex flex-col items-center justify-center py-16">
            <Bell className="h-10 w-10 text-[var(--text-muted)] mb-3" />
            <p className="text-sm text-[var(--text-secondary)]">No notifications</p>
            <p className="text-xs text-[var(--text-muted)] mt-1">
              You will be notified about trades, results, and league events
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-6">
          {groupOrder.map((groupLabel) => {
            const items = grouped[groupLabel];
            if (!items || items.length === 0) return null;

            return (
              <div key={groupLabel}>
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                  {groupLabel}
                </h3>
                <div className="space-y-2">
                  {items.map((n, i) => (
                    <motion.div
                      key={n.id}
                      initial={{ opacity: 0, x: -8 }}
                      animate={{ opacity: 1, x: 0 }}
                      transition={{ duration: 0.2, delay: i * 0.03 }}
                    >
                      <Card
                        className={`cursor-pointer border-[var(--border)] transition-colors hover:bg-[var(--bg-elevated)] ${
                          n.is_read ? 'bg-[var(--bg-surface)]' : 'bg-[var(--bg-surface)] border-l-2 border-l-[var(--accent-blue)]'
                        }`}
                        onClick={() => handleClick(n)}
                      >
                        <CardContent className="flex items-start gap-3 p-4">
                          {/* Icon */}
                          <div className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${
                            n.is_read ? 'bg-[var(--bg-elevated)]' : 'bg-[var(--accent-blue)]/10'
                          }`}>
                            {typeIcon(n.type)}
                          </div>

                          {/* Content */}
                          <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                              <p className={`text-sm font-semibold ${
                                n.is_read ? 'text-[var(--text-secondary)]' : 'text-[var(--text-primary)]'
                              }`}>
                                {n.title}
                              </p>
                              {!n.is_read && (
                                <Badge variant="outline" className="text-[8px] py-0 bg-[var(--accent-blue)]/10 text-[var(--accent-blue)] border-[var(--accent-blue)]/30">
                                  NEW
                                </Badge>
                              )}
                            </div>
                            <p className="mt-0.5 text-xs text-[var(--text-muted)] line-clamp-2">
                              {n.body}
                            </p>
                          </div>

                          {/* Time */}
                          <span className="shrink-0 text-[10px] text-[var(--text-muted)]">
                            {formatTime(n.created_at)}
                          </span>
                        </CardContent>
                      </Card>
                    </motion.div>
                  ))}
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
