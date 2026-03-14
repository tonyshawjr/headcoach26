import { useCoachingStaff, useAvailableStaff, useHireStaff, useFireStaff } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { motion } from 'framer-motion';
import { ClipboardList, UserPlus, UserMinus, TrendingUp, DollarSign, Zap } from 'lucide-react';
import { toast } from 'sonner';
import type { StaffMember } from '@/api/client';

function ratingColor(r: number) {
  if (r >= 85) return 'text-green-400';
  if (r >= 75) return 'text-blue-400';
  if (r >= 65) return 'text-yellow-400';
  return 'text-red-400';
}

function roleBadge(role: string) {
  const map: Record<string, string> = {
    'Offensive Coordinator': 'bg-blue-500/20 text-blue-400 border-blue-500/30',
    'Defensive Coordinator': 'bg-red-500/20 text-red-400 border-red-500/30',
    'Special Teams Coach': 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30',
    'Quarterbacks Coach': 'bg-blue-500/20 text-blue-400 border-blue-500/30',
    'Strength Coach': 'bg-green-500/20 text-green-400 border-green-500/30',
    'Scout': 'bg-purple-500/20 text-purple-400 border-purple-500/30',
  };
  return map[role] ?? 'bg-gray-500/20 text-gray-400 border-gray-500/30';
}

function formatSalary(amount: number) {
  if (amount >= 1_000_000) return `$${(amount / 1_000_000).toFixed(1)}M`;
  if (amount >= 1_000) return `$${(amount / 1_000).toFixed(0)}K`;
  return `$${amount}`;
}

function BonusSummaryCard({ staff }: { staff: StaffMember[] }) {
  const bonuses = staff.reduce<Record<string, number>>((acc, s) => {
    if (s.bonus_type && s.bonus_value) {
      acc[s.bonus_type] = (acc[s.bonus_type] || 0) + s.bonus_value;
    }
    return acc;
  }, {});

  const totalSalary = staff.reduce((sum, s) => sum + s.salary, 0);

  if (Object.keys(bonuses).length === 0 && totalSalary === 0) return null;

  return (
    <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
      <CardHeader className="pb-2">
        <CardTitle className="font-display text-base flex items-center gap-2">
          <Zap className="h-4 w-4 text-[var(--accent-gold)]" /> Staff Bonuses
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {Object.entries(bonuses).map(([type, value]) => (
          <div key={type} className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <TrendingUp className="h-3.5 w-3.5 text-green-400" />
              <span className="text-sm capitalize text-[var(--text-secondary)]">
                {type.replace(/_/g, ' ')}
              </span>
            </div>
            <span className="font-mono text-sm font-semibold text-green-400">
              +{value}%
            </span>
          </div>
        ))}
        <Separator />
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <DollarSign className="h-3.5 w-3.5 text-[var(--accent-gold)]" />
            <span className="text-sm text-[var(--text-secondary)]">Total Staff Salary</span>
          </div>
          <span className="font-mono text-sm font-semibold text-[var(--accent-gold)]">
            {formatSalary(totalSalary)}
          </span>
        </div>
      </CardContent>
    </Card>
  );
}

