<?php
/**
 * Head Coach 26 — API Route Definitions
 * All routes are prefixed with /api automatically by the Router.
 */

use App\Controllers\AuthController;
use App\Controllers\LeaguesController;
use App\Controllers\TeamsController;
use App\Controllers\PlayersController;
use App\Controllers\DepthChartController;
use App\Controllers\GamesController;
use App\Controllers\GamePlanController;
use App\Controllers\SimulationController;
use App\Controllers\StandingsController;
use App\Controllers\PressConferenceController;
use App\Controllers\ArticlesController;
use App\Controllers\SocialController;
use App\Controllers\SettingsController;
use App\Controllers\OwnerOfficeController;
use App\Controllers\TradeController;
use App\Controllers\FreeAgencyController;
use App\Controllers\DraftController;
use App\Controllers\CoachingStaffController;
use App\Controllers\OffseasonController;
use App\Controllers\NotificationController;
use App\Controllers\InviteController;
use App\Controllers\CommissionerController;
use App\Controllers\MessageController;
use App\Controllers\AiContentController;
use App\Controllers\RosterImportController;
use App\Controllers\CoachCareerController;
use App\Controllers\FranchiseController;
use App\Controllers\FantasyController;

// Auth
$router->post('/auth/login', [AuthController::class, 'login']);
$router->post('/auth/logout', [AuthController::class, 'logout']);
$router->post('/auth/register', [AuthController::class, 'register']);
$router->get('/auth/session', [AuthController::class, 'session']);

// Profile
$router->get('/profile', [AuthController::class, 'getProfile']);
$router->put('/profile', [AuthController::class, 'updateProfile']);
$router->post('/profile/avatar', [AuthController::class, 'uploadAvatar']);

// Leagues
$router->get('/leagues', [LeaguesController::class, 'index']);
$router->post('/leagues', [LeaguesController::class, 'create']);
$router->get('/leagues/{id}', [LeaguesController::class, 'show']);
$router->put('/leagues/{id}', [LeaguesController::class, 'update']);
$router->post('/leagues/{id}/join', [LeaguesController::class, 'join']);
$router->post('/leagues/{id}/advance', [LeaguesController::class, 'advance']);
$router->get('/leagues/{id}/playoff-bracket', [LeaguesController::class, 'playoffBracket']);
$router->get('/leagues/{id}/playoff-seeding', [LeaguesController::class, 'playoffSeeding']);

// Ready Check & Advance System
$router->get('/leagues/{id}/ready-status', [LeaguesController::class, 'readyStatus']);
$router->post('/leagues/{id}/ready', [LeaguesController::class, 'markReady']);
$router->post('/leagues/{id}/force-advance', [LeaguesController::class, 'forceAdvance']);
$router->get('/leagues/{id}/advance-settings', [LeaguesController::class, 'advanceSettings']);
$router->put('/leagues/{id}/advance-settings', [LeaguesController::class, 'updateAdvanceSettings']);
$router->post('/cron/advance', [LeaguesController::class, 'cronAdvance']);

// Teams
$router->get('/leagues/{league_id}/teams', [TeamsController::class, 'index']);
$router->get('/teams/{id}', [TeamsController::class, 'show']);
$router->get('/teams/{id}/cap', [TeamsController::class, 'capSpace']);
$router->get('/teams/{id}/contracts', [TeamsController::class, 'contracts']);
$router->post('/contracts/{id}/restructure', [TeamsController::class, 'restructureContract']);

// Players / Roster
$router->get('/teams/{team_id}/players', [PlayersController::class, 'roster']);
$router->get('/players/search', [PlayersController::class, 'search']);
$router->get('/players/{id}', [PlayersController::class, 'show']);
$router->get('/players/{id}/stats', [PlayersController::class, 'stats']);
$router->get('/players/{id}/game-log', [PlayersController::class, 'gameLog']);
$router->post('/players/{id}/move-to-active', [PlayersController::class, 'moveToActive']);
$router->post('/players/{id}/move-to-practice-squad', [PlayersController::class, 'moveToPracticeSquad']);
$router->post('/players/{id}/move-to-ir', [PlayersController::class, 'moveToIR']);
$router->get('/players/{id}/contract-status', [PlayersController::class, 'contractStatus']);
$router->post('/players/{id}/offer-extension', [PlayersController::class, 'offerExtension']);
$router->get('/offseason/contract-planner', [PlayersController::class, 'contractPlanner']);

