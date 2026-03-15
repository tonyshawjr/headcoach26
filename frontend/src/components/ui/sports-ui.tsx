/**
 * Sports UI Component System
 * ==========================
 * Reusable building blocks for a sports-media content area.
 * Designed for ESPN/NFL.com/The Athletic feel — bold, editorial, content-forward.
 *
 * Components:
 *   PageLayout     — Consistent page wrapper with optional hero
 *   PageHeader     — Bold sports-media page header
 *   Section        — Content grouping with section headers
 *   SectionHeader  — Standalone section label
 *   StatCard       — Prominent stat number display
 *   StatRow        — Inline stat key/value pair
 *   DataTable      — Editorial-style table wrapper
 *   PlayerRow      — Player listing row
 *   SportsTabs     — Integrated tab navigation
 *   ActionButton   — Context-appropriate sports buttons
 *   SidePanel      — Sidebar widget wrapper
 *   EmptyBlock     — Empty/no-data state
 */

import React from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { ChevronRight } from 'lucide-react';

/* ================================================================
   PAGE LAYOUT
   Consistent wrapper for every content page.
   Standardizes width, padding, and optional hero section.
   ================================================================ */

interface PageLayoutProps {
  children: React.ReactNode;
  /** Optional full-bleed hero section rendered above the content */
  hero?: React.ReactNode;
  /** Additional className on the content container */
  className?: string;
}

export function PageLayout({ children, hero, className = '' }: PageLayoutProps) {
  return (
    <div className="min-h-[calc(100vh-160px)]">
      {hero && (
        <div className="-mx-4 -mt-6 sm:-mx-6 mb-6">
          {hero}
        </div>
      )}
      <div className={className}>
        {children}
      </div>
    </div>
  );
}

/* ================================================================
   PAGE HEADER
   Bold sports-media page header with accent bar, title, subtitle,
   and optional right-side actions. Feels like a broadcast chyron.
   ================================================================ */

interface PageHeaderProps {
  title: string;
  subtitle?: string;
  /** Accent color for the left bar. Defaults to team primary or accent-blue. */
  accentColor?: string;
  /** Optional icon component rendered before the title */
  icon?: React.ComponentType<{ className?: string; style?: React.CSSProperties }>;
  /** Right-side content (tabs, buttons, filters) */
  actions?: React.ReactNode;
  /** Optional meta line below subtitle (e.g., "Week 6 / Regular Season") */
  meta?: string;
}

export function PageHeader({
  title,
  subtitle,
  accentColor = 'var(--accent-blue)',
  icon: Icon,
  actions,
  meta,
}: PageHeaderProps) {
  return (
    <motion.div
      initial={{ opacity: 0, y: -8 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.3 }}
      className="mb-6"
    >
      <div className="flex items-start justify-between gap-4">
        <div className="flex items-start gap-3 min-w-0">
          {/* Accent bar — the signature sports-media touch */}
          <div
            className="mt-1 h-10 w-1 shrink-0 rounded-full"
            style={{ backgroundColor: accentColor }}
          />
          <div className="min-w-0">
            <div className="flex items-center gap-2.5">
              {Icon && (
                <Icon
                  className="h-5 w-5 shrink-0"
                  style={{ color: accentColor }}
                />
              )}
              <h1 className="font-display text-2xl tracking-tight sm:text-3xl text-[var(--text-primary)]">
                {title}
              </h1>
            </div>
            {subtitle && (
              <p className="mt-0.5 text-sm text-[var(--text-secondary)]">
                {subtitle}
              </p>
            )}
            {meta && (
              <p className="mt-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]">
                {meta}
              </p>
            )}
          </div>
        </div>
        {actions && (
          <div className="shrink-0 flex items-center gap-2">
            {actions}
          </div>
        )}
      </div>
      {/* Bottom rule — subtle editorial separator */}
      <div
        className="mt-4 h-[2px]"
        style={{
          background: `linear-gradient(90deg, ${accentColor}, transparent 60%)`,
        }}
      />
    </motion.div>
  );
}

