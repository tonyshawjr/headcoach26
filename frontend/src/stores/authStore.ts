import { create } from 'zustand';
import type { User, Coach, Team, League, Season } from '@/api/client';

interface AuthState {
  user: User | null;
  coach: Coach | null;
  team: Team | null;
  league: League | null;
  season: Season | null;
  isAuthenticated: boolean;
  isLoading: boolean;

  setSession: (data: { user: User; coach: Coach; team: Team; league: League; season?: Season }) => void;
  clearSession: () => void;
  setLoading: (v: boolean) => void;
  updateCoach: (patch: Partial<Coach>) => void;
  updateTeam: (patch: Partial<Team>) => void;
  updateLeague: (patch: Partial<League>) => void;
}

export const useAuthStore = create<AuthState>((set) => ({
  user: null,
  coach: null,
  team: null,
  league: null,
  season: null,
  isAuthenticated: false,
  isLoading: true,

  setSession: (data) =>
    set({
      user: data.user,
      coach: data.coach,
      team: data.team,
      league: data.league,
      season: data.season ?? null,
      isAuthenticated: true,
      isLoading: false,
    }),

  clearSession: () =>
    set({
      user: null,
      coach: null,
      team: null,
      league: null,
      season: null,
      isAuthenticated: false,
      isLoading: false,
    }),

  setLoading: (isLoading) => set({ isLoading }),

  updateCoach: (patch) =>
    set((s) => ({ coach: s.coach ? { ...s.coach, ...patch } : null })),

  updateTeam: (patch) =>
    set((s) => ({ team: s.team ? { ...s.team, ...patch } : null })),

  updateLeague: (patch) =>
    set((s) => ({ league: s.league ? { ...s.league, ...patch } : null })),
}));