// Franchise Tags
$router->post('/players/{id}/franchise-tag', [PlayersController::class, 'applyFranchiseTag']);
$router->delete('/players/{id}/franchise-tag', [PlayersController::class, 'removeFranchiseTag']);
$router->get('/players/{id}/franchise-tag/check', [PlayersController::class, 'checkFranchiseTag']);
$router->get('/franchise-tag/values', [PlayersController::class, 'franchiseTagValues']);

// Depth Chart
$router->get('/teams/{team_id}/depth-chart', [DepthChartController::class, 'show']);
$router->put('/teams/{team_id}/depth-chart', [DepthChartController::class, 'update']);
$router->post('/teams/{team_id}/depth-chart/auto-set', [DepthChartController::class, 'autoSet']);

// Schedule & Games
$router->get('/leagues/{league_id}/schedule', [GamesController::class, 'schedule']);
$router->get('/games/{id}', [GamesController::class, 'show']);
$router->get('/games/{id}/box-score', [GamesController::class, 'boxScore']);
$router->get('/games/{id}/articles', [GamesController::class, 'articles']);

// Game Plan
$router->get('/games/{id}/game-plan', [GamePlanController::class, 'show']);
$router->post('/games/{id}/game-plan', [GamePlanController::class, 'submit']);

// Simulation
$router->post('/leagues/{league_id}/simulate', [SimulationController::class, 'simulateWeek']);

// Standings & Leaders
$router->get('/leagues/{league_id}/standings', [StandingsController::class, 'index']);
$router->get('/leagues/{league_id}/leaders', [StandingsController::class, 'leaders']);
$router->get('/leagues/{league_id}/power-rankings', [StandingsController::class, 'powerRankings']);
$router->get('/leagues/{league_id}/records', [StandingsController::class, 'records']);
$router->get('/leagues/{league_id}/scenarios', [StandingsController::class, 'scenarios']);
$router->get('/leagues/{league_id}/history', [StandingsController::class, 'history']);
$router->get('/achievements', [StandingsController::class, 'achievements']);
$router->get('/standings/award-history', [StandingsController::class, 'awardHistory']);

// Press Conference
$router->get('/press-conference/current', [PressConferenceController::class, 'current']);
$router->post('/press-conference/{id}/answer', [PressConferenceController::class, 'answer']);
$router->get('/press-conference/{id}/results', [PressConferenceController::class, 'results']);

// Articles / News
$router->get('/leagues/{league_id}/articles', [ArticlesController::class, 'index']);
$router->get('/articles/{id}', [ArticlesController::class, 'show']);
$router->get('/leagues/{league_id}/ticker', [ArticlesController::class, 'ticker']);

// Social / GridironX
$router->get('/leagues/{league_id}/social', [SocialController::class, 'index']);

// Coach
$router->get('/coaches/{id}', [TeamsController::class, 'coach']);

// Owner Office
$router->get('/owner-office', [OwnerOfficeController::class, 'index']);

// Settings
$router->get('/settings', [SettingsController::class, 'show']);
$router->put('/settings', [SettingsController::class, 'update']);

// Trades
$router->get('/trades', [TradeController::class, 'index']);
$router->post('/trades/find-opportunities', [TradeController::class, 'findOpportunities']);
$router->post('/trades/acquire', [TradeController::class, 'acquirePlayer']);
$router->get('/trades/incoming-offers', [TradeController::class, 'incomingOffers']);
$router->post('/trades', [TradeController::class, 'propose']);
$router->post('/trades/sweeten', [TradeController::class, 'sweeten']);
$router->put('/trades/{id}/respond', [TradeController::class, 'respond']);
$router->get('/trades/{id}/evaluate', [TradeController::class, 'evaluate']);
$router->get('/trade-block', [TradeController::class, 'tradeBlockIndex']);
$router->post('/trade-block', [TradeController::class, 'tradeBlockAdd']);
$router->delete('/trade-block/{id}', [TradeController::class, 'tradeBlockRemove']);

