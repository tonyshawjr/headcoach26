import { useOwnerOffice } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { motion } from 'framer-motion';
import {
  Building2, Shield, Newspaper, TrendingUp,
  TrendingDown, Clock, Users,
} from 'lucide-react';
import {
  RadialBarChart, RadialBar, PolarAngleAxis,
} from 'recharts';
import {
  PageLayout, PageHeader, Section, ContentGrid,
  SidePanel, EmptyBlock,
} from '@/components/ui/sports-ui';

// --- Influence Zone Helpers ---

interface InfluenceZone {
  label: string;
  color: string;
  bgColor: string;
  borderColor: string;
}

function getInfluenceZone(value: number): InfluenceZone {
  if (value >= 80) return { label: 'Elite', color: '#2188FF', bgColor: 'rgba(33,136,255,0.12)', borderColor: 'rgba(33,136,255,0.3)' };
  if (value >= 60) return { label: 'Stable', color: '#22C55E', bgColor: 'rgba(34,197,94,0.12)', borderColor: 'rgba(34,197,94,0.3)' };
  if (value >= 40) return { label: 'Neutral', color: '#D4A017', bgColor: 'rgba(212,160,23,0.12)', borderColor: 'rgba(212,160,23,0.3)' };
  if (value >= 20) return { label: 'Warning', color: '#F97316', bgColor: 'rgba(249,115,22,0.12)', borderColor: 'rgba(249,115,22,0.3)' };
  return { label: 'Danger', color: '#E3342F', bgColor: 'rgba(227,52,47,0.12)', borderColor: 'rgba(227,52,47,0.3)' };
}

function getSecurityColor(value: number): string {
  if (value >= 70) return '#22C55E';
  if (value >= 40) return '#D4A017';
  return '#E3342F';
}

function getMediaColor(value: number): string {
  if (value >= 70) return '#2188FF';
  if (value >= 40) return '#D4A017';
  return '#E3342F';
}

function getMoraleColor(value: number): string {
  if (value >= 70) return '#22C55E';
  if (value >= 40) return '#D4A017';
  return '#E3342F';
}

// --- Radial Gauge Component ---

function InfluenceGauge({ value }: { value: number }) {
  const zone = getInfluenceZone(value);
  const data = [{ value, fill: zone.color }];

  return (
    <div className="relative flex flex-col items-center">
      <RadialBarChart
        width={220}
        height={220}
        innerRadius={80}
        outerRadius={105}
        data={data}
        startAngle={225}
        endAngle={-45}
        barSize={18}
      >
        <PolarAngleAxis type="number" domain={[0, 100]} angleAxisId={0} tick={false} />
        <RadialBar
          dataKey="value"
          cornerRadius={10}
          background={{ fill: 'rgba(48,54,61,0.5)' }}
          animationDuration={1200}
        />
      </RadialBarChart>
      {/* Center label */}
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className="font-display text-4xl" style={{ color: zone.color }}>
          {value}
        </span>
        <span className="text-xs font-medium uppercase tracking-wider" style={{ color: zone.color }}>
          {zone.label}
        </span>
      </div>
    </div>
  );
}

// --- Stat Bar Component ---

function StatBar({ label, value, icon: Icon, color }: {
  label: string;
  value: number;
  icon: React.ElementType;
  color: string;
}) {
  return (
    <div className="space-y-1.5">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Icon className="h-4 w-4" style={{ color }} />
          <span className="text-sm font-medium text-[var(--text-secondary)]">{label}</span>
        </div>
        <span className="font-display text-lg" style={{ color }}>{value}</span>
      </div>
      <div className="h-2 w-full overflow-hidden rounded-full bg-[var(--bg-primary)]">
        <motion.div
          className="h-full rounded-full"
          style={{ backgroundColor: color }}
          initial={{ width: 0 }}
          animate={{ width: `${value}%` }}
          transition={{ duration: 0.8, ease: 'easeOut' }}
        />
      </div>
    </div>
  );
}

// --- Zone Legend ---

function ZoneLegend() {
  const zones = [
    { range: '80-100', label: 'Elite', color: '#2188FF' },
    { range: '60-79', label: 'Stable', color: '#22C55E' },
    { range: '40-59', label: 'Neutral', color: '#D4A017' },
    { range: '20-39', label: 'Warning', color: '#F97316' },
    { range: '0-19', label: 'Danger', color: '#E3342F' },
  ];

  return (
    <div className="flex flex-wrap gap-3 mt-4">
      {zones.map((z) => (
        <div key={z.label} className="flex items-center gap-1.5">
          <div className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: z.color }} />
          <span className="text-[10px] text-[var(--text-muted)]">{z.range} {z.label}</span>
        </div>
      ))}
    </div>
  );
}

// --- Main Page ---

