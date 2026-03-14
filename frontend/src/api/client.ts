const BASE = '/api';

async function request<T>(path: string, options?: RequestInit): Promise<T> {
  const res = await fetch(`${BASE}${path}`, {
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', ...options?.headers },
    ...options,
  });

  let data: unknown;
  try {
    data = await res.json();
  } catch {
    if (!res.ok) {
      throw new Error(`Request failed (${res.status})`);
    }
    return undefined as T;
  }

  if (!res.ok) {
    const err = data as Record<string, string>;
    throw new Error(err?.error || err?.message || `Request failed (${res.status})`);
  }

  return data as T;
}

export const api = {
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'POST', body: body ? JSON.stringify(body) : undefined }),
  put: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'PUT', body: body ? JSON.stringify(body) : undefined }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
};

// Auth
export const authApi = {
  login: (username: string, password: string) =>
    api.post<{ user: User; coach: Coach; team: Team; league: League }>('/auth/login', { username, password }),
  logout: () => api.post('/auth/logout'),
  session: () => api.get<{ user: User; coach: Coach; team: Team; league: League; season: Season }>('/auth/session'),
  register: (username: string, email: string, password: string) =>
    api.post<{ user_id: number }>('/auth/register', { username, email, password }),
};

// Leagues
export const leagueApi = {
  list: () => api.get<{ leagues: League[] }>('/leagues').then((r) => r.leagues),
  get: (id: number) => api.get<League>(`/leagues/${id}`),
  advance: (id: number) => api.post<{ success: boolean; week: number; phase: string }>(`/leagues/${id}/advance`),
};

// Teams
export const teamApi = {
  list: (leagueId: number) => api.get<{ conferences: Record<string, Record<string, Team[]>> }>(`/leagues/${leagueId}/teams`),
  get: (id: number) => api.get<Team>(`/teams/${id}`),
  capSpace: (id: number) => api.get<CapInfo>(`/teams/${id}/cap`),
};

// Players
export const playerApi = {
  roster: (teamId: number) => api.get<RosterResponse>(`/teams/${teamId}/players`),
  get: (id: number) => api.get<Player>(`/players/${id}`),
  stats: (id: number) => api.get<PlayerStats>(`/players/${id}/stats`),
  gameLog: (id: number) => api.get<{ games: GameLogEntry[] }>(`/players/${id}/game-log`).then((r) => r.games),
};

// Depth Chart
export const depthChartApi = {
  get: (teamId: number) => api.get<DepthChartData>(`/teams/${teamId}/depth-chart`),
  update: (teamId: number, changes: DepthChartChange[]) =>
    api.put<DepthChartData>(`/teams/${teamId}/depth-chart`, { changes }),
};

// Games
export const gameApi = {
  schedule: (leagueId: number, week?: number, teamId?: number) => {
    const params = new URLSearchParams();
    if (week) params.set('week', String(week));
    if (teamId) params.set('team_id', String(teamId));
    const qs = params.toString();
    return api.get<ScheduleResponse>(`/leagues/${leagueId}/schedule${qs ? `?${qs}` : ''}`);
  },
  get: (id: number) => api.get<Game>(`/games/${id}`),
  boxScore: (id: number) => api.get<BoxScoreData>(`/games/${id}/box-score`),
};

// Game Plan
export const gamePlanApi = {
  get: (gameId: number) => api.get<GamePlanResponse>(`/games/${gameId}/game-plan`),
  submit: (gameId: number, offense: string, defense: string) =>
    api.post<{ success: boolean }>(`/games/${gameId}/game-plan`, { offense, defense }),
};

// Simulation
export const simApi = {
  simulateWeek: (leagueId: number) =>
    api.post<SimResult>(`/leagues/${leagueId}/simulate`),
};

// Standings
export const standingsApi = {
  get: (leagueId: number) => api.get<StandingsData>(`/leagues/${leagueId}/standings`),
  leaders: (leagueId: number) => api.get<{ leaders: LeadersData }>(`/leagues/${leagueId}/leaders`).then((r) => r.leaders),
  powerRankings: (leagueId: number) => api.get<PowerRanking[]>(`/leagues/${leagueId}/power-rankings`),
};

