import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  authApi, leagueApi, teamApi, playerApi, depthChartApi,
  gameApi, gamePlanApi, simApi, standingsApi,
  pressConferenceApi, contentApi, ownerOfficeApi,
  tradeApi, freeAgencyApi, draftApi, staffApi, legacyApi,
  notificationApi, inviteApi, commissionerApi, messageApi,
  aiApi, rosterImportApi, coachCareerApi,
} from '@/api/client';
import type { DepthChartChange, TradeProposal } from '@/api/client';
import { useAuthStore } from '@/stores/authStore';

// --- Auth ---
export function useSession() {
  const setSession = useAuthStore((s) => s.setSession);
  const clearSession = useAuthStore((s) => s.clearSession);

  return useQuery({
    queryKey: ['session'],
    queryFn: async () => {
      try {
        const data = await authApi.session();
        setSession(data);
        return data;
      } catch {
        clearSession();
        return null;
      }
    },
    retry: false,
    staleTime: 5 * 60 * 1000,
  });
}

export function useLogin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ username, password }: { username: string; password: string }) =>
      authApi.login(username, password),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['session'] }),
  });
}

export function useLogout() {
  const qc = useQueryClient();
  const clearSession = useAuthStore((s) => s.clearSession);
  return useMutation({
    mutationFn: () => authApi.logout(),
    onSuccess: () => {
      clearSession();
      qc.clear();
    },
  });
}

// --- Teams ---
export function useTeams(leagueId: number | undefined) {
  return useQuery({
    queryKey: ['teams', leagueId],
    queryFn: () => teamApi.list(leagueId!),
    enabled: !!leagueId,
  });
}

export function useTeam(id: number | undefined) {
  return useQuery({
    queryKey: ['team', id],
    queryFn: () => teamApi.get(id!),
    enabled: !!id,
  });
}

// --- Players ---
export function useRoster(teamId: number | undefined) {
  return useQuery({
    queryKey: ['roster', teamId],
    queryFn: () => playerApi.roster(teamId!),
    enabled: !!teamId,
  });
}

export function usePlayer(id: number | undefined) {
  return useQuery({
    queryKey: ['player', id],
    queryFn: () => playerApi.get(id!),
    enabled: !!id,
  });
}

export function usePlayerStats(id: number | undefined) {
  return useQuery({
    queryKey: ['playerStats', id],
    queryFn: () => playerApi.stats(id!),
    enabled: !!id,
  });
}

export function usePlayerGameLog(id: number | undefined) {
  return useQuery({
    queryKey: ['playerGameLog', id],
    queryFn: () => playerApi.gameLog(id!),
    enabled: !!id,
  });
}

// --- Depth Chart ---
export function useDepthChart(teamId: number | undefined) {
  return useQuery({
    queryKey: ['depthChart', teamId],
    queryFn: () => depthChartApi.get(teamId!),
    enabled: !!teamId,
  });
}

export function useUpdateDepthChart(teamId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (changes: DepthChartChange[]) => depthChartApi.update(teamId, changes),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['depthChart', teamId] }),
  });
}

// --- Schedule & Games ---
export function useSchedule(leagueId: number | undefined, week?: number) {
  return useQuery({
    queryKey: ['schedule', leagueId, week],
    queryFn: () => gameApi.schedule(leagueId!, week),
    enabled: !!leagueId,
  });
}

export function useGame(id: number | undefined) {
  return useQuery({
    queryKey: ['game', id],
    queryFn: () => gameApi.get(id!),
    enabled: !!id,
  });
}

export function useBoxScore(id: number | undefined) {
  return useQuery({
    queryKey: ['boxScore', id],
    queryFn: () => gameApi.boxScore(id!),
    enabled: !!id,
  });
}

// --- Game Plan ---
export function useGamePlan(gameId: number | undefined) {
  return useQuery({
    queryKey: ['gamePlan', gameId],
    queryFn: () => gamePlanApi.get(gameId!),
    enabled: !!gameId,
  });
}