// Free Agency
$router->get('/free-agents', [FreeAgencyController::class, 'index']);
$router->post('/free-agents/{id}/bid', [FreeAgencyController::class, 'bid']);
$router->post('/free-agents/resolve', [FreeAgencyController::class, 'resolve']);
$router->get('/free-agents/my-bids', [FreeAgencyController::class, 'myBids']);
$router->post('/players/{id}/release', [FreeAgencyController::class, 'release']);
$router->get('/players/{id}/cut-preview', [FreeAgencyController::class, 'cutPreview']);
$router->post('/free-agency/simulate-round', [FreeAgencyController::class, 'simulateRound']);

// Restricted Free Agency
$router->post('/free-agents/{id}/tender', [FreeAgencyController::class, 'tender']);
$router->post('/free-agents/{id}/offer-sheet', [FreeAgencyController::class, 'offerSheet']);
$router->post('/free-agents/{id}/match-offer', [FreeAgencyController::class, 'matchOffer']);
$router->post('/free-agents/{id}/decline-offer', [FreeAgencyController::class, 'declineOffer']);
$router->get('/free-agents/rfa-offers', [FreeAgencyController::class, 'rfaOffers']);

// Draft
$router->get('/draft/class', [DraftController::class, 'draftClass']);
$router->get('/draft/board', [DraftController::class, 'board']);
$router->get('/draft/my-picks', [DraftController::class, 'myPicks']);
$router->post('/draft/scout/{id}', [DraftController::class, 'scout']);
$router->post('/draft/pick', [DraftController::class, 'pick']);
$router->post('/draft/auto-pick', [DraftController::class, 'autoPick']);
$router->post('/draft/simulate', [DraftController::class, 'simulate']);
$router->post('/draft/generate-prospects', [DraftController::class, 'generateProspects']);
$router->get('/draft/report', [DraftController::class, 'weeklyReport']);
$router->get('/draft/prospect/{id}', [DraftController::class, 'prospectProfile']);
$router->post('/draft/prospect/{id}/favorite', [DraftController::class, 'toggleFavorite']);
$router->get('/draft/my-board', [DraftController::class, 'myBoard']);
$router->put('/draft/board', [DraftController::class, 'updateDraftBoard']);
$router->get('/draft/budget', [DraftController::class, 'scoutingBudget']);
$router->get('/draft/state', [DraftController::class, 'draftState']);

// Coaching Staff
$router->get('/coaching-staff', [CoachingStaffController::class, 'index']);
$router->get('/coaching-staff/available', [CoachingStaffController::class, 'available']);
$router->post('/coaching-staff/hire/{id}', [CoachingStaffController::class, 'hire']);
$router->post('/coaching-staff/fire/{id}', [CoachingStaffController::class, 'fire']);

// Offseason & Legacy
$router->post('/offseason/process', [OffseasonController::class, 'process']);
$router->get('/offseason/report', [OffseasonController::class, 'report']);
$router->get('/offseason/awards', [OffseasonController::class, 'awards']);
$router->get('/legacy', [OffseasonController::class, 'legacy']);
$router->get('/offseason/status', [OffseasonController::class, 'status']);
$router->get('/offseason/expiring-contracts', [OffseasonController::class, 'expiringContracts']);
$router->post('/offseason/re-sign/{id}', [OffseasonController::class, 'reSign']);
$router->post('/offseason/decline/{id}', [OffseasonController::class, 'declineOption']);

// Hall of Fame & Awards
$router->get('/hall-of-fame', [OffseasonController::class, 'hallOfFame']);
$router->get('/awards', [OffseasonController::class, 'leagueAwards']);
$router->get('/awards/{year}', [OffseasonController::class, 'seasonAwards']);

// Notifications
$router->get('/notifications', [NotificationController::class, 'index']);
$router->put('/notifications/read-all', [NotificationController::class, 'markAllRead']);
$router->get('/notifications/count', [NotificationController::class, 'unreadCount']);
$router->put('/notifications/{id}/read', [NotificationController::class, 'markRead']);

// Invites
$router->post('/invites', [InviteController::class, 'create']);
$router->get('/invites', [InviteController::class, 'index']);
$router->post('/invites/claim', [InviteController::class, 'claim']);
$router->get('/invites/available-teams', [InviteController::class, 'availableTeams']);
$router->delete('/invites/{id}', [InviteController::class, 'cancel']);