// Press Conference
export const pressConferenceApi = {
  current: () => api.get<PressConference>('/press-conference/current'),
  answer: (id: number, answers: Record<number, number>) =>
    api.post<PressConferenceResult>(`/press-conference/${id}/answer`, { answers }),
  results: (id: number) => api.get<PressConferenceResult>(`/press-conference/${id}/results`),
};

// Articles & Social
export const contentApi = {
  articles: (leagueId: number, params?: { type?: string; week?: number; page?: number }) => {
    const qs = new URLSearchParams();
    if (params?.type) qs.set('type', params.type);
    if (params?.week) qs.set('week', String(params.week));
    if (params?.page) qs.set('page', String(params.page));
    const s = qs.toString();
    return api.get<ArticlesResponse>(`/leagues/${leagueId}/articles${s ? `?${s}` : ''}`);
  },
  article: (id: number) => api.get<{ article: Article }>(`/articles/${id}`).then((r) => r.article),
  social: (leagueId: number, week?: number) => {
    const qs = week ? `?week=${week}` : '';
    return api.get<SocialPost[]>(`/leagues/${leagueId}/social${qs}`);
  },
  ticker: (leagueId: number) => api.get<{ ticker: TickerItem[] }>(`/leagues/${leagueId}/ticker`).then((r) => r.ticker),
};

// Settings
export const settingsApi = {
  get: () => api.get<SettingsData>('/settings'),
  update: (data: Partial<SettingsData>) => api.put<{ success: boolean }>('/settings', data),
};

// Owner Office
export const ownerOfficeApi = {
  get: () => api.get<OwnerOfficeData>('/owner-office'),
};

// --- Types ---
export interface User {
  id: number;
  username: string;
  email: string;
  is_admin: boolean;
}

export interface League {
  id: number;
  name: string;
  slug: string;
  season_year: number;
  current_week: number;
  phase: string;
  settings?: Record<string, unknown>;
}

export interface Season {
  id: number;
  league_id: number;
  year: number;
  is_current: boolean;
}

export interface Team {
  id: number;
  league_id: number;
  city: string;
  name: string;
  abbreviation: string;
  conference: string;
  division: string;
  primary_color: string;
  secondary_color: string;
  logo_emoji?: string;
  overall_rating: number;
  wins: number;
  losses: number;
  ties: number;
  points_for: number;
  points_against: number;
  streak: string;
  morale: number;
}

export interface Coach {
  id: number;
  league_id: number;
  team_id: number;
  user_id: number | null;
  name: string;
  is_human: boolean;
  archetype: string | null;
  influence: number;
  job_security: number;
  media_rating: number;
  contract_years: number;
}

export interface Player {
  id: number;
  team_id: number;
  first_name: string;
  last_name: string;
  position: string;
  age: number;
  overall_rating: number;
  potential: string;
  jersey_number: number;
  status: string;
  positional_ratings?: Record<string, number>;
  team?: Team;
  injury?: Injury | null;
}

export interface Injury {
  id: number;
  player_id: number;
  type: string;
  severity: string;
  weeks_remaining: number;
}

export interface Game {
  id: number;
  league_id: number;
  season_id: number;
  week: number;
  home_team_id: number;
  away_team_id: number;
  home_score: number | null;
  away_score: number | null;
  is_simulated: boolean;
  weather: string;
  turning_point: string | null;
  home_team?: Team;
  away_team?: Team;
}

export interface RosterResponse {
  active: Player[];
  practice_squad: Player[];
  counts: Record<string, number>;
}

export interface DepthChartData {
  [position: string]: { slot: number; player_id: number; name: string; position: string; overall_rating: number }[];
}

export interface DepthChartChange {
  position_group: string;
  slot: number;
  player_id: number;
}

export interface ScheduleResponse {
  [week: string]: Game[];
}

