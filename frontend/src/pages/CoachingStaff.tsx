import { useCoachingStaff, useAvailableStaff, useHireStaff, useFireStaff } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { useState } from 'react';
import { ClipboardList, UserPlus, UserMinus, TrendingUp, DollarSign } from 'lucide-react';
import { toast } from 'sonner';
import type { StaffMember } from '@/api/client';
import {
  PageLayout, PageHeader, Section, StatRow, DataTable,
  ActionButton, SidePanel, SportsTabs,
} from '@/components/ui/sports-ui';

function ratingColor(r: number) {
  if (r >= 85) return 'text-green-400';
  if (r >= 75) return 'text-blue-400';
  if (r >= 65) return 'text-yellow-400';
  return 'text-red-400';
}

function roleBadgeClass(role: string) {
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

function BonusSummaryPanel({ staff }: { staff: StaffMember[] }) {
  const bonuses = staff.reduce<Record<string, number>>((acc, s) => {
    if (s.bonus_type && s.bonus_value) {
      acc[s.bonus_type] = (acc[s.bonus_type] || 0) + s.bonus_value;
    }
    return acc;
  }, {});

  const totalSalary = staff.reduce((sum, s) => sum + s.salary, 0);

  if (Object.keys(bonuses).length === 0 && totalSalary === 0) return null;

  return (
    <SidePanel title="Staff Bonuses" accentColor="var(--accent-gold)" delay={0.15}>
      <div className="space-y-3">
        {Object.entries(bonuses).map(([type, value]) => (
          <StatRow
            key={type}
            icon={TrendingUp}
            label={type.replace(/_/g, ' ')}
            value={`+${value}%`}
            color="#22C55E"
          />
        ))}
        <div className="h-px bg-[var(--border)] my-2" />
        <StatRow
          icon={DollarSign}
          label="Total Staff Salary"
          value={formatSalary(totalSalary)}
          color="var(--accent-gold)"
        />
      </div>
    </SidePanel>
  );
}

function StaffDataTable({ members, actionLabel, actionIcon: ActionIcon, onAction, actionVariant, isActing }: {
  members: StaffMember[];
  actionLabel: string;
  actionIcon: React.ComponentType<{ className?: string }>;
  onAction: (id: number) => void;
  actionVariant?: 'primary' | 'secondary' | 'ghost' | 'danger';
  isActing: boolean;
}) {
  const columns = [
    {
      key: 'name',
      label: 'Name',
      render: (row: StaffMember) => (
        <span className="font-medium text-[var(--text-primary)]">{row.name}</span>
      ),
    },
    {
      key: 'role',
      label: 'Role',
      render: (row: StaffMember) => (
        <span className={`inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-medium ${roleBadgeClass(row.role)}`}>
          {row.role}
        </span>
      ),
    },
    {
      key: 'specialty',
      label: 'Specialty',
      render: (row: StaffMember) => (
        <span className="text-sm text-[var(--text-secondary)]">{row.specialty}</span>
      ),
    },
    {
      key: 'rating',
      label: 'Rating',
      stat: true,
      align: 'right' as const,
      render: (row: StaffMember) => (
        <span className={`font-stat text-sm font-semibold ${ratingColor(row.rating)}`}>
          {row.rating}
        </span>
      ),
    },
    {
      key: 'salary',
      label: 'Salary',
      align: 'right' as const,
      stat: true,
      render: (row: StaffMember) => (
        <span className="font-stat text-sm">{formatSalary(row.salary)}</span>
      ),
    },
    {
      key: 'bonus',
      label: 'Bonus',
      render: (row: StaffMember) => {
        if (row.bonus_type && row.bonus_value) {
          return (
            <span className="text-xs text-green-400">
              +{row.bonus_value}% {row.bonus_type.replace(/_/g, ' ')}
            </span>
          );
        }
        return <span className="text-xs text-[var(--text-muted)]">--</span>;
      },
    },
    {
      key: 'action',
      label: '',
      width: 'w-24',
      render: (row: StaffMember) => (
        <ActionButton
          size="sm"
          variant={actionVariant ?? 'secondary'}
          onClick={() => onAction(row.id)}
          disabled={isActing}
          icon={ActionIcon}
        >
          {actionLabel}
        </ActionButton>
      ),
    },
  ];

  return (
    <DataTable<StaffMember>
      columns={columns}
      data={members}
      rowKey={(row) => row.id}
      striped
      emptyMessage="No staff members"
    />
  );
}

export default function CoachingStaff() {
  const coach = useAuthStore((s) => s.coach);
  const team = useAuthStore((s) => s.team);
  const { data: staff, isLoading } = useCoachingStaff();
  const { data: available, isLoading: availLoading } = useAvailableStaff();
  const hireMut = useHireStaff();
  const fireMut = useFireStaff();
  const [activeTab, setActiveTab] = useState('current');

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

  const tabs = [
    { key: 'current', label: `Current Staff (${myStaff.length})` },
    { key: 'market', label: `Available Market${available ? ` (${available.length})` : ''}` },
  ];

  return (
    <PageLayout>
      <PageHeader
        title="Coaching Staff"
        subtitle={`${coach?.name} \u2014 ${team?.city} ${team?.name}`}
        icon={ClipboardList}
        accentColor="var(--accent-blue)"
      />

      {/* Staff Bonus Summary */}
      {myStaff.length > 0 && (
        <div className="mb-6">
          <BonusSummaryPanel staff={myStaff} />
        </div>
      )}

      {/* Tabs */}
      <div className="mb-4">
        <SportsTabs
          tabs={tabs}
          activeTab={activeTab}
          onChange={setActiveTab}
          accentColor="var(--accent-blue)"
        />
      </div>

      {/* Tab Content */}
      {activeTab === 'current' && (
        <Section title="Your Staff" accentColor="var(--accent-blue)" delay={0.1}>
          <StaffDataTable
            members={myStaff}
            actionLabel="Release"
            actionIcon={UserMinus}
            onAction={handleFire}
            actionVariant="danger"
            isActing={fireMut.isPending}
          />
        </Section>
      )}

      {activeTab === 'market' && (
        <Section title="Free Agent Coaches" accentColor="var(--accent-blue)" delay={0.1}>
          {availLoading ? (
            <p className="text-sm text-[var(--text-secondary)]">Loading available coaches...</p>
          ) : (
            <StaffDataTable
              members={available ?? []}
              actionLabel="Hire"
              actionIcon={UserPlus}
              onAction={handleHire}
              actionVariant="primary"
              isActing={hireMut.isPending}
            />
          )}
        </Section>
      )}
    </PageLayout>
  );
}