/* ================================================================
   SECTION
   Groups content blocks with an editorial section header.
   Think of each Section as a "module" on an ESPN page.
   ================================================================ */

interface SectionProps {
  title: string;
  accentColor?: string;
  /** Optional "See All" or action link on the right */
  action?: { label: string; to: string };
  children: React.ReactNode;
  className?: string;
  /** Animation delay in seconds */
  delay?: number;
}

export function Section({
  title,
  accentColor = 'var(--accent-blue)',
  action,
  children,
  className = '',
  delay = 0,
}: SectionProps) {
  return (
    <motion.section
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, delay }}
      className={className}
    >
      <SectionHeader title={title} accentColor={accentColor} action={action} />
      {children}
    </motion.section>
  );
}

/* ================================================================
   SECTION HEADER (standalone)
   Reusable label with accent pip and optional action link.
   ================================================================ */

interface SectionHeaderProps {
  title: string;
  accentColor?: string;
  action?: { label: string; to: string };
}

export function SectionHeader({
  title,
  accentColor = 'var(--accent-blue)',
  action,
}: SectionHeaderProps) {
  return (
    <div className="flex items-center justify-between mb-3">
      <div className="flex items-center gap-2">
        <div
          className="h-4 w-1 rounded-sm"
          style={{ backgroundColor: accentColor }}
        />
        <h2
          className="text-[11px] font-bold uppercase tracking-[0.14em]"
          style={{ color: accentColor }}
        >
          {title}
        </h2>
      </div>
      {action && (
        <Link
          to={action.to}
          className="flex items-center gap-0.5 text-[11px] font-semibold uppercase tracking-wider text-[var(--accent-blue)] hover:opacity-80 transition-opacity"
        >
          {action.label}
          <ChevronRight className="h-3.5 w-3.5" />
        </Link>
      )}
    </div>
  );
}

/* ================================================================
   STAT CARD
   Big, bold stat number with label. The hero stat display.
   Use in a grid of 3-5 across the top of a page.
   ================================================================ */

interface StatCardProps {
  label: string;
  value: string | number;
  /** Optional secondary value (e.g., rank, change) */
  sub?: string;
  /** Accent color for the top border */
  accentColor?: string;
  /** Optional trend indicator */
  trend?: 'up' | 'down' | 'neutral';
}

export function StatCard({
  label,
  value,
  sub,
  accentColor = 'var(--accent-blue)',
  trend,
}: StatCardProps) {
  const trendColor =
    trend === 'up'
      ? 'text-green-500'
      : trend === 'down'
        ? 'text-red-500'
        : 'text-[var(--text-muted)]';

  return (
    <div className="relative overflow-hidden rounded-lg border border-[var(--border)] bg-[var(--bg-surface)]">
      {/* Top accent stripe */}
      <div
        className="h-[3px] w-full"
        style={{ backgroundColor: accentColor }}
      />
      <div className="px-4 py-4">
        <p className="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)] mb-1">
          {label}
        </p>
        <div className="flex items-baseline gap-2">
          <span className="font-stat text-3xl leading-none text-[var(--text-primary)]">
            {value}
          </span>
          {sub && (
            <span className={`text-xs font-medium ${trendColor}`}>
              {trend === 'up' && '+'}{sub}
            </span>
          )}
        </div>
      </div>
    </div>
  );
}

/* ================================================================
   STAT ROW
   Compact inline stat for sidebars and detail panels.
   ================================================================ */

interface StatRowProps {
  icon?: React.ComponentType<{ className?: string; style?: React.CSSProperties }>;
  label: string;
  value: string | number;
  sub?: string;
  color?: string;
}