export interface BoxScoreData {
  game: Game;
  home: { players: BoxScorePlayer[]; totals: TeamTotals };
  away: { players: BoxScorePlayer[]; totals: TeamTotals };
}

export interface BoxScorePlayer {
  id: number;
  name: string;
  position: string;
  [stat: string]: string | number;
}

export interface TeamTotals {
  pass_yards: number;
  rush_yards: number;
  total_yards: number;
  [key: string]: number;
}

export interface GamePlanResponse {
  my_plan: { offense: string; defense: string } | null;
  opponent_plan?: { offense: string; defense: string } | null;
  schemes: { offense: string[]; defense: string[] };
  is_home: boolean;
}

export interface SimResult {
  success: boolean;
  week: number;
  results: { game_id: number; home: string; away: string; home_score: number; away_score: number }[];
}

export interface StandingsData {
  divisions: Record<string, Record<string, StandingsTeam[]>>;
  conferences: Record<string, StandingsTeam[]>;
}

export interface StandingsTeam extends Team {
  win_pct: number;
  point_diff: number;
}

export interface LeadersData {
  [category: string]: { player_id: number; name: string; team: string; position: string; value: number }[];
}

export interface PowerRanking {
  rank: number;
  team: Team;
  power_score: number;
}

export interface PressConference {
  id: number;
  questions: PressQuestion[];
  week: number;
  type: string;
}

export interface PressQuestion {
  question: string;
  answers: { text: string; tone: string; influence?: number; morale?: number; media?: number }[];
  topic?: string;
}

export interface PressConferenceResult {
  influence_change: number;
  morale_change: number;
  media_change: number;
  answers?: { question: string; answer: string; tone: string }[];
}

export interface Article {
  id: number;
  headline: string;
  body: string;
  type: string;
  author_name: string;
  week: number;
  published_at: string;
}

export interface ArticlesResponse {
  articles: Article[];
  total: number;
  page: number;
  pages: number;
}

export interface SocialPost {
  id: number;
  handle: string;
  display_name: string;
  body: string;
  likes: number;
  reposts: number;
  avatar_type: string;
  team_id: number | null;
  posted_at: string;
}

export interface TickerItem {
  id: number;
  text: string;
  type: string;
  week: number;
}

export interface CapInfo {
  total_cap: number;
  cap_used: number;
  cap_remaining: number;
  contracts: { player_name: string; cap_hit: number; years: number }[];
}

export interface PlayerStats {
  season: Record<string, number>;
  career: Record<string, number>;
}

export interface GameLogEntry {
  week: number;
  opponent: string;
  result: string;
  score: string;
  stats: Record<string, number>;
}

export interface SettingsData {
  league_settings?: Record<string, unknown>;
  coach?: { name: string; archetype: string };
  is_commissioner?: boolean;
}

export interface OwnerOfficeData {
  influence: number;
  job_security: number;
  media_rating: number;
  contract_years: number;
  contract_salary: number;
  owner_message: string;
  expectations: string;
  recent_changes: { week: number; type: string; amount: number; reason: string }[];
  morale: number;
}

// --- Phase 3: Depth Systems Types ---

export interface Trade {
  id: number;
  league_id: number;
  proposing_team_id: number;
  receiving_team_id: number;
  status: string;
  proposing_team?: Team;
  receiving_team?: Team;
  offered_players: Player[];
  requested_players: Player[];
  offered_picks?: DraftPick[];
  requested_picks?: DraftPick[];
  created_at: string;
  evaluation?: TradeEvaluation;
}

export interface TradeProposal {
  target_team_id: number;
  offering_player_ids: number[];
  requesting_player_ids: number[];
  offering_picks?: number[];
  requesting_picks?: number[];
}

export interface TradeEvaluation {
  fair: boolean;
  offering_value: number;
  requesting_value: number;
  difference: number;
  grade: string;
  summary: string;
}