export default function OwnerOffice() {
  const { data, isLoading } = useOwnerOffice();
  const coach = useAuthStore((s) => s.coach);
  const team = useAuthStore((s) => s.team);

  // Use API data, fallback to auth store values
  const influence = data?.influence ?? coach?.influence ?? 50;
  const jobSecurity = data?.job_security ?? coach?.job_security ?? 50;
  const mediaRating = data?.media_rating ?? coach?.media_rating ?? 50;
  const contractYears = data?.contract_years ?? coach?.contract_years ?? 3;
  const contractSalary = data?.contract_salary ?? 0;
  const ownerMessage = data?.owner_message ?? 'The owner has not shared any messages yet.';
  const expectations = data?.expectations ?? 'Meet season expectations and maintain team morale.';
  const recentChanges = data?.recent_changes ?? [];
  const morale = data?.morale ?? team?.morale ?? 50;

  const zone = getInfluenceZone(influence);

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="text-center">
          <Building2 className="mx-auto h-8 w-8 animate-pulse text-[var(--accent-blue)]" />
          <p className="mt-2 text-sm text-[var(--text-secondary)]">Loading owner office...</p>
        </div>
      </div>
    );
  }

  return (
    <PageLayout>
      <PageHeader
        title="Owner's Office"
        subtitle={`${coach?.name} \u2014 ${team?.city} ${team?.name}`}
        icon={Building2}
        accentColor="var(--accent-blue)"
      />

      {/* Top Row: Influence Gauge + Stats + Messages */}
      <ContentGrid layout="three-col" className="mb-6">
        {/* Influence Meter -- Large centerpiece */}
        <SidePanel title="Coaching Influence" accentColor={zone.color} delay={0.1}>
          <div className="flex flex-col items-center">
            <InfluenceGauge value={influence} />
            <ZoneLegend />
          </div>
        </SidePanel>

        {/* Stats Column */}
        <div className="space-y-4">
          <SidePanel title="Performance Metrics" accentColor="var(--accent-blue)" delay={0.2}>
            <div className="space-y-5">
              <StatBar
                label="Job Security"
                value={jobSecurity}
                icon={Shield}
                color={getSecurityColor(jobSecurity)}
              />
              <StatBar
                label="Media Rating"
                value={mediaRating}
                icon={Newspaper}
                color={getMediaColor(mediaRating)}
              />
              <StatBar
                label="Team Morale"
                value={morale}
                icon={Users}
                color={getMoraleColor(morale)}
              />
            </div>
          </SidePanel>

          {/* Contract Card */}
          <SidePanel title="Contract" accentColor="var(--accent-gold)" delay={0.25}>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <p className="text-xs text-[var(--text-muted)]">Years Remaining</p>
                <p className="font-display text-2xl text-[var(--accent-gold)]">
                  {contractYears}
                </p>
              </div>
              <div>
                <p className="text-xs text-[var(--text-muted)]">Salary</p>
                <p className="font-display text-2xl text-[var(--text-primary)]">
                  {contractSalary > 0
                    ? `$${(contractSalary / 1_000_000).toFixed(1)}M`
                    : '--'}
                </p>
              </div>
            </div>
          </SidePanel>
        </div>

        {/* Owner Message & Expectations */}
        <div className="space-y-4">
          <SidePanel title="Owner's Message" accentColor="var(--accent-blue)" delay={0.3}>
            <div className="rounded-lg bg-[var(--bg-primary)] p-4 border border-[var(--border)]">
              <p className="text-sm leading-relaxed text-[var(--text-secondary)] italic">
                &ldquo;{ownerMessage}&rdquo;
              </p>
            </div>
          </SidePanel>

          <SidePanel title="Expectations" accentColor="var(--accent-red)" delay={0.35}>
            <p className="text-sm leading-relaxed text-[var(--text-secondary)]">
              {expectations}
            </p>
          </SidePanel>
        </div>
      </ContentGrid>

      {/* Influence Timeline */}
      <Section title="Recent Influence Changes" accentColor="var(--accent-blue)" delay={0.4}>
        {recentChanges.length > 0 ? (
          <div className="space-y-2">
            {recentChanges.map((change, i) => {
              const isPositive = change.amount > 0;
              const color = isPositive ? '#22C55E' : '#E3342F';
              const Icon = isPositive ? TrendingUp : TrendingDown;

              return (
                <motion.div
                  key={`${change.week}-${change.type}-${i}`}
                  initial={{ opacity: 0, x: -10 }}
                  animate={{ opacity: 1, x: 0 }}
                  transition={{ duration: 0.3, delay: 0.05 * i }}
                  className="flex items-center gap-3 rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] px-4 py-3"
                >
                  <div
                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                    style={{ backgroundColor: `${color}15` }}
                  >
                    <Icon className="h-4 w-4" style={{ color }} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium truncate">{change.reason}</p>
                    <div className="flex items-center gap-2 mt-0.5">
                      <span className="inline-flex items-center rounded border border-[var(--border)] px-1.5 py-0.5 text-[10px] font-medium text-[var(--text-secondary)]">
                        Week {change.week}
                      </span>
                      <span className="text-[10px] text-[var(--text-muted)] capitalize">
                        {change.type.replace(/_/g, ' ')}
                      </span>
                    </div>
                  </div>
                  <span
                    className="font-display text-lg shrink-0"
                    style={{ color }}
                  >
                    {isPositive ? '+' : ''}{change.amount}
                  </span>
                </motion.div>
              );
            })}
          </div>
        ) : (
          <EmptyBlock
            icon={Clock}
            title="No influence changes recorded yet"
            description="Play games, hold press conferences, and manage your team to see changes here."
          />
        )}
      </Section>
    </PageLayout>
  );
}