export function useSubmitGamePlan(gameId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ offense, defense }: { offense: string; defense: string }) =>
      gamePlanApi.submit(gameId, offense, defense),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['gamePlan', gameId] }),
  });
}

// --- Simulation ---
export function useSimulateWeek(leagueId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => simApi.simulateWeek(leagueId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['schedule'] });
      qc.invalidateQueries({ queryKey: ['standings'] });
      qc.invalidateQueries({ queryKey: ['session'] });
      qc.invalidateQueries({ queryKey: ['roster'] });
      qc.invalidateQueries({ queryKey: ['articles'] });
      qc.invalidateQueries({ queryKey: ['ticker'] });
    },
  });
}

// --- Standings ---
export function useStandings(leagueId: number | undefined) {
  return useQuery({
    queryKey: ['standings', leagueId],
    queryFn: () => standingsApi.get(leagueId!),
    enabled: !!leagueId,
  });
}

export function useLeaders(leagueId: number | undefined) {
  return useQuery({
    queryKey: ['leaders', leagueId],
    queryFn: () => standingsApi.leaders(leagueId!),
    enabled: !!leagueId,
  });
}

export function usePowerRankings(leagueId: number | undefined) {
  return useQuery({
    queryKey: ['powerRankings', leagueId],
    queryFn: () => standingsApi.powerRankings(leagueId!),
    enabled: !!leagueId,
  });
}

// --- Press Conference ---
export function usePressConference() {
  return useQuery({
    queryKey: ['pressConference'],
    queryFn: () => pressConferenceApi.current(),
    retry: false,
  });
}

export function useAnswerPressConference() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, answers }: { id: number; answers: Record<number, number> }) =>
      pressConferenceApi.answer(id, answers),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['pressConference'] }),
  });
}

// --- Content ---
export function useArticles(leagueId: number | undefined, params?: { type?: string; week?: number; page?: number }) {
  return useQuery({
    queryKey: ['articles', leagueId, params],
    queryFn: () => contentApi.articles(leagueId!, params),
    enabled: !!leagueId,
  });
}

export function useArticle(id: number | undefined) {
  return useQuery({
    queryKey: ['article', id],
    queryFn: () => contentApi.article(id!),
    enabled: !!id,
  });
}

export function useSocial(leagueId: number | undefined, week?: number) {
  return useQuery({
    queryKey: ['social', leagueId, week],
    queryFn: () => contentApi.social(leagueId!, week),
    enabled: !!leagueId,
  });
}

export function useTicker(leagueId: number | undefined) {
  return useQuery({
    queryKey: ['ticker', leagueId],
    queryFn: () => contentApi.ticker(leagueId!),
    enabled: !!leagueId,
  });
}

// --- Owner Office ---
export function useOwnerOffice() {
  return useQuery({
    queryKey: ['ownerOffice'],
    queryFn: () => ownerOfficeApi.get(),
  });
}

// --- League ---
export function useAdvanceWeek(leagueId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => leagueApi.advance(leagueId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['session'] });
      qc.invalidateQueries({ queryKey: ['schedule'] });
    },
  });
}

// --- Trades ---
export function useTrades() {
  return useQuery({
    queryKey: ['trades'],
    queryFn: () => tradeApi.list(),
  });
}

export function useProposeTrade() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: TradeProposal) => tradeApi.propose(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['trades'] }),
  });
}

export function useRespondTrade() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, action }: { id: number; action: string }) =>
      tradeApi.respond(id, action),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['trades'] });
      qc.invalidateQueries({ queryKey: ['roster'] });
    },
  });
}

export function useFindTradeOpportunities() {
  return useMutation({
    mutationFn: (playerId: number) => tradeApi.findOpportunities(playerId),
  });
}