export interface FreeAgent {
  id: number;
  player_id: number;
  first_name: string;
  last_name: string;
  position: string;
  age: number;
  overall_rating: number;
  potential: string;
  market_value: number;
  asking_salary: number;
  asking_years: number;
  interest_level: string;
}

export interface FaBid {
  id: number;
  player_id: number;
  player_name: string;
  position: string;
  overall_rating: number;
  salary_offer: number;
  years_offer: number;
  status: string;
  created_at: string;
}

export interface DraftClass {
  year: number;
  total_prospects: number;
  top_positions: Record<string, number>;
  strength: string;
}

export interface DraftProspect {
  id: number;
  first_name: string;
  last_name: string;
  position: string;
  college: string;
  age: number;
  projected_round: number;
  projected_pick: number;
  scouted: boolean;
  scouted_overall?: number;
  scouted_range_low?: number;
  scouted_range_high?: number;
  combine_grade?: string;
  is_drafted: boolean;
}

export interface DraftPick {
  id: number;
  round: number;
  pick_number: number;
  team_id: number;
  team_name?: string;
  is_used: boolean;
  selected_prospect_id?: number;
}

export interface ScoutResult {
  prospect_id: number;
  overall_range_low: number;
  overall_range_high: number;
  combine_grade: string;
  strengths: string[];
  weaknesses: string[];
}

export interface DraftResult {
  success: boolean;
  pick: DraftPick;
  prospect: DraftProspect;
  message: string;
}

export interface StaffMember {
  id: number;
  name: string;
  role: string;
  specialty: string;
  rating: number;
  salary: number;
  bonus_type: string;
  bonus_value: number;
  team_id: number | null;
}

export interface LegacyData {
  total_wins: number;
  total_losses: number;
  total_ties: number;
  championships: number;
  playoff_appearances: number;
  legacy_score: number;
  seasons: LegacySeason[];
}

export interface LegacySeason {
  year: number;
  team_name: string;
  wins: number;
  losses: number;
  ties: number;
  playoff_result: string;
  notable_event: string;
}

export interface Award {
  id: number;
  name: string;
  category: string;
  recipient: string;
  season_year: number;
  description: string;
}

// --- Phase 3: Depth Systems API ---

// Trades
export interface FindTradePlayer {
  id: number;
  name: string;
  position: string;
  overall_rating: number;
  age: number;
  trade_value: number;
}

export interface TradePackageSide {
  players: (FindTradePlayer & { is_selected?: boolean; fills_need?: boolean })[];
  picks: { id: number; label: string; round: number; year: number; trade_value: number }[];
  total_value: number;
}

export interface TeamNeed {
  position: string;
  need_score: number;
  roster_count: number;
  ideal_count: number;
  best_overall: number;
}

export interface TradeOpportunity {
  team: {
    id: number;
    city: string;
    name: string;
    abbreviation: string;
    primary_color: string;
    secondary_color: string;
  };
  interest: 'high' | 'medium' | 'low';
  interest_score: number;
  package_type: string;
  you_send: TradePackageSide;
  they_send: TradePackageSide;
  fairness: number;
  reason: string;
  team_mode?: 'contender' | 'competitive' | 'rebuilding';
  gm_note?: string;
}

export interface FindTradeResult {
  player: FindTradePlayer;
  team_needs: TeamNeed[];
  opportunities: TradeOpportunity[];
}

export const tradeApi = {
  list: () => api.get<{ trades: Trade[] }>('/trades').then((r) => r.trades),
  propose: (data: TradeProposal) =>
    api.post<{ trade_id: number; evaluation: TradeEvaluation }>('/trades', data),
  respond: (id: number, action: string) =>
    api.put<{ success: boolean }>(`/trades/${id}/respond`, { action }),
  evaluate: (id: number) => api.get<{ trade_id: number; evaluation: TradeEvaluation }>(`/trades/${id}/evaluate`).then((r) => r.evaluation),
  findOpportunities: (playerId: number) =>
    api.post<FindTradeResult>('/trades/find-opportunities', { player_id: playerId }),
};