function StaffTable({ members, actionLabel, actionIcon: ActionIcon, onAction, actionVariant, isActing }: {
  members: StaffMember[];
  actionLabel: string;
  actionIcon: React.ElementType;
  onAction: (id: number) => void;
  actionVariant?: 'default' | 'outline' | 'destructive';
  isActing: boolean;
}) {
  return (
    <Card className="border-[var(--border)] bg-[var(--bg-surface)]">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Name</TableHead>
            <TableHead>Role</TableHead>
            <TableHead>Specialty</TableHead>
            <TableHead>Rating</TableHead>
            <TableHead>Salary</TableHead>
            <TableHead>Bonus</TableHead>
            <TableHead className="w-24"></TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {members.length === 0 ? (
            <TableRow>
              <TableCell colSpan={7} className="text-center py-8 text-[var(--text-muted)]">
                No staff members
              </TableCell>
            </TableRow>
          ) : (
            members.map((member, i) => (
              <motion.tr
                key={member.id}
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                transition={{ delay: i * 0.03 }}
                className="border-b border-[var(--border)] hover:bg-[var(--bg-elevated)]"
              >
                <TableCell className="font-medium">{member.name}</TableCell>
                <TableCell>
                  <Badge variant="outline" className={`text-[10px] ${roleBadge(member.role)}`}>
                    {member.role}
                  </Badge>
                </TableCell>
                <TableCell className="text-sm text-[var(--text-secondary)]">
                  {member.specialty}
                </TableCell>
                <TableCell className={`font-mono font-semibold ${ratingColor(member.rating)}`}>
                  {member.rating}
                </TableCell>
                <TableCell className="font-mono text-sm">
                  {formatSalary(member.salary)}
                </TableCell>
                <TableCell>
                  {member.bonus_type && member.bonus_value ? (
                    <span className="text-xs text-green-400">
                      +{member.bonus_value}% {member.bonus_type.replace(/_/g, ' ')}
                    </span>
                  ) : (
                    <span className="text-xs text-[var(--text-muted)]">--</span>
                  )}
                </TableCell>
                <TableCell>
                  <Button
                    size="sm"
                    variant={actionVariant ?? 'outline'}
                    className="h-7 text-xs"
                    onClick={() => onAction(member.id)}
                    disabled={isActing}
                  >
                    <ActionIcon className="mr-1 h-3 w-3" /> {actionLabel}
                  </Button>
                </TableCell>
              </motion.tr>
            ))
          )}
        </TableBody>
      </Table>
    </Card>
  );
}

export default function CoachingStaff() {
  const coach = useAuthStore((s) => s.coach);
  const team = useAuthStore((s) => s.team);
  const { data: staff, isLoading } = useCoachingStaff();
  const { data: available, isLoading: availLoading } = useAvailableStaff();
  const hireMut = useHireStaff();
  const fireMut = useFireStaff();

  function handleHire(id: number) {
    hireMut.mutate(id, {
      onSuccess: () => toast.success('Coach hired!'),
      onError: (err) => toast.error(err.message),
    });
  }

  function handleFire(id: number) {
    fireMut.mutate(id, {
      onSuccess: () => toast.success('Coach released'),
      onError: (err) => toast.error(err.message),
    });
  }

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="text-center">
          <ClipboardList className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
          <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading coaching staff...</p>
        </div>
      </div>
    );
  }

  const myStaff = staff ?? [];

  return (
    <div className="space-y-6">
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
      >
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--accent-blue)]/10">
            <ClipboardList className="h-5 w-5 text-[var(--accent-blue)]" />
          </div>
          <div>
            <h1 className="font-display text-2xl">Coaching Staff</h1>
            <p className="text-sm text-[var(--text-secondary)]">
              {coach?.name} &mdash; {team?.city} {team?.name}
            </p>
          </div>
        </div>
      </motion.div>

      {/* Staff Bonus Summary */}
      <BonusSummaryCard staff={myStaff} />

      <Tabs defaultValue="current">
        <TabsList>
          <TabsTrigger value="current">
            Current Staff ({myStaff.length})
          </TabsTrigger>
          <TabsTrigger value="market">
            Available Market {available && `(${available.length})`}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="current" className="mt-4">
          <StaffTable
            members={myStaff}
            actionLabel="Release"
            actionIcon={UserMinus}
            onAction={handleFire}
            actionVariant="destructive"
            isActing={fireMut.isPending}
          />
        </TabsContent>

        <TabsContent value="market" className="mt-4">
          {availLoading ? (
            <p className="text-sm text-[var(--text-secondary)]">Loading available coaches...</p>
          ) : (
            <StaffTable
              members={available ?? []}
              actionLabel="Hire"
              actionIcon={UserPlus}
              onAction={handleHire}
              isActing={hireMut.isPending}
            />
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
}
