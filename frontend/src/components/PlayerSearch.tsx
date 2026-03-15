import { useState, useEffect, useRef, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Search, Loader2 } from 'lucide-react';
import { playerApi, type SearchResult } from '@/api/client';

export function PlayerSearch() {
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<SearchResult[]>([]);
  const [loading, setLoading] = useState(false);
  const [highlightIndex, setHighlightIndex] = useState(-1);

  const inputRef = useRef<HTMLInputElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const debounceRef = useRef<ReturnType<typeof setTimeout>>(undefined);

  // Open / close helpers
  const openSearch = useCallback(() => {
    setOpen(true);
    setTimeout(() => inputRef.current?.focus(), 0);
  }, []);

  const closeSearch = useCallback(() => {
    setOpen(false);
    setQuery('');
    setResults([]);
    setHighlightIndex(-1);
  }, []);

  // Cmd+K / Ctrl+K keyboard shortcut
  useEffect(() => {
    function handleGlobalKey(e: KeyboardEvent) {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        if (open) {
          closeSearch();
        } else {
          openSearch();
        }
      }
      if (e.key === 'Escape' && open) {
        closeSearch();
      }
    }
    document.addEventListener('keydown', handleGlobalKey);
    return () => document.removeEventListener('keydown', handleGlobalKey);
  }, [open, openSearch, closeSearch]);

  // Close on click outside
  useEffect(() => {
    if (!open) return;
    function handleClickOutside(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        closeSearch();
      }
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [open, closeSearch]);

  // Debounced search
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);

    if (query.length < 2) {
      setResults([]);
      setLoading(false);
      return;
    }

    setLoading(true);
    debounceRef.current = setTimeout(async () => {
      try {
        const data = await playerApi.search(query);
        setResults(data);
        setHighlightIndex(-1);
      } catch {
        setResults([]);
      } finally {
        setLoading(false);
      }
    }, 300);

    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [query]);

  // Navigate to player
  const selectPlayer = useCallback(
    (id: number) => {
      navigate(`/player/${id}`);
      closeSearch();
    },
    [navigate, closeSearch],
  );

  // Keyboard navigation in results
  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setHighlightIndex((i) => Math.min(i + 1, results.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setHighlightIndex((i) => Math.max(i - 1, 0));
    } else if (e.key === 'Enter' && highlightIndex >= 0 && results[highlightIndex]) {
      e.preventDefault();
      selectPlayer(results[highlightIndex].id);
    }
  };

  // OVR badge color
  const ovrColor = (ovr: number) => {
    if (ovr >= 90) return '#22c55e';
    if (ovr >= 80) return '#3b82f6';
    if (ovr >= 70) return '#eab308';
    return '#9ca3af';
  };

  /* ── Collapsed state: compact button with ⌘K hint ── */
  if (!open) {
    return (
      <button
        onClick={openSearch}
        className="hidden h-8 items-center gap-2 rounded border border-white/10 px-3 text-white/40 transition-colors hover:bg-white/5 hover:text-white/70 sm:flex"
        title="Search players (⌘K)"
      >
        <Search className="h-3.5 w-3.5" />
        <span className="text-[11px] font-medium">⌘K</span>
      </button>
    );
  }

  /* ── Expanded state: full search overlay ── */
  return (
    <>
      {/* Backdrop */}
      <div className="fixed inset-0 z-[60] bg-black/40" />

      {/* Search container */}
      <div
        ref={containerRef}
        className="fixed left-1/2 top-[10%] z-[70] w-[90vw] max-w-[520px] -translate-x-1/2"
      >
        <div
          className="overflow-hidden rounded-xl border shadow-2xl"
          style={{
            background: 'var(--bg-surface, #1a1a1a)',
            borderColor: 'var(--border, rgba(255,255,255,0.1))',
          }}
        >
          {/* Input row */}
          <div
            className="flex items-center gap-3 border-b border-[var(--border)] px-4 py-3"
          >
            <Search className="h-5 w-5 shrink-0 text-[var(--text-muted)]" />
            <input
              ref={inputRef}
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder="Search players..."
              className="flex-1 bg-transparent text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] outline-none"
              autoComplete="off"
              spellCheck={false}
            />
            {loading && <Loader2 className="h-4 w-4 animate-spin text-[var(--text-muted)]" />}
            <button
              onClick={closeSearch}
              className="flex h-6 items-center rounded border border-[var(--border)] px-1.5 text-[10px] font-medium text-[var(--text-muted)] transition-colors hover:bg-[var(--bg-elevated)] hover:text-[var(--text-secondary)]"
            >
              ESC
            </button>
          </div>

          {/* Results */}
          {query.length >= 2 && (
            <div className="max-h-[400px] overflow-y-auto">
              {results.length === 0 && !loading && (
                <div className="px-4 py-8 text-center text-sm text-[var(--text-muted)]">
                  No players found for &ldquo;{query}&rdquo;
                </div>
              )}

              {results.map((player, idx) => (
                <button
                  key={player.id}
                  onClick={() => selectPlayer(player.id)}
                  onMouseEnter={() => setHighlightIndex(idx)}
                  className={`flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors ${
                    idx === highlightIndex ? 'bg-[var(--bg-elevated)]' : 'hover:bg-[var(--bg-elevated)]/50'
                  }`}
                >
                  {/* OVR badge */}
                  <div
                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-xs font-bold text-white"
                    style={{ backgroundColor: ovrColor(player.overall_rating) }}
                  >
                    {player.overall_rating}
                  </div>

                  {/* Name + position */}
                  <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-semibold text-[var(--text-primary)]">
                      {player.first_name} {player.last_name}
                    </div>
                    <div className="flex items-center gap-2 text-[11px] text-[var(--text-muted)]">
                      <span className="font-semibold text-[var(--text-secondary)]">{player.position}</span>
                      <span>&middot;</span>
                      <span>Age {player.age}</span>
                    </div>
                  </div>

                  {/* Team */}
                  <div className="shrink-0 text-right">
                    <span className="text-xs font-semibold text-[var(--text-secondary)]">
                      {player.team_abbreviation ?? 'Free Agent'}
                    </span>
                  </div>
                </button>
              ))}
            </div>
          )}

          {/* Footer hint */}
          <div
            className="flex items-center justify-between border-t px-4 py-2"
            style={{ borderColor: 'var(--border, rgba(255,255,255,0.1))' }}
          >
            <span className="text-[10px] text-white/20">
              <kbd className="rounded border border-white/10 px-1 py-0.5 text-[9px]">&uarr;</kbd>{' '}
              <kbd className="rounded border border-white/10 px-1 py-0.5 text-[9px]">&darr;</kbd>{' '}
              navigate{' '}
              <kbd className="rounded border border-white/10 px-1 py-0.5 text-[9px]">Enter</kbd>{' '}
              select
            </span>
            <span className="text-[10px] text-white/20">
              <kbd className="rounded border border-white/10 px-1 py-0.5 text-[9px]">⌘K</kbd>{' '}
              to toggle
            </span>
          </div>
        </div>
      </div>
    </>
  );
}