// Free Agency
export const freeAgencyApi = {
  list: (position?: string) =>
    api.get<{ free_agents: FreeAgent[] }>(`/free-agents${position ? `?position=${position}` : ''}`).then((r) => r.free_agents),
  bid: (id: number, salary_offer: number, years_offer: number) =>
    api.post(`/free-agents/${id}/bid`, { salary_offer, years_offer }),
  myBids: () => api.get<{ bids: FaBid[] }>('/free-agents/my-bids').then((r) => r.bids),
};

// Draft
export const draftApi = {
  draftClass: () => api.get<{ draft_class: DraftClass }>('/draft/class').then((r) => r.draft_class),
  board: (position?: string) =>
    api.get<{ board: DraftProspect[] }>(`/draft/board${position ? `?position=${position}` : ''}`).then((r) => r.board),
  myPicks: () => api.get<{ picks: DraftPick[] }>('/draft/my-picks').then((r) => r.picks),
  scout: (id: number) => api.post<{ message: string; report: ScoutResult }>(`/draft/scout/${id}`).then((r) => r.report),
  pick: (pickId: number, prospectId: number) =>
    api.post<DraftResult>('/draft/pick', { pick_id: pickId, prospect_id: prospectId }),
};

// Coaching Staff
export const staffApi = {
  get: () => api.get<{ coaching_staff: StaffMember[] }>('/coaching-staff').then((r) => r.coaching_staff),
  available: () => api.get<{ available_coaches: StaffMember[] }>('/coaching-staff/available').then((r) => r.available_coaches),
  hire: (id: number) => api.post<{ success: boolean }>(`/coaching-staff/hire/${id}`),
  fire: (id: number) => api.post<{ success: boolean }>(`/coaching-staff/fire/${id}`),
};

// Legacy
export const legacyApi = {
  get: () => api.get<{ legacy: LegacyData }>('/legacy').then((r) => r.legacy),
  awards: () => api.get<{ awards: Award[] }>('/offseason/awards').then((r) => r.awards),
};

// --- Phase 4: Multiplayer API ---

// Notifications
export const notificationApi = {
  list: (unreadOnly?: boolean) => api.get<{ notifications: unknown[] }>(`/notifications${unreadOnly ? '?unread_only=1' : ''}`).then((r) => r.notifications),
  markRead: (id: number) => api.put(`/notifications/${id}/read`),
  markAllRead: () => api.put('/notifications/read-all'),
  unreadCount: () => api.get<{ unread_count: number }>('/notifications/count').then((r) => ({ count: r.unread_count })),
};

// Invites
export const inviteApi = {
  create: (data: { team_id?: number; expires_hours?: number }) => api.post('/invites', data),
  list: () => api.get<{ invites: unknown[] }>('/invites').then((r) => r.invites),
  claim: (code: string) => api.post('/invites/claim', { code }),
  cancel: (id: number) => api.delete(`/invites/${id}`),
  availableTeams: () => api.get('/invites/available-teams'),
};

// Commissioner
export const commissionerApi = {
  settings: () => api.get<{ settings: Record<string, unknown> }>('/commissioner/settings').then((r) => r.settings),
  updateSettings: (data: Record<string, unknown>) => api.put('/commissioner/settings', data),
  members: () => api.get<{ members: unknown[] }>('/commissioner/members').then((r) => r.members),
  reviewTrade: (id: number, action: string, reason?: string) => api.put(`/commissioner/trades/${id}/review`, { action, reason }),
  forceAdvance: () => api.post('/commissioner/force-advance'),
  submissionStatus: () => api.get<{ submissions: unknown[] }>('/commissioner/submissions').then((r) => r.submissions),
};

// Messages
export const messageApi = {
  list: (channel?: string, before?: number) => api.get<{ messages: unknown[] }>(`/messages?channel=${channel ?? 'general'}${before ? `&before=${before}` : ''}`).then((r) => r.messages),
  post: (body: string, channel?: string) => api.post('/messages', { body, channel: channel ?? 'general' }),
  pin: (id: number) => api.put(`/messages/${id}/pin`),
  remove: (id: number) => api.delete(`/messages/${id}`),
  channels: () => api.get<{ channels: unknown[] }>('/messages/channels').then((r) => r.channels),
};

