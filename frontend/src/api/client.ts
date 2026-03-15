const BASE = '/api';

// CSRF token — stored after login/session response
let csrfToken: string | null = null;

export function setCsrfToken(token: string | null) {
  csrfToken = token;
}

async function request<T>(path: string, options?: RequestInit): Promise<T> {
  const method = options?.method ?? 'GET';
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    ...(options?.headers as Record<string, string>),
  };

  // Include CSRF token on state-changing requests
  if (csrfToken && method !== 'GET' && method !== 'HEAD') {
    headers['X-CSRF-Token'] = csrfToken;
  }

  const res = await fetch(`${BASE}${path}`, {
    credentials: 'include',
    headers,
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

  // Auto-capture CSRF token from responses
  if (data && typeof data === 'object' && 'csrf_token' in data) {
    const token = (data as Record<string, unknown>).csrf_token;
    if (typeof token === 'string') {
      csrfToken = token;
    }
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

// Profile
export interface ProfileData {
  user_id: number;
  username: string;
  email: string;
  display_name: string | null;
  avatar_url: string | null;
  avatar_color: string | null;
  bio: string | null;
  is_admin: boolean;
  created_at: string;
  coach_name: string | null;
  coach_id: number | null;
  coach_avatar_url: string | null;
  coaching_philosophy: string | null;
  archetype: string | null;
  team_name: string | null;
  team_abbreviation: string | null;
  team_primary_color: string | null;
}

export interface ProfileUpdate {
  display_name?: string;
  email?: string;
  bio?: string;
  avatar_url?: string;
  avatar_color?: string;
  coach_name?: string;
  coaching_philosophy?: string;
  coach_avatar_url?: string;
  current_password?: string;
  new_password?: string;
}

export const profileApi = {
  get: () => api.get<{ profile: ProfileData }>('/profile').then(r => r.profile),
  update: (data: ProfileUpdate) => api.put<{ message: string }>('/profile', data),
  uploadAvatar: async (file: File): Promise<{ avatar_url: string; message: string }> => {
    const formData = new FormData();
    formData.append('avatar', file);
    const res = await fetch(`${BASE}/profile/avatar`, {
      method: 'POST',
      credentials: 'include',
      body: formData,
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data?.error || 'Upload failed');
    return data;
  },
};

// Leagues
export const leagueApi = {
  list: () => api.get<{ leagues: League[] }>('/leagues').then((r) => r.leagues),
  get: (id: number) => api.get<League>(`/leagues/${id}`),
  advance: (id: number) => api.post<{ success: boolean; week: number; phase: string; message?: string; offseason?: { phase?: string; [key: string]: unknown } }>(`/leagues/${id}/advance`),
};

// Teams
export const teamApi = {
  list: (leagueId: number) => api.get<{ conferences: Record<string, Record<string, Team[]>> }>(`/leagues/${leagueId}/teams`),
  get: (id: number) => api.get<{ team: Team; rankings?: { pass_rank: number; pass_ypg: number; rush_rank: number; rush_ypg: number; total_rank: number; total_ypg: number } }>(`/teams/${id}`).then((r) => ({ ...r.team, rankings: r.rankings })),
  capSpace: (id: number) => api.get<CapInfo>(`/teams/${id}/cap`),
};

// Players
export const playerApi = {
  roster: (teamId: number) => api.get<RosterResponse>(`/teams/${teamId}/players`),
  get: (id: number) => api.get<Player>(`/players/${id}`),
  stats: (id: number) => api.get<PlayerStats>(`/players/${id}/stats`),
  gameLog: (id: number) => api.get<{ games: GameLogEntry[] }>(`/players/${id}/game-log`).then((r) => r.games),
  search: (q: string) => api.get<{ players: SearchResult[] }>(`/players/search?q=${encodeURIComponent(q)}`).then((r) => r.players),
  moveToActive: (id: number) => api.post<{ success: boolean; message: string }>(`/players/${id}/move-to-active`),
  moveToPracticeSquad: (id: number) => api.post<{ success: boolean; message: string }>(`/players/${id}/move-to-practice-squad`),
  moveToIR: (id: number) => api.post<{ success: boolean; message: string }>(`/players/${id}/move-to-ir`),
  release: (id: number) => api.post<{ success: boolean; message: string }>(`/players/${id}/release`),
  contractStatus: (id: number) => api.get<ContractStatusResponse>(`/players/${id}/contract-status`),
  offerExtension: (id: number, salary: number, years: number) =>
    api.post<ExtensionOfferResponse>(`/players/${id}/offer-extension`, { salary, years }),
};

// Depth Chart
export const depthChartApi = {
  get: (teamId: number) => api.get<DepthChartData>(`/teams/${teamId}/depth-chart`),
  update: (teamId: number, changes: DepthChartChange[]) =>
    api.put<DepthChartData>(`/teams/${teamId}/depth-chart`, { changes }),
  autoSet: (teamId: number) =>
    api.post<DepthChartData>(`/teams/${teamId}/depth-chart/auto-set`),
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
  articles: (id: number) => api.get<{ articles: Article[] }>(`/games/${id}/articles`).then((r) => r.articles),
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
  leaders: (leagueId: number, type?: string) => {
    const qs = type ? `?type=${type}` : '';
    return api.get<{ leaders: LeadersData }>(`/leagues/${leagueId}/leaders${qs}`).then((r) => r.leaders);
  },
  powerRankings: (leagueId: number) => api.get<PowerRanking[]>(`/leagues/${leagueId}/power-rankings`),
  records: (leagueId: number) => api.get<RecordsData>(`/leagues/${leagueId}/records`),
  history: (leagueId: number) => api.get<{ history: LeagueHistoryEntry[] }>(`/leagues/${leagueId}/history`).then((r) => r.history),
  scenarios: (leagueId: number) => api.get<ScenariosData>(`/leagues/${leagueId}/scenarios`),
  achievements: () => api.get<{ achievements: AchievementData[] }>('/achievements').then((r) => r.achievements),
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
  display_name: string | null;
  avatar_url: string | null;
  bio: string | null;
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

export interface SearchResult {
  id: number;
  first_name: string;
  last_name: string;
  position: string;
  overall_rating: number;
  age: number;
  team_id: number | null;
  team_city: string | null;
  team_name: string | null;
  team_abbreviation: string | null;
  status: string;
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
  game_type?: string;
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
  injured_reserve: Player[];
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

export interface GameLogEntry {
  quarter: number;
  clock: number;
  possession: 'home' | 'away';
  yard_line: number;
  down: number;
  distance: number;
  home_score: number;
  away_score: number;
  play: {
    type: string;
    yards: number;
    made?: boolean | null;
    distance?: number | null;
    player?: string | null;
    target?: string | null;
    defender?: string | null;
    depth?: string | null;
  };
  note?: string | null;
  key_play?: boolean;
}

export interface BoxScoreData {
  game: Game;
  home: { players: BoxScorePlayer[]; totals: TeamTotals };
  away: { players: BoxScorePlayer[]; totals: TeamTotals };
  game_log?: GameLogEntry[];
  game_class?: { type: string; description: string } | null;
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

export interface ScenarioTeam {
  id: number;
  city: string;
  name: string;
  abbreviation: string;
  conference: string;
  division: string;
  primary_color: string;
  wins: number;
  losses: number;
  ties: number;
  points_for: number;
  points_against: number;
}

export interface RemainingGame {
  id: number;
  week: number;
  home_team_id: number;
  away_team_id: number;
  home_abbr: string;
  away_abbr: string;
}

export interface TeamScenario {
  team_id: number;
  abbreviation: string;
  conference_rank: number;
  games_left: number;
  max_possible_wins: number;
  is_div_leader: boolean;
  div_magic_number: number | null;
  can_win_division: boolean;
  playoff_spots: number;
  clinched_playoff: boolean;
  clinched_division: boolean;
  eliminated: boolean;
}

export interface ScenariosData {
  current_week: number;
  total_games: number;
  teams: ScenarioTeam[];
  remaining_games: RemainingGame[];
  scenarios: Record<number, TeamScenario>;
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
  author_persona?: string;
  week: number;
  published_at: string;
  team_id?: number | null;
  player_id?: number | null;
  game_id?: number | null;
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

export interface CapContract {
  contract_id: number;
  player_id: number;
  player_name: string;
  position: string;
  overall_rating: number;
  age: number;
  years_total: number;
  years_remaining: number;
  salary_annual: number;
  cap_hit: number;
  guaranteed: number;
  dead_cap: number;
}

export interface CapInfo {
  team_id: number;
  team_name: string;
  total_cap: number;
  cap_used: number;
  cap_remaining: number;
  total_guaranteed: number;
  total_dead_cap: number;
  contracts: CapContract[];
  by_position: Record<string, { total_salary: number; count: number }>;
  committed_next_year: number;
  projected_cap_available: number;
  dead_money: number;
}

export interface PlayerStats {
  season: Record<string, number>;
  career: Record<string, number>;
}

// --- Contract Decision Types ---

export interface ContractStatusResponse {
  player: {
    id: number;
    first_name: string;
    last_name: string;
    position: string;
    age: number;
    overall_rating: number;
    personality: string;
    morale: string;
  };
  contract: {
    id: number;
    salary_annual: number;
    years_total: number;
    years_remaining: number;
    cap_hit: number;
    guaranteed: number;
    dead_cap: number;
    contract_type: string;
  } | null;
  team: {
    id: number;
    city: string;
    name: string;
    abbreviation: string;
  } | null;
  eligible_for_extension: boolean;
  market_value: number;
  willingness: {
    open_to_extension: boolean;
    openness_score: number;
    minimum_salary: number;
    preferred_years: number;
    market_value: number;
    reasoning: string;
  };
  preferences: {
    money_weight: number;
    winning_weight: number;
    playing_time_weight: number;
    loyalty_weight: number;
    market_weight: number;
    priorities: string[];
  };
  is_my_player: boolean;
}

export interface ExtensionOfferResponse {
  result: 'accepted' | 'countered' | 'refused';
  message: string;
  reasoning: string;
  willingness: string;
  score: number;
  counter_offer?: { salary: number; years: number };
  market_value?: number;
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
  image_url: string | null;
  is_restricted: number;
  tender_level: string | null;
  tender_salary: number;
  original_team_id: number | null;
  original_team_abbr: string | null;
  original_team_city: string | null;
  original_team_name: string | null;
  original_draft_round: number | null;
  offer_sheet: RfaOfferSheet | null;
}

export interface RfaOfferSheet {
  id: number;
  free_agent_id: number;
  offering_team_id: number;
  offering_team_abbr: string;
  offering_team_city: string;
  offering_team_name: string;
  salary: number;
  years: number;
  status: string;
  created_at: string;
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
  tier?: string;
  injury_flag?: boolean;
  latest_performance?: string;
  stock_trend?: string;
}

export interface DraftPick {
  id: number;
  round: number;
  pick_number: number;
  team_id: number;
  team_name?: string;
  is_used: boolean;
  selected_prospect_id?: number;
  via_team?: string;
  original_record?: string;
}

export interface ScoutResult {
  prospect_id: number;
  overall_range_low: number;
  overall_range_high: number;
  scouted_overall?: number;
  scouted_floor?: number;
  scouted_ceiling?: number;
  combine_grade: string;
  strengths: string[];
  weaknesses: string[];
  scouts_remaining?: number;
}

export interface DraftResult {
  success: boolean;
  pick: DraftPick;
  prospect: DraftProspect;
  message: string;
}

export interface DraftStatePick {
  id: number;
  round: number;
  pick_number: number;
  overall_pick: number;
  is_used: boolean;
  team_id: number;
  team_name: string;
  team_city: string;
  team_abbreviation: string;
  team_primary_color: string;
  team_secondary_color: string;
  prospect_id?: number;
  prospect_name?: string;
  prospect_position?: string;
  prospect_college?: string;
  prospect_age?: number;
  prospect_overall?: number;
}

export interface DraftState {
  picks: DraftStatePick[];
  current_pick: DraftStatePick | null;
  round: number;
  total_rounds: number;
  draft_year: number;
  status: 'in_progress' | 'complete' | 'not_started';
  league_phase: string | null;
  offseason_phase: string | null;
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

export interface AcquirePackage {
  players: (FindTradePlayer & { fills_need?: boolean })[];
  picks: { id: number; label: string; round: number; year: number; trade_value: number }[];
  total_value: number;
}

export interface AcquirePlayerResult {
  player: FindTradePlayer;
  available: boolean;
  asking_price?: number;
  reason: string;
  team: {
    id: number;
    city: string;
    name: string;
    abbreviation: string;
    primary_color: string;
    secondary_color: string;
    gm_personality: string;
    mode: string;
  };
  their_needs?: TeamNeed[];
  packages: AcquirePackage[];
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
  acquirePlayer: (playerId: number) =>
    api.post<AcquirePlayerResult>('/trades/acquire', { player_id: playerId }),
};

// Free Agency
export const freeAgencyApi = {
  list: (position?: string) =>
    api.get<{ free_agents: FreeAgent[] }>(`/free-agents${position ? `?position=${position}` : ''}`).then((r) => r.free_agents),
  bid: (id: number, salary_offer: number, years_offer: number) =>
    api.post(`/free-agents/${id}/bid`, { salary_offer, years_offer }),
  myBids: () => api.get<{ bids: FaBid[] }>('/free-agents/my-bids').then((r) => r.bids),
  // Restricted Free Agency
  setTender: (id: number, level: string) =>
    api.post<{ success: boolean; tender_level: string; tender_salary: number }>(`/free-agents/${id}/tender`, { level }),
  makeOfferSheet: (id: number, salary: number, years: number) =>
    api.post<{ success: boolean; offer_sheet_id: number }>(`/free-agents/${id}/offer-sheet`, { salary, years }),
  matchOffer: (id: number) =>
    api.post<{ success: boolean; action: string }>(`/free-agents/${id}/match-offer`, {}),
  declineOffer: (id: number) =>
    api.post<{ success: boolean; action: string; compensation: { round_label: string } }>(`/free-agents/${id}/decline-offer`, {}),
  rfaOffers: () =>
    api.get<{ pending_offers: any[]; my_rfas: FreeAgent[] }>('/free-agents/rfa-offers'),
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
  state: () => api.get<DraftState>('/draft/state'),
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
  activity: () => api.get<{ activity: ActivityRecord[] }>('/commissioner/activity').then((r) => r.activity),
  replaceCoach: (teamId: number, action: 'to_ai' | 'to_human') =>
    api.post<{ success: boolean; message: string }>('/commissioner/replace-coach', { team_id: teamId, action }),
  sendReminders: () => api.post<{ success: boolean; message: string; count: number }>('/commissioner/send-reminders'),
};

export interface ActivityRecord {
  team_id: number;
  team_name: string;
  team_emoji: string;
  abbreviation: string;
  coach_id: number;
  coach_name: string;
  is_human: boolean;
  games_played: number;
  plans_submitted: number;
  plans_missed: number;
  status: 'active' | 'inactive' | 'absent';
}

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

// --- Offseason Report ---

export interface OffseasonReport {
  awards: { type: string; player_name?: string; coach_name?: string; team_name: string; stats?: string }[];
  development: {
    improved: { name: string; position: string; old_ovr: number; new_ovr: number; change: number }[];
    declined: { name: string; position: string; old_ovr: number; new_ovr: number; change: number }[];
    retired: { name: string; position: string; final_ovr: number; age: number }[];
  };
  contracts_expired: { name: string; position: string; overall_rating: number; team_name: string }[];
  draft_class_size: number;
  free_agents_generated: number;
  schedule_games: number;
  new_season_year: number;
}

// Ready Check & Advance
export interface ReadyStatus {
  week: number;
  coaches: { coach_id: number; name: string; team: string; team_name: string; is_ready: boolean }[];
  coaches_ready: number;
  coaches_total: number;
  fantasy: { manager_id: number; name: string; team_name: string; is_ready: boolean }[];
  fantasy_ready: number;
  fantasy_total: number;
  all_ready: boolean;
  advance_mode: string;
  auto_advance_hours: number;
  next_advance_at: string | null;
  auto_advanced?: boolean;
  advance_reason?: string;
}

export const advanceApi = {
  readyStatus: (leagueId: number) =>
    api.get<ReadyStatus>(`/leagues/${leagueId}/ready-status`),
  markReady: (leagueId: number) =>
    api.post<ReadyStatus>(`/leagues/${leagueId}/ready`),
  forceAdvance: (leagueId: number) =>
    api.post<{ advanced: boolean; reason: string }>(`/leagues/${leagueId}/force-advance`),
  getSettings: (leagueId: number) =>
    api.get<{ advance_mode: string; auto_advance_hours: number }>(`/leagues/${leagueId}/advance-settings`),
  updateSettings: (leagueId: number, settings: { advance_mode?: string; auto_advance_hours?: number }) =>
    api.put(`/leagues/${leagueId}/advance-settings`, settings),
};

export interface OffseasonPhaseMeta {
  id: string;
  week: number;
  label: string;
  short: string;
}

export interface OffseasonStatus {
  offseason_phase: string | null;
  league_phase: string;
  season_year: number;
  phase_index: number;
  total_phases: number;
  week_number: number;
  week_label: string;
  week_short: string;
  total_weeks: number;
  summary: string;
  phases: OffseasonPhaseMeta[];
  pending_actions: { action: string; count: number; message: string }[];
}

export interface OffseasonExpiringContract {
  contract_id: number;
  player_id: number;
  team_id: number;
  salary_annual: number;
  cap_hit: number;
  years_remaining: number;
  first_name: string;
  last_name: string;
  position: string;
  overall_rating: number;
  age: number;
  potential: string;
}

export const offseasonApi = {
  report: () => api.get<OffseasonReport>('/offseason/report'),
  status: () => api.get<OffseasonStatus>('/offseason/status'),
  expiringContracts: () => api.get<{ expiring_contracts: OffseasonExpiringContract[]; count: number }>('/offseason/expiring-contracts'),
  reSign: (playerId: number, salaryOffer: number, yearsOffer: number) =>
    api.post(`/offseason/re-sign/${playerId}`, { salary_offer: salaryOffer, years_offer: yearsOffer }),
  decline: (playerId: number) => api.post(`/offseason/decline/${playerId}`),
};

// --- Fantasy Football ---

export interface FantasyLeagueSummary {
  id: number;
  league_id: number;
  name: string;
  num_teams: number;
  scoring_type: string;
  status: string;
  draft_status: string;
  invite_code: string;
  is_member: boolean;
  manager_count: number;
  human_count: number;
}

export interface FantasyManager {
  id: number;
  fantasy_league_id: number;
  coach_id: number | null;
  team_name: string;
  owner_name: string;
  avatar_color: string;
  is_ai: boolean;
  personality: string | null;
  wins: number;
  losses: number;
  ties: number;
  points_for: number;
  points_against: number;
  streak: string;
  playoff_seed: number | null;
  is_eliminated: boolean;
  is_champion: boolean;
  win_pct?: number;
  ppg?: number;
  games_played?: number;
  faab_remaining: number;
  waiver_priority: number;
}

export interface FantasyLeagueDetail {
  id: number;
  name: string;
  num_teams: number;
  scoring_type: string;
  scoring_rules: Record<string, number>;
  roster_slots: Record<string, number>;
  status: string;
  draft_status: string;
  invite_code: string;
  playoff_start_week: number;
  championship_week: number;
  regular_season_end_week: number;
  managers: FantasyManager[];
  standings: FantasyManager[];
  my_manager: FantasyManager | null;
}

export interface FantasyRosterPlayer {
  player_id: number;
  first_name: string;
  last_name: string;
  position: string;
  overall_rating: number;
  team_abbr: string;
  team_name: string;
  roster_slot: string;
  is_starter: boolean;
  acquired_via: string;
  points?: number;
}

export interface FantasyMatchup {
  id: number;
  week: number;
  manager1_id: number;
  manager2_id: number;
  team1_name: string;
  team2_name: string;
  owner1_name: string;
  owner2_name: string;
  manager1_score: number | null;
  manager2_score: number | null;
  winner_id: number | null;
  is_playoff: boolean;
  is_championship: boolean;
  team1_roster?: FantasyRosterPlayer[];
  team2_roster?: FantasyRosterPlayer[];
}

export interface FantasyDraftPick {
  round: number;
  pick: number;
  manager_id: number;
  manager_name: string;
  team_name: string;
  player_id: number;
  player_name: string;
  position: string;
  overall: number;
  is_ai: boolean;
  first_name?: string;
  last_name?: string;
  overall_rating?: number;
  details?: string;
  player_pos?: string;
  owner_name?: string;
}

export interface FantasyTradeProposal {
  id: number;
  proposer_name: string;
  proposer_team: string;
  recipient_name: string;
  recipient_team: string;
  players_offered: { id: number; first_name: string; last_name: string; position: string; overall_rating: number }[];
  players_requested: { id: number; first_name: string; last_name: string; position: string; overall_rating: number }[];
  message: string | null;
  status: string;
  is_mine: boolean;
  can_respond: boolean;
  created_at: string;
}

export interface FantasyTransaction {
  id: number;
  type: string;
  owner_name: string;
  team_name: string;
  player_first: string;
  player_last: string;
  player_pos: string;
  week: number;
  created_at: string;
}

export const fantasyApi = {
  leagues: () =>
    api.get<{ fantasy_leagues: FantasyLeagueSummary[] }>('/fantasy/leagues').then(r => r.fantasy_leagues),
  createLeague: (config: {
    name: string;
    num_teams: number;
    max_humans: number;
    scoring_type: string;
    playoff_start_week: number;
    num_playoff_teams: number;
    draft_type: string;
    draft_rounds: number;
    waiver_type: string;
    faab_budget?: number;
    team_name: string;
    owner_name: string;
  }) => api.post<{ id: number; invite_code: string; num_teams: number; ai_managers: number }>('/fantasy/leagues', config),
  getLeague: (id: number) =>
    api.get<{ fantasy_league: FantasyLeagueDetail }>(`/fantasy/leagues/${id}`).then(r => r.fantasy_league),
  joinLeague: (id: number, inviteCode: string, teamName: string, ownerName: string) =>
    api.post<{ success: boolean; manager_id: number }>(`/fantasy/leagues/${id}/join`, {
      invite_code: inviteCode, team_name: teamName, owner_name: ownerName,
    }),
  draft: (id: number) =>
    api.post<{ success: boolean; picks: FantasyDraftPick[]; total_picks: number }>(`/fantasy/leagues/${id}/draft`),
  draftResults: (id: number) =>
    api.get<{ draft_picks: FantasyDraftPick[] }>(`/fantasy/leagues/${id}/draft-results`).then(r => r.draft_picks),
  roster: (leagueId: number) =>
    api.get<{ manager: FantasyManager; roster: FantasyRosterPlayer[] }>(`/fantasy/leagues/${leagueId}/roster`),
  managerRoster: (managerId: number) =>
    api.get<{ manager: FantasyManager; roster: FantasyRosterPlayer[] }>(`/fantasy/managers/${managerId}/roster`),
  setLineup: (leagueId: number, lineup: { player_id: number; roster_slot: string; is_starter: boolean }[]) =>
    api.put<{ roster: FantasyRosterPlayer[] }>(`/fantasy/leagues/${leagueId}/lineup`, { lineup }),
  addDrop: (leagueId: number, addPlayerId: number, dropPlayerId: number) =>
    api.post(`/fantasy/leagues/${leagueId}/add-drop`, {
      add_player_id: addPlayerId, drop_player_id: dropPlayerId,
    }),
  matchups: (leagueId: number, week: number) =>
    api.get<{ matchups: FantasyMatchup[] }>(`/fantasy/leagues/${leagueId}/matchups/${week}`).then(r => r.matchups),
  standings: (leagueId: number) =>
    api.get<{ standings: FantasyManager[] }>(`/fantasy/leagues/${leagueId}/standings`).then(r => r.standings),
  schedule: (leagueId: number) =>
    api.get<{ schedule: FantasyMatchup[]; manager: FantasyManager }>(`/fantasy/leagues/${leagueId}/schedule`),
  availablePlayers: (leagueId: number, position?: string) =>
    api.get<{ players: Player[] }>(`/fantasy/leagues/${leagueId}/available${position ? `?position=${position}` : ''}`).then(r => r.players),
  trades: (leagueId: number) =>
    api.get<{ trades: FantasyTradeProposal[] }>(`/fantasy/leagues/${leagueId}/trades`).then(r => r.trades),
  proposeTrade: (leagueId: number, recipientId: number, playersOffered: number[], playersRequested: number[], message?: string) =>
    api.post<{ status: string; message: string }>(`/fantasy/leagues/${leagueId}/trades`, {
      recipient_id: recipientId, players_offered: playersOffered, players_requested: playersRequested, message,
    }),
  respondTrade: (tradeId: number, action: 'accept' | 'reject') =>
    api.put(`/fantasy/trades/${tradeId}/respond`, { action }),
  transactions: (leagueId: number) =>
    api.get<{ transactions: FantasyTransaction[] }>(`/fantasy/leagues/${leagueId}/transactions`).then(r => r.transactions),
  rankings: (leagueId: number, position?: string) =>
    api.get<{ rankings: Player[] }>(`/fantasy/leagues/${leagueId}/rankings${position ? `?position=${position}` : ''}`).then(r => r.rankings),
  playoffs: (leagueId: number) =>
    api.get<{ bracket: FantasyMatchup[] }>(`/fantasy/leagues/${leagueId}/playoffs`).then(r => r.bracket),
};

// --- Records, History, Achievements Types ---

export interface RecordEntry {
  player_id: number;
  first_name: string;
  last_name: string;
  position: string;
  team: string;
  season_year?: number;
  seasons?: number;
  total: number;
}

export interface RecordsData {
  single_season: Record<string, { label: string; records: RecordEntry[] }>;
  career: Record<string, { label: string; records: RecordEntry[] }>;
  team_wins: { city: string; name: string; abbreviation: string; primary_color: string; season_year: number; wins: number; losses: number; ties: number }[];
  biggest_blowouts: { week: number; season_year: number; home_team: string; away_team: string; home_score: number; away_score: number; margin: number }[];
}

export interface LeagueHistoryEntry {
  year: number;
  is_current: boolean;
  champion: string | null;
  mvp: string | null;
  mvp_team?: string | null;
  coach_of_year?: string | null;
}

export interface AchievementData {
  id: string;
  name: string;
  desc: string;
  icon: string;
  unlocked: boolean;
}