export function useEvaluateTrade(id: number | undefined) {
  return useQuery({
    queryKey: ['tradeEvaluation', id],
    queryFn: () => tradeApi.evaluate(id!),
    enabled: !!id,
  });
}

// --- Free Agency ---
export function useFreeAgents(position?: string) {
  return useQuery({
    queryKey: ['freeAgents', position],
    queryFn: () => freeAgencyApi.list(position),
  });
}

export function useBidFreeAgent() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, salary_offer, years_offer }: { id: number; salary_offer: number; years_offer: number }) =>
      freeAgencyApi.bid(id, salary_offer, years_offer),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['freeAgents'] });
      qc.invalidateQueries({ queryKey: ['myBids'] });
    },
  });
}

export function useMyBids() {
  return useQuery({
    queryKey: ['myBids'],
    queryFn: () => freeAgencyApi.myBids(),
  });
}

// --- Draft ---
export function useDraftClass() {
  return useQuery({
    queryKey: ['draftClass'],
    queryFn: () => draftApi.draftClass(),
  });
}

export function useDraftBoard(position?: string) {
  return useQuery({
    queryKey: ['draftBoard', position],
    queryFn: () => draftApi.board(position),
  });
}

export function useMyDraftPicks() {
  return useQuery({
    queryKey: ['myDraftPicks'],
    queryFn: () => draftApi.myPicks(),
  });
}

export function useScoutProspect() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => draftApi.scout(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['draftBoard'] }),
  });
}

export function useDraftPick() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ pickId, prospectId }: { pickId: number; prospectId: number }) =>
      draftApi.pick(pickId, prospectId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['draftBoard'] });
      qc.invalidateQueries({ queryKey: ['myDraftPicks'] });
      qc.invalidateQueries({ queryKey: ['roster'] });
    },
  });
}

// --- Coaching Staff ---
export function useCoachingStaff() {
  return useQuery({
    queryKey: ['coachingStaff'],
    queryFn: () => staffApi.get(),
  });
}

export function useAvailableStaff() {
  return useQuery({
    queryKey: ['availableStaff'],
    queryFn: () => staffApi.available(),
  });
}

export function useHireStaff() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => staffApi.hire(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['coachingStaff'] });
      qc.invalidateQueries({ queryKey: ['availableStaff'] });
    },
  });
}

export function useFireStaff() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => staffApi.fire(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['coachingStaff'] });
      qc.invalidateQueries({ queryKey: ['availableStaff'] });
    },
  });
}

// --- Legacy ---
export function useLegacy() {
  return useQuery({
    queryKey: ['legacy'],
    queryFn: () => legacyApi.get(),
  });
}

export function useAwards() {
  return useQuery({
    queryKey: ['awards'],
    queryFn: () => legacyApi.awards(),
  });
}

// --- Phase 4: Multiplayer Hooks ---

// --- Notifications ---
export function useNotifications(unreadOnly?: boolean) {
  return useQuery({
    queryKey: ['notifications', unreadOnly],
    queryFn: () => notificationApi.list(unreadOnly),
  });
}

export function useUnreadCount() {
  return useQuery({
    queryKey: ['unreadCount'],
    queryFn: () => notificationApi.unreadCount(),
  });
}

export function useMarkNotificationRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => notificationApi.markRead(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['notifications'] });
      qc.invalidateQueries({ queryKey: ['unreadCount'] });
    },
  });
}

export function useMarkAllRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => notificationApi.markAllRead(),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['notifications'] });
      qc.invalidateQueries({ queryKey: ['unreadCount'] });
    },
  });
}

// --- Messages ---
export function useMessages(channel?: string) {
  return useQuery({
    queryKey: ['messages', channel],
    queryFn: () => messageApi.list(channel),
  });
}

export function usePostMessage() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ body, channel }: { body: string; channel?: string }) => messageApi.post(body, channel),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['messages'] }),
  });
}

// --- Commissioner ---
export function useCommissionerSettings() {
  return useQuery({
    queryKey: ['commissionerSettings'],
    queryFn: () => commissionerApi.settings(),
  });
}

