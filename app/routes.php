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

// Auth
$router->post('/auth/login', [AuthController::class, 'login']);
$router->post('/auth/logout', [AuthController::class, 'logout']);
$router->post('/auth/register', [AuthController::class, 'register']);
$router->get('/auth/session', [AuthController::class, 'session']);

// Leagues
$router->get('/leagues', [LeaguesController::class, 'index']);
$router->post('/leagues', [LeaguesController::class, 'create']);
$router->get('/leagues/{id}', [LeaguesController::class, 'show']);
$router->put('/leagues/{id}', [LeaguesController::class, 'update']);
$router->post('/leagues/{id}/join', [LeaguesController::class, 'join']);
$router->post('/leagues/{id}/advance', [LeaguesController::class, 'advance']);

// Teams
$router->get('/leagues/{league_id}/teams', [TeamsController::class, 'index']);
$router->get('/teams/{id}', [TeamsController::class, 'show']);
$router->get('/teams/{id}/cap', [TeamsController::class, 'capSpace']);

// Players / Roster
$router->get('/teams/{team_id}/players', [PlayersController::class, 'roster']);
$router->get('/players/{id}', [PlayersController::class, 'show']);
$router->get('/players/{id}/stats', [PlayersController::class, 'stats']);
$router->get('/players/{id}/game-log', [PlayersController::class, 'gameLog']);

// Depth Chart
$router->get('/teams/{team_id}/depth-chart', [DepthChartController::class, 'show']);
$router->put('/teams/{team_id}/depth-chart', [DepthChartController::class, 'update']);

// Schedule & Games
$router->get('/leagues/{league_id}/schedule', [GamesController::class, 'schedule']);
$router->get('/games/{id}', [GamesController::class, 'show']);
$router->get('/games/{id}/box-score', [GamesController::class, 'boxScore']);

// Game Plan
$router->get('/games/{id}/game-plan', [GamePlanController::class, 'show']);
$router->post('/games/{id}/game-plan', [GamePlanController::class, 'submit']);

// Simulation
$router->post('/leagues/{league_id}/simulate', [SimulationController::class, 'simulateWeek']);

// Standings & Leaders
$router->get('/leagues/{league_id}/standings', [StandingsController::class, 'index']);
$router->get('/leagues/{league_id}/leaders', [StandingsController::class, 'leaders']);
$router->get('/leagues/{league_id}/power-rankings', [StandingsController::class, 'powerRankings']);

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
$router->post('/trades', [TradeController::class, 'propose']);
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

// Draft
$router->get('/draft/class', [DraftController::class, 'draftClass']);
$router->get('/draft/board', [DraftController::class, 'board']);
$router->get('/draft/my-picks', [DraftController::class, 'myPicks']);
$router->post('/draft/scout/{id}', [DraftController::class, 'scout']);
$router->post('/draft/pick', [DraftController::class, 'pick']);
$router->post('/draft/auto-pick', [DraftController::class, 'autoPick']);
$router->post('/draft/simulate', [DraftController::class, 'simulate']);

// Coaching Staff
$router->get('/coaching-staff', [CoachingStaffController::class, 'index']);
$router->get('/coaching-staff/available', [CoachingStaffController::class, 'available']);
$router->post('/coaching-staff/hire/{id}', [CoachingStaffController::class, 'hire']);
$router->post('/coaching-staff/fire/{id}', [CoachingStaffController::class, 'fire']);

// Offseason & Legacy
$router->post('/offseason/process', [OffseasonController::class, 'process']);
$router->get('/offseason/awards', [OffseasonController::class, 'awards']);
$router->get('/legacy', [OffseasonController::class, 'legacy']);

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

// Franchise Management
$router->get('/franchise/teams-config', [FranchiseController::class, 'teamsConfig']);
$router->post('/franchise/restart', [FranchiseController::class, 'restart']);
$router->post('/franchise/generate-roster', [FranchiseController::class, 'generateRoster']);
$router->post('/franchise/generate-free-agents', [FranchiseController::class, 'generateFreeAgents']);
