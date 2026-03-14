import { useTicker } from '@/hooks/useApi';
import { useAuthStore } from '@/stores/authStore';
import { motion } from 'framer-motion';

export function BreakingTicker() {
  const league = useAuthStore((s) => s.league);
  const { data: items } = useTicker(league?.id);

  if (!items?.length) return null;

  const text = items.map((t) => t.text).join('  ///  ');
  const doubled = `${text}  ///  ${text}`;

  return (
    <div className="relative h-7 overflow-hidden border-b border-[var(--border)] bg-[var(--accent-red)]">
      <div className="flex h-full items-center">
        <span className="z-10 shrink-0 bg-[#000] px-3 text-xs font-bold uppercase tracking-wider text-white">
          Breaking
        </span>
        <div className="relative flex-1 overflow-hidden">
          <motion.div
            className="flex whitespace-nowrap"
            animate={{ x: ['0%', '-50%'] }}
            transition={{ duration: items.length * 8, repeat: Infinity, ease: 'linear' }}
          >
            <span className="px-4 text-xs font-medium text-white">{doubled}</span>
          </motion.div>
        </div>
      </div>
    </div>
  );
}
