<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\League;
use App\Models\Coach;

class SettingsController
{
    private League $league;
    private Coach $coach;

    public function __construct()
    {
        $this->league = new League();
        $this->coach = new Coach();
    }

    /**
     * GET /api/settings
     * Return current settings for the user's active league.
     */
    public function show(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $leagueId = (int) $auth['league_id'];
        if (!$leagueId) {
            Response::error('No active league');
            return;
        }

        $league = $this->league->find($leagueId);
        if (!$league) {
            Response::notFound('League not found');
            return;
        }

        $leagueSettings = json_decode($league['settings'] ?? '{}', true);

        $coach = $auth['coach_id'] ? $this->coach->find((int) $auth['coach_id']) : null;

        // Match frontend SettingsData interface
        Response::json([
            'league_settings' => $leagueSettings,
            'coach' => $coach ? [
                'name' => $coach['name'],
                'archetype' => $coach['archetype'],
            ] : null,
            'is_commissioner' => $auth['is_admin'],
        ]);
    }

    /**
     * PUT /api/settings
     * Update settings. Commissioners can update league settings;
     * all coaches can update their own coach-level preferences.
     */
    public function update(array $params): void
    {
        $auth = AuthMiddleware::handle();
        if (!$auth) return;

        $body = Response::getJsonBody();

        $leagueId = (int) $auth['league_id'];
        if (!$leagueId) {
            Response::error('No active league');
            return;
        }

        $updated = [];

        // League-level settings (commissioner only)
        if (isset($body['league_settings']) && is_array($body['league_settings'])) {
            if (!$auth['is_admin']) {
                Response::error('Only the commissioner can update league settings', 403);
                return;
            }

            $league = $this->league->find($leagueId);
            if (!$league) {
                Response::notFound('League not found');
                return;
            }

            $allowedKeys = [
                'quarter_length', 'injury_frequency', 'trade_difficulty',
                'salary_cap_enabled', 'max_teams', 'sim_speed', 'invite_code',
            ];

            $currentSettings = json_decode($league['settings'] ?? '{}', true);
            foreach ($body['league_settings'] as $key => $value) {
                if (in_array($key, $allowedKeys, true)) {
                    $currentSettings[$key] = $value;
                }
            }

            $this->league->update($leagueId, [
                'settings' => json_encode($currentSettings),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $updated['league_settings'] = $currentSettings;
        }

        // Coach-level settings (own coach only)
        if (isset($body['coach']) && is_array($body['coach']) && $auth['coach_id']) {
            $coachData = [];

            if (isset($body['coach']['name'])) {
                $name = trim($body['coach']['name']);
                if ($name !== '') {
                    $coachData['name'] = $name;
                }
            }

            if (isset($body['coach']['archetype'])) {
                $validArchetypes = ['balanced', 'offensive', 'defensive', 'players_coach', 'disciplinarian'];
                if (in_array($body['coach']['archetype'], $validArchetypes, true)) {
                    $coachData['archetype'] = $body['coach']['archetype'];
                }
            }

            if (!empty($coachData)) {
                $this->coach->update((int) $auth['coach_id'], $coachData);
                $updated['coach'] = $coachData;
            }
        }

        if (empty($updated)) {
            Response::error('No valid settings to update');
            return;
        }

        Response::success('Settings updated', ['updated' => $updated]);
    }
}