// --- Phase 5: AI Pack API ---

export const aiApi = {
  status: () => api.get<{ configured: boolean; usage: Array<{ type: string; count: number; total_prompt: number; total_completion: number }> }>('/ai/status'),
  configure: (api_key: string) => api.post('/ai/configure', { api_key }),
  generateRecap: (game_id: number) => api.post('/ai/generate-recap', { game_id }),
  generateFeature: (topic: string, context?: Record<string, unknown>) => api.post('/ai/generate-feature', { topic, context }),
  generateSocial: (week: number, results?: unknown[]) => api.post('/ai/generate-social', { week, results }),
};

export const rosterImportApi = {
  validate: (content: string, format: 'csv' | 'json') => api.post('/roster-import/validate', { content, format }),
  import: (content: string, format: 'csv' | 'json', filename: string) => api.post('/roster-import', { content, format, filename }),
  history: () => api.get('/roster-import/history'),
  importMadden: () => api.post<MaddenImportResult>('/roster-import/madden', {}),
};

export interface MaddenImportResult {
  imported: number;
  skipped: number;
  errors: string[];
  skip_summary: string[];
  message: string;
}

// Coach Career / Team Switching
export interface AvailableTeam {
  id: number;
  city: string;
  name: string;
  abbreviation: string;
  conference: string;
  division: string;
  primary_color: string;
  secondary_color: string;
  overall_rating: number;
  wins: number;
  losses: number;
  ties: number;
  coach_name: string;
  coach_archetype: string;
}

export interface CareerHistory {
  id: number;
  coach_id: number;
  team_id: number;
  league_id: number;
  start_season: number;
  end_season: number | null;
  wins: number;
  losses: number;
  ties: number;
  departure_reason: string | null;
  city: string;
  team_name: string;
  abbreviation: string;
  primary_color: string;
  secondary_color: string;
}

export interface SwitchTeamResult {
  success: boolean;
  message: string;
  new_team_id: number;
  new_coach_id: number;
}

// Franchise Management
export interface SetupTeam {
  index: number;
  city: string;
  name: string;
  abbreviation: string;
  conference: string;
  division: string;
  primary_color: string;
  secondary_color: string;
  logo_emoji: string;
}

export const franchiseApi = {
  teamsConfig: () => api.get<{
    teams: SetupTeam[];
    structure: Record<string, Record<string, SetupTeam[]>>;
    team_count_options: number[];
  }>('/franchise/teams-config'),
  restart: (leagueName: string, teamCount: number, userTeamId: number, coachName: string) =>
    api.post<{ success: boolean; league_id: number; message: string }>('/franchise/restart', {
      league_name: leagueName, team_count: teamCount, user_team_id: userTeamId, coach_name: coachName,
    }),
  generateRoster: (leagueId: number, freeAgentCount?: number) =>
    api.post<{ success: boolean; players_created: number; free_agents_created: number }>('/franchise/generate-roster', {
      league_id: leagueId, free_agent_count: freeAgentCount ?? 150,
    }),
  generateFreeAgents: (leagueId: number, count?: number) =>
    api.post<{ success: boolean; free_agents_created: number }>('/franchise/generate-free-agents', {
      league_id: leagueId, count: count ?? 150,
    }),
};

export const coachCareerApi = {
  availableTeams: () => api.get<AvailableTeam[]>('/coach-career/available-teams'),
  switchTeam: (teamId: number, mode: 'request_release' | 'retire', newCoachName?: string) =>
    api.post<SwitchTeamResult>('/coach-career/switch-team', {
      team_id: teamId,
      mode,
      new_coach_name: newCoachName,
    }),
  history: () => api.get<CareerHistory[]>('/coach-career/history'),
};