export function useLeagueMembers() {
  return useQuery({
    queryKey: ['leagueMembers'],
    queryFn: () => commissionerApi.members(),
  });
}

export function useSubmissionStatus() {
  return useQuery({
    queryKey: ['submissionStatus'],
    queryFn: () => commissionerApi.submissionStatus(),
  });
}

export function useForceAdvance() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => commissionerApi.forceAdvance(),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['session'] });
      qc.invalidateQueries({ queryKey: ['schedule'] });
      qc.invalidateQueries({ queryKey: ['submissionStatus'] });
    },
  });
}

export function useReviewTrade() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, action, reason }: { id: number; action: string; reason?: string }) =>
      commissionerApi.reviewTrade(id, action, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['trades'] });
    },
  });
}

export function useUpdateCommissionerSettings() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: Record<string, unknown>) => commissionerApi.updateSettings(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['commissionerSettings'] }),
  });
}

// --- Invites ---
export function useInvites() {
  return useQuery({
    queryKey: ['invites'],
    queryFn: () => inviteApi.list(),
  });
}

export function useCreateInvite() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: { team_id?: number; expires_hours?: number }) => inviteApi.create(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['invites'] }),
  });
}

export function useClaimInvite() {
  return useMutation({
    mutationFn: (code: string) => inviteApi.claim(code),
  });
}

export function useCancelInvite() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => inviteApi.cancel(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['invites'] }),
  });
}

// --- Phase 5: AI ---
export function useAiStatus() {
  return useQuery({
    queryKey: ['aiStatus'],
    queryFn: () => aiApi.status(),
  });
}

export function useConfigureAi() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (apiKey: string) => aiApi.configure(apiKey),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['aiStatus'] }),
  });
}

export function useGenerateRecap() {
  return useMutation({
    mutationFn: (gameId: number) => aiApi.generateRecap(gameId),
  });
}

export function useGenerateFeature() {
  return useMutation({
    mutationFn: ({ topic, context }: { topic: string; context?: Record<string, unknown> }) =>
      aiApi.generateFeature(topic, context),
  });
}

export function useGenerateSocial() {
  return useMutation({
    mutationFn: ({ week, results }: { week: number; results?: unknown[] }) =>
      aiApi.generateSocial(week, results),
  });
}

// --- Phase 5: Roster Import ---
export function useValidateImport() {
  return useMutation({
    mutationFn: ({ content, format }: { content: string; format: 'csv' | 'json' }) =>
      rosterImportApi.validate(content, format),
  });
}

export function useImportRoster() {
  return useMutation({
    mutationFn: ({ content, format, filename }: { content: string; format: 'csv' | 'json'; filename: string }) =>
      rosterImportApi.import(content, format, filename),
  });
}

export function useImportHistory() {
  return useQuery({
    queryKey: ['importHistory'],
    queryFn: () => rosterImportApi.history(),
  });
}

export function useImportMaddenRoster() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => rosterImportApi.importMadden(),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['roster'] });
      qc.invalidateQueries({ queryKey: ['importHistory'] });
    },
  });
}

// ── Coach Career / Team Switching ─────────────────────────────────────

export function useAvailableTeams() {
  return useQuery({
    queryKey: ['availableTeams'],
    queryFn: () => coachCareerApi.availableTeams(),
  });
}

export function useSwitchTeam() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ teamId, mode, newCoachName }: { teamId: number; mode: 'request_release' | 'retire'; newCoachName?: string }) =>
      coachCareerApi.switchTeam(teamId, mode, newCoachName),
    onSuccess: () => {
      // Team context changes everything — invalidate all queries
      qc.invalidateQueries();
    },
  });
}

export function useCareerHistory() {
  return useQuery({
    queryKey: ['careerHistory'],
    queryFn: () => coachCareerApi.history(),
  });
}