export function StatRow({
  icon: Icon,
  label,
  value,
  sub,
  color = 'var(--text-muted)',
}: StatRowProps) {
  return (
    <div className="flex items-center justify-between gap-2 py-1">
      <div className="flex items-center gap-2 min-w-0">
        {Icon && <Icon className="h-3.5 w-3.5 shrink-0" style={{ color }} />}
        <span className="text-xs text-[var(--text-secondary)]">{label}</span>
      </div>
      <div className="text-right shrink-0 flex items-baseline gap-1.5">
        <span className="font-stat text-sm text-[var(--text-primary)]">{value}</span>
        {sub && (
          <span className="text-[10px] text-[var(--text-muted)]">{sub}</span>
        )}
      </div>
    </div>
  );
}

/* ================================================================
   DATA TABLE
   Editorial-style table that feels like a sports stats page,
   not an admin CRUD table.
   ================================================================ */

interface DataTableColumn<T> {
  key: string;
  label: string;
  /** Column alignment */
  align?: 'left' | 'center' | 'right';
  /** Width class (e.g., 'w-12', 'w-20') */
  width?: string;
  /** Whether this is a stat/number column — uses font-stat */
  stat?: boolean;
  /** Custom render function */
  render?: (row: T, index: number) => React.ReactNode;
}

interface DataTableProps<T> {
  columns: DataTableColumn<T>[];
  data: T[];
  /** Team accent color for the top bar */
  accentColor?: string;
  /** Callback when a row is clicked */
  onRowClick?: (row: T, index: number) => void;
  /** Key extractor for React list keys */
  rowKey: (row: T) => string | number;
  /** Whether to show alternating row stripes */
  striped?: boolean;
  /** Whether to highlight the top 3 rows (leaderboard style) */
  leaderboard?: boolean;
  /** Custom empty state message */
  emptyMessage?: string;
}