// Commissioner
$router->get('/commissioner/settings', [CommissionerController::class, 'settings']);
$router->put('/commissioner/settings', [CommissionerController::class, 'updateSettings']);
$router->get('/commissioner/members', [CommissionerController::class, 'members']);
$router->put('/commissioner/trades/{id}/review', [CommissionerController::class, 'reviewTrade']);
$router->post('/commissioner/force-advance', [CommissionerController::class, 'forceAdvance']);
$router->get('/commissioner/submissions', [CommissionerController::class, 'submissionStatus']);
$router->get('/commissioner/activity', [CommissionerController::class, 'activity']);
$router->post('/commissioner/replace-coach', [CommissionerController::class, 'replaceCoach']);
$router->post('/commissioner/send-reminders', [CommissionerController::class, 'sendReminders']);

// Messages
$router->get('/messages', [MessageController::class, 'index']);
$router->post('/messages', [MessageController::class, 'post']);
$router->get('/messages/channels', [MessageController::class, 'channels']);
$router->put('/messages/{id}/pin', [MessageController::class, 'pin']);
$router->delete('/messages/{id}', [MessageController::class, 'delete']);

// AI Content
$router->get('/ai/status', [AiContentController::class, 'status']);
$router->post('/ai/configure', [AiContentController::class, 'configure']);
$router->post('/ai/generate-recap', [AiContentController::class, 'generateRecap']);
$router->post('/ai/generate-feature', [AiContentController::class, 'generateFeature']);
$router->post('/ai/generate-social', [AiContentController::class, 'generateSocial']);

// Coach Career / Team Switching
$router->get('/coach-career/available-teams', [CoachCareerController::class, 'availableTeams']);
$router->post('/coach-career/switch-team', [CoachCareerController::class, 'switchTeam']);
$router->get('/coach-career/history', [CoachCareerController::class, 'history']);

// Roster Import
$router->post('/roster-import/validate', [RosterImportController::class, 'validate']);
$router->post('/roster-import', [RosterImportController::class, 'import']);
$router->post('/roster-import/madden', [RosterImportController::class, 'importMadden']);
$router->get('/roster-import/history', [RosterImportController::class, 'history']);
$router->post('/roster-import/fetch-images', [RosterImportController::class, 'fetchImages']);

// Franchise Management
$router->get('/franchise/teams-config', [FranchiseController::class, 'teamsConfig']);
$router->post('/franchise/restart', [FranchiseController::class, 'restart']);
$router->post('/franchise/generate-roster', [FranchiseController::class, 'generateRoster']);
$router->post('/franchise/generate-free-agents', [FranchiseController::class, 'generateFreeAgents']);

// Fantasy Football
$router->get('/fantasy/leagues', [FantasyController::class, 'index']);
$router->post('/fantasy/leagues', [FantasyController::class, 'create']);
$router->get('/fantasy/leagues/{id}', [FantasyController::class, 'show']);
$router->post('/fantasy/leagues/{id}/join', [FantasyController::class, 'join']);
$router->post('/fantasy/leagues/{id}/draft', [FantasyController::class, 'draft']);
$router->get('/fantasy/leagues/{id}/draft-results', [FantasyController::class, 'draftResults']);
$router->get('/fantasy/leagues/{id}/roster', [FantasyController::class, 'roster']);
$router->put('/fantasy/leagues/{id}/lineup', [FantasyController::class, 'setLineup']);
$router->post('/fantasy/leagues/{id}/add-drop', [FantasyController::class, 'addDrop']);
$router->get('/fantasy/leagues/{id}/matchups/{week}', [FantasyController::class, 'matchups']);
$router->get('/fantasy/leagues/{id}/standings', [FantasyController::class, 'standings']);
$router->get('/fantasy/leagues/{id}/schedule', [FantasyController::class, 'schedule']);
$router->get('/fantasy/leagues/{id}/available', [FantasyController::class, 'availablePlayers']);
$router->get('/fantasy/leagues/{id}/trades', [FantasyController::class, 'trades']);
$router->post('/fantasy/leagues/{id}/trades', [FantasyController::class, 'proposeTrade']);
$router->put('/fantasy/trades/{id}/respond', [FantasyController::class, 'respondTrade']);
$router->get('/fantasy/leagues/{id}/transactions', [FantasyController::class, 'transactions']);
$router->get('/fantasy/leagues/{id}/rankings', [FantasyController::class, 'rankings']);
$router->get('/fantasy/leagues/{id}/playoffs', [FantasyController::class, 'playoffs']);
$router->get('/fantasy/managers/{id}/roster', [FantasyController::class, 'managerRoster']);