export function DataTable<T>({
  columns,
  data,
  accentColor,
  onRowClick,
  rowKey,
  striped = false,
  leaderboard = false,
  emptyMessage = 'No data available',
}: DataTableProps<T>) {
  return (
    <div className="overflow-hidden rounded-lg border border-[var(--border)] bg-[var(--bg-surface)]">
      {/* Optional accent bar */}
      {accentColor && (
        <div
          className="h-[2px] w-full"
          style={{ background: `linear-gradient(90deg, ${accentColor}, transparent)` }}
        />
      )}
      <div className="overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr className="border-b-2 border-[var(--border)]">
              {columns.map((col) => (
                <th
                  key={col.key}
                  className={`
                    px-4 py-3 text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--text-muted)]
                    ${col.width ?? ''}
                    ${col.align === 'right' ? 'text-right' : col.align === 'center' ? 'text-center' : 'text-left'}
                  `}
                >
                  {col.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data.length === 0 ? (
              <tr>
                <td
                  colSpan={columns.length}
                  className="py-12 text-center text-sm text-[var(--text-muted)]"
                >
                  {emptyMessage}
                </td>
              </tr>
            ) : (
              data.map((row, i) => {
                const isTopThree = leaderboard && i < 3;
                return (
                  <tr
                    key={rowKey(row)}
                    className={`
                      border-b border-[var(--border)] last:border-b-0 transition-colors
                      ${onRowClick ? 'cursor-pointer hover:bg-[var(--bg-elevated)]' : ''}
                      ${striped && i % 2 === 1 ? 'bg-[var(--bg-elevated)]/40' : ''}
                      ${isTopThree ? 'bg-[var(--surface-glow)]' : ''}
                    `}
                    onClick={() => onRowClick?.(row, i)}
                  >
                    {columns.map((col) => (
                      <td
                        key={col.key}
                        className={`
                          px-4 py-3
                          ${col.width ?? ''}
                          ${col.stat ? 'font-stat' : ''}
                          ${col.align === 'right' ? 'text-right' : col.align === 'center' ? 'text-center' : 'text-left'}
                        `}
                      >
                        {col.render
                          ? col.render(row, i)
                          : String((row as Record<string, unknown>)[col.key] ?? '')}
                      </td>
                    ))}
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/* ================================================================
   PLAYER ROW
   A stylized row for player listings — editorial feel with
   position badge, rating pill, and contextual info.
   ================================================================ */

interface PlayerRowProps {
  rank?: number;
  name: string;
  position: string;
  /** Jersey number */
  number?: number;
  rating: number;
  /** Team abbreviation */
  team?: string;
  /** Additional stat to show on the right */
  stat?: { label: string; value: string | number };
  /** Injury text (shows red badge) */
  injury?: string;
  onClick?: () => void;
  /** Accent for the position badge — auto-colored by default */
  accentColor?: string;
}

function ratingTier(r: number) {
  if (r >= 90) return { color: 'text-yellow-400', bg: 'bg-yellow-500/10 border-yellow-500/20' };
  if (r >= 80) return { color: 'text-green-400', bg: 'bg-green-500/10 border-green-500/20' };
  if (r >= 70) return { color: 'text-blue-400', bg: 'bg-blue-500/10 border-blue-500/20' };
  if (r >= 60) return { color: 'text-orange-400', bg: 'bg-orange-500/10 border-orange-500/20' };
  return { color: 'text-red-400', bg: 'bg-red-500/10 border-red-500/20' };
}

export function PlayerRow({
  rank,
  name,
  position,
  number,
  rating,
  team,
  stat,
  injury,
  onClick,
}: PlayerRowProps) {
  const tier = ratingTier(rating);

  return (
    <div
      className={`
        flex items-center gap-3 px-4 py-3 border-b border-[var(--border)] last:border-b-0
        transition-colors
        ${onClick ? 'cursor-pointer hover:bg-[var(--bg-elevated)]' : ''}
      `}
      onClick={onClick}
    >
      {/* Rank number */}
      {rank !== undefined && (
        <span
          className={`
            w-6 text-center font-stat text-xs shrink-0
            ${rank <= 3 ? 'text-[var(--accent-gold)] font-bold' : 'text-[var(--text-muted)]'}
          `}
        >
          {rank}
        </span>
      )}

      {/* Jersey number */}
      {number !== undefined && (
        <span className="w-7 text-center font-stat text-xs text-[var(--text-muted)] shrink-0">
          #{number}
        </span>
      )}

      {/* Position badge */}
      <span className="inline-flex items-center justify-center w-9 h-6 rounded text-[10px] font-bold uppercase bg-[var(--bg-elevated)] text-[var(--text-secondary)] border border-[var(--border)] shrink-0">
        {position}
      </span>

      {/* Name */}
      <span className="text-[13px] font-medium text-[var(--text-primary)] flex-1 min-w-0 truncate">
        {name}
      </span>

      {/* Team abbrev */}
      {team && (
        <span className="text-[11px] text-[var(--text-muted)] shrink-0 hidden sm:block">
          {team}
        </span>
      )}

      {/* Injury badge */}
      {injury && (
        <span className="text-[10px] font-semibold text-red-400 bg-red-500/10 border border-red-500/20 rounded px-1.5 py-0.5 shrink-0">
          {injury}
        </span>
      )}

      {/* Stat value */}
      {stat && (
        <div className="text-right shrink-0 hidden sm:block">
          <span className="font-stat text-sm text-[var(--text-primary)]">{stat.value}</span>
          <span className="ml-1 text-[10px] text-[var(--text-muted)]">{stat.label}</span>
        </div>
      )}

      {/* Rating pill */}
      <span
        className={`font-stat text-sm shrink-0 ${tier.color}`}
      >
        {rating}
      </span>

      {/* Chevron for clickable rows */}
      {onClick && (
        <ChevronRight className="h-3.5 w-3.5 text-[var(--text-muted)] shrink-0" />
      )}
    </div>
  );
}

/* ================================================================
   SPORTS TABS
   Integrated tab navigation that feels part of the content,
   not floating generic pills.
   ================================================================ */

interface SportsTabsProps {
  tabs: Array<{ key: string; label: string; icon?: React.ComponentType<{ className?: string }> }>;
  activeTab: string;
  onChange: (key: string) => void;
  /** Visual variant */
  variant?: 'underline' | 'pills';
  accentColor?: string;
}

export function SportsTabs({
  tabs,
  activeTab,
  onChange,
  variant = 'underline',
  accentColor = 'var(--accent-blue)',
}: SportsTabsProps) {
  if (variant === 'pills') {
    return (
      <div className="flex rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-1">
        {tabs.map((tab) => {
          const isActive = tab.key === activeTab;
          return (
            <button
              key={tab.key}
              onClick={() => onChange(tab.key)}
              className={`
                flex items-center gap-1.5 rounded-md px-3.5 py-2 text-xs font-bold uppercase tracking-[0.1em] transition-all
                ${isActive
                  ? 'text-white shadow-sm'
                  : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'
                }
              `}
              style={isActive ? { backgroundColor: accentColor } : undefined}
            >
              {tab.icon && <tab.icon className="h-3.5 w-3.5" />}
              {tab.label}
            </button>
          );
        })}
      </div>
    );
  }

  // Underline variant (default) — editorial, like ESPN section tabs
  return (
    <div className="flex border-b-2 border-[var(--border)]">
      {tabs.map((tab) => {
        const isActive = tab.key === activeTab;
        return (
          <button
            key={tab.key}
            onClick={() => onChange(tab.key)}
            className={`
              relative flex items-center gap-1.5 px-4 py-2.5 text-xs font-bold uppercase tracking-[0.1em] transition-colors
              ${isActive
                ? 'text-[var(--text-primary)]'
                : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
              }
            `}
          >
            {tab.icon && <tab.icon className="h-3.5 w-3.5" />}
            {tab.label}
            {/* Active indicator bar */}
            {isActive && (
              <motion.div
                layoutId="sports-tab-indicator"
                className="absolute bottom-0 left-0 right-0 h-[3px] rounded-t-full"
                style={{ backgroundColor: accentColor }}
                transition={{ type: 'spring', stiffness: 500, damping: 35 }}
              />
            )}
          </button>
        );
      })}
    </div>
  );
}

/* ================================================================
   ACTION BUTTON
   Sports-context buttons — bolder, more purposeful than generic
   shadcn buttons. Three variants: primary (CTA), secondary, ghost.
   ================================================================ */

interface ActionButtonProps {
  children: React.ReactNode;
  onClick?: () => void;
  /** Button variant */
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
  /** Size */
  size?: 'sm' | 'md' | 'lg';
  disabled?: boolean;
  /** Icon component on the left */
  icon?: React.ComponentType<{ className?: string }>;
  /** Full width */
  fullWidth?: boolean;
  /** Custom accent color (for primary variant) */
  accentColor?: string;
  className?: string;
  type?: 'button' | 'submit';
}

export function ActionButton({
  children,
  onClick,
  variant = 'primary',
  size = 'md',
  disabled = false,
  icon: Icon,
  fullWidth = false,
  accentColor,
  className = '',
  type = 'button',
}: ActionButtonProps) {
  const sizeClasses = {
    sm: 'px-3 py-1.5 text-xs gap-1.5',
    md: 'px-5 py-2.5 text-sm gap-2',
    lg: 'px-6 py-3 text-sm gap-2',
  };

  const baseClasses = `
    inline-flex items-center justify-center font-bold uppercase tracking-[0.08em] rounded-md
    transition-all duration-150 active:scale-[0.97]
    disabled:opacity-50 disabled:cursor-not-allowed disabled:active:scale-100
    ${sizeClasses[size]}
    ${fullWidth ? 'w-full' : ''}
    ${className}
  `;

  const variantClasses = {
    primary: 'text-white shadow-sm hover:shadow-md hover:brightness-110',
    secondary: `
      border-2 border-[var(--border)] bg-[var(--bg-surface)] text-[var(--text-primary)]
      hover:bg-[var(--bg-elevated)] hover:border-[var(--text-muted)]
    `,
    ghost: `
      text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--bg-elevated)]
    `,
    danger: `
      bg-[var(--accent-red)] text-white shadow-sm hover:shadow-md hover:brightness-110
    `,
  };

  const primaryBg = accentColor ?? 'var(--accent-blue)';

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      className={`${baseClasses} ${variantClasses[variant]}`}
      style={variant === 'primary' ? { backgroundColor: primaryBg } : undefined}
    >
      {Icon && <Icon className={size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4'} />}
      {children}
    </button>
  );
}

/* ================================================================
   SIDE PANEL
   Sidebar widget container — used for right-rail content like
   team snapshot, quick links, standings, etc.
   ================================================================ */

interface SidePanelProps {
  title: string;
  accentColor?: string;
  children: React.ReactNode;
  /** Optional footer action */
  action?: { label: string; to: string };
  delay?: number;
}

export function SidePanel({
  title,
  accentColor = 'var(--accent-blue)',
  children,
  action,
  delay = 0,
}: SidePanelProps) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, delay }}
      className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] overflow-hidden"
    >
      {/* Header */}
      <div className="flex items-center gap-2 px-4 py-2.5 border-b border-[var(--border)] bg-[var(--bg-elevated)]/40">
        <div
          className="h-3.5 w-1 rounded-sm"
          style={{ backgroundColor: accentColor }}
        />
        <h3
          className="text-[10px] font-bold uppercase tracking-[0.15em]"
          style={{ color: accentColor }}
        >
          {title}
        </h3>
      </div>
      {/* Body */}
      <div className="p-4">{children}</div>
      {/* Footer action */}
      {action && (
        <div className="px-4 py-2.5 border-t border-[var(--border)]">
          <Link
            to={action.to}
            className="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider text-[var(--accent-blue)] hover:opacity-80 transition-opacity"
          >
            {action.label}
            <ChevronRight className="h-3 w-3" />
          </Link>
        </div>
      )}
    </motion.div>
  );
}

/* ================================================================
   EMPTY BLOCK
   Attractive empty state for when data hasn't loaded yet.
   ================================================================ */

interface EmptyBlockProps {
  icon?: React.ComponentType<{ className?: string }>;
  title: string;
  description?: string;
  action?: { label: string; to?: string; onClick?: () => void };
}

export function EmptyBlock({ icon: Icon, title, description, action }: EmptyBlockProps) {
  return (
    <div className="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] px-6 py-12 text-center">
      {Icon && (
        <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-[var(--bg-elevated)]">
          <Icon className="h-6 w-6 text-[var(--text-muted)]" />
        </div>
      )}
      <h3 className="text-sm font-semibold text-[var(--text-primary)]">{title}</h3>
      {description && (
        <p className="mt-1 text-sm text-[var(--text-secondary)] max-w-sm mx-auto">
          {description}
        </p>
      )}
      {action && (
        <div className="mt-4">
          {action.to ? (
            <Link to={action.to}>
              <ActionButton variant="secondary" size="sm">
                {action.label}
              </ActionButton>
            </Link>
          ) : (
            <ActionButton variant="secondary" size="sm" onClick={action.onClick}>
              {action.label}
            </ActionButton>
          )}
        </div>
      )}
    </div>
  );
}

/* ================================================================
   CONTENT GRID
   Standard 2-column (main + sidebar) and 3-column layouts.
   ================================================================ */

interface ContentGridProps {
  children: React.ReactNode;
  /** Layout variant */
  layout?: 'main-sidebar' | 'three-col' | 'full';
  className?: string;
}

export function ContentGrid({ children, layout = 'main-sidebar', className = '' }: ContentGridProps) {
  const layoutClasses = {
    'main-sidebar': 'grid gap-6 lg:grid-cols-3',
    'three-col': 'grid gap-5 md:grid-cols-2 lg:grid-cols-3',
    'full': '',
  };

  return <div className={`${layoutClasses[layout]} ${className}`}>{children}</div>;
}

/** Main content area (2/3 width in main-sidebar layout) */
export function MainColumn({ children, className = '' }: { children: React.ReactNode; className?: string }) {
  return <div className={`space-y-6 lg:col-span-2 ${className}`}>{children}</div>;
}

/** Sidebar column (1/3 width in main-sidebar layout) */
export function SidebarColumn({ children, className = '' }: { children: React.ReactNode; className?: string }) {
  return <div className={`space-y-5 ${className}`}>{children}</div>;
}

/* ================================================================
   HERO SECTION
   Full-width hero for page tops — matchup, team identity, etc.
   ================================================================ */

interface HeroProps {
  /** Gradient start color (left) */
  colorLeft?: string;
  /** Gradient end color (right) */
  colorRight?: string;
  children: React.ReactNode;
  className?: string;
}

export function Hero({
  colorLeft = 'var(--accent-blue)',
  colorRight = 'var(--bg-surface)',
  children,
  className = '',
}: HeroProps) {
  return (
    <div
      className={`relative overflow-hidden px-4 py-8 sm:px-6 sm:py-10 ${className}`}
      style={{
        background: `linear-gradient(135deg, ${colorLeft}15, ${colorRight})`,
      }}
    >
      {/* Subtle pattern overlay */}
      <div
        className="absolute inset-0 opacity-[0.03]"
        style={{
          backgroundImage: `repeating-linear-gradient(
            45deg,
            transparent,
            transparent 10px,
            currentColor 10px,
            currentColor 11px
          )`,
        }}
      />
      <div className="relative mx-auto max-w-[1400px]">
        {children}
      </div>
    </div>
  );
}

/* ================================================================
   CALLOUT BANNER
   For important messages — like the Coach's Agenda.
   Replaces generic alert components.
   ================================================================ */

interface CalloutBannerProps {
  label: string;
  message: string;
  accentColor?: string;
  children?: React.ReactNode;
}

export function CalloutBanner({
  label,
  message,
  accentColor = 'var(--accent-gold)',
  children,
}: CalloutBannerProps) {
  return (
    <motion.div
      initial={{ opacity: 0, y: -8 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, delay: 0.05 }}
    >
      <div
        className="relative overflow-hidden rounded-lg border px-5 py-4"
        style={{
          borderColor: `color-mix(in srgb, ${accentColor} 20%, transparent)`,
          background: `linear-gradient(135deg, color-mix(in srgb, ${accentColor} 6%, transparent), var(--bg-surface), var(--bg-surface))`,
        }}
      >
        <div
          className="absolute left-0 top-0 h-full w-1"
          style={{ backgroundColor: accentColor }}
        />
        <div className="pl-2">
          <p
            className="text-[10px] font-bold uppercase tracking-[0.15em] mb-1.5"
            style={{ color: accentColor }}
          >
            {label}
          </p>
          <p className="text-sm text-[var(--text-secondary)] mb-3">{message}</p>
          {children}
        </div>
      </div>
    </motion.div>
  );
}

/* ================================================================
   RATING BADGE
   Colored overall rating display.
   ================================================================ */

export function RatingBadge({ rating, size = 'md' }: { rating: number; size?: 'sm' | 'md' | 'lg' }) {
  const tier = ratingTier(rating);
  const sizeClasses = {
    sm: 'text-xs px-1.5 py-0.5',
    md: 'text-sm px-2 py-0.5',
    lg: 'text-lg px-2.5 py-1',
  };

  return (
    <span className={`font-stat ${tier.color} ${tier.bg} border rounded ${sizeClasses[size]}`}>
      {rating}
    </span>
  );
}
