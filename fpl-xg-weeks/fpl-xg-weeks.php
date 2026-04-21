<?php
/**
 * Plugin Name: FPL xG Over Weeks Tool
 * Description: Dynamic tool to view team and player expected goals (xG) over a selected number of recent FPL gameweeks.
 * Version: 1.1.1
 * Author: xg
 */

if (! defined('ABSPATH')) {
    exit;
}

class FPL_XG_Weeks_Tool {
    private const ACTION = 'fpl_xg_get_data';
    private const NONCE_ACTION = 'fpl_xg_nonce';
    private const API_BASE = 'https://fantasy.premierleague.com/api';

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);

        // New unique shortcode.
        add_shortcode('xg_fpl_rankings_pro', [$this, 'render_shortcode']);
        // Legacy alias for backward compatibility.
        add_shortcode('fpl_xg_tool', [$this, 'render_shortcode']);

        add_action('wp_ajax_' . self::ACTION, [$this, 'ajax_get_data']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'ajax_get_data']);
    }

    public function register_assets(): void {
        wp_register_style(
            'fpl-xg-weeks-style',
            plugin_dir_url(__FILE__) . 'assets/css/fpl-xg-weeks.css',
            [],
            '1.1.1'
        );

        wp_register_script(
            'fpl-xg-weeks-script',
            plugin_dir_url(__FILE__) . 'assets/js/fpl-xg-weeks.js',
            ['jquery'],
            '1.1.1',
            true
        );

        wp_localize_script('fpl-xg-weeks-script', 'FPLXGWeeks', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::ACTION,
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
        ]);
    }

    public function render_shortcode(): string {
        wp_enqueue_style('fpl-xg-weeks-style');
        wp_enqueue_script('fpl-xg-weeks-script');

        ob_start();
        ?>
        <section class="fpl-xg-weeks-tool" aria-label="FPL xG Rankings">
            <div class="fpl-xg-header">
                <h3>FPL xG Rankings Dashboard</h3>
                <p>Track <strong>team xG rankings</strong>, opponents faced, and an <strong>individual player xG list</strong> over recent gameweeks.</p>
            </div>

            <div class="fpl-xg-controls">
                <label>
                    Last
                    <input type="number" id="fpl-xg-week-count" min="1" max="38" value="5" />
                    gameweeks
                </label>

                <label>
                    Player rows
                    <input type="number" id="fpl-xg-player-limit" min="10" max="300" value="60" />
                </label>

                <button id="fpl-xg-load" type="button">Update Rankings</button>
            </div>

            <div id="fpl-xg-status" class="fpl-xg-status" aria-live="polite"></div>

            <div class="fpl-xg-grid">
                <article class="fpl-xg-card">
                    <h4>Team xG Ranking</h4>
                    <div class="fpl-xg-table-wrap">
                        <table id="fpl-xg-teams-table" class="fpl-xg-table" hidden>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Team</th>
                                    <th>xG</th>
                                    <th>xG/90</th>
                                    <th>Matches</th>
                                    <th>Opponents Faced</th>
                                </tr>
                            </thead>
                            <tbody id="fpl-xg-teams-body"></tbody>
                        </table>
                    </div>
                </article>

                <article class="fpl-xg-card">
                    <h4>Player xG List</h4>
                    <div class="fpl-xg-table-wrap">
                        <table id="fpl-xg-players-table" class="fpl-xg-table" hidden>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Player</th>
                                    <th>Team</th>
                                    <th>xG</th>
                                    <th>xG/90</th>
                                    <th>Minutes</th>
                                    <th>Opponents</th>
                                </tr>
                            </thead>
                            <tbody id="fpl-xg-players-body"></tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    public function ajax_get_data(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $weeks = isset($_POST['weeks']) ? (int) $_POST['weeks'] : 5;
        $weeks = max(1, min(38, $weeks));

        $player_limit = isset($_POST['playerLimit']) ? (int) $_POST['playerLimit'] : 60;
        $player_limit = max(10, min(300, $player_limit));

        $bootstrap = $this->get_bootstrap_static();

        if (is_wp_error($bootstrap)) {
            wp_send_json_error(['message' => $bootstrap->get_error_message()], 500);
        }

        $events = $bootstrap['events'] ?? [];
        $elements = $bootstrap['elements'] ?? [];
        $teams = $bootstrap['teams'] ?? [];

        $latest_finished_gw = $this->get_latest_finished_gw($events);

        if ($latest_finished_gw < 1) {
            wp_send_json_error(['message' => 'No completed gameweeks found yet.'], 400);
        }

        $from_gw = max(1, $latest_finished_gw - $weeks + 1);
        $fixtures_by_team = $this->get_team_opponents_for_range($from_gw, $latest_finished_gw, $teams);

        if (is_wp_error($fixtures_by_team)) {
            wp_send_json_error(['message' => $fixtures_by_team->get_error_message()], 500);
        }

        $dataset = $this->build_dataset($from_gw, $latest_finished_gw, $elements, $teams, $fixtures_by_team, $player_limit);

        if (is_wp_error($dataset)) {
            wp_send_json_error(['message' => $dataset->get_error_message()], 500);
        }

        wp_send_json_success([
            'weeks' => $weeks,
            'fromGw' => $from_gw,
            'toGw' => $latest_finished_gw,
            'teamRows' => $dataset['teamRows'],
            'playerRows' => $dataset['playerRows'],
        ]);
    }

    private function get_latest_finished_gw(array $events): int {
        $latest = 0;

        foreach ($events as $event) {
            if (! empty($event['finished']) && ! empty($event['id'])) {
                $latest = max($latest, (int) $event['id']);
            }
        }

        return $latest;
    }

    private function build_dataset(int $from_gw, int $to_gw, array $elements, array $teams, array $fixtures_by_team, int $player_limit) {
        $team_names = [];

        foreach ($teams as $team) {
            $team_id = isset($team['id']) ? (int) $team['id'] : 0;
            if ($team_id > 0) {
                $team_names[$team_id] = (string) ($team['name'] ?? 'Unknown');
            }
        }

        $team_rows = [];
        $player_rows = [];

        foreach ($elements as $player) {
            $player_id = isset($player['id']) ? (int) $player['id'] : 0;
            if ($player_id < 1) {
                continue;
            }

            $team_id = isset($player['team']) ? (int) $player['team'] : 0;
            $summary = $this->get_player_summary($player_id);

            if (is_wp_error($summary)) {
                return $summary;
            }

            $history = $summary['history'] ?? [];
            $xg = 0.0;
            $minutes = 0;
            $appearances = 0;
            $opp_map = [];

            foreach ($history as $entry) {
                $gw = isset($entry['round']) ? (int) $entry['round'] : 0;
                if ($gw < $from_gw || $gw > $to_gw) {
                    continue;
                }

                $xg += isset($entry['expected_goals']) ? (float) $entry['expected_goals'] : 0.0;
                $entry_minutes = isset($entry['minutes']) ? (int) $entry['minutes'] : 0;
                $minutes += $entry_minutes;
                if ($entry_minutes > 0) {
                    $appearances++;
                }

                $opp_id = isset($entry['opponent_team']) ? (int) $entry['opponent_team'] : 0;
                if ($opp_id > 0 && isset($team_names[$opp_id])) {
                    $opp_map[$opp_id] = $team_names[$opp_id];
                }
            }

            if ($xg <= 0 && $minutes <= 0) {
                continue;
            }

            if (! isset($team_rows[$team_id])) {
                $team_rows[$team_id] = [
                    'name' => $team_names[$team_id] ?? 'Unknown',
                    'xg' => 0.0,
                    'minutes' => 0,
                    'matches' => 0,
                    'opponents' => $fixtures_by_team[$team_id]['opponents'] ?? [],
                ];
            }

            $team_rows[$team_id]['xg'] += $xg;
            $team_rows[$team_id]['minutes'] += $minutes;

            if (isset($fixtures_by_team[$team_id]['matches'])) {
                $team_rows[$team_id]['matches'] = (int) $fixtures_by_team[$team_id]['matches'];
            }

            $player_name = trim(((string) ($player['first_name'] ?? '')) . ' ' . ((string) ($player['second_name'] ?? '')));
            if ($player_name === '') {
                $player_name = (string) ($player['web_name'] ?? 'Unknown Player');
            }

            sort($opp_map);
            $player_rows[] = [
                'name' => $player_name,
                'team' => $team_names[$team_id] ?? 'Unknown',
                'xg' => round($xg, 2),
                'minutes' => $minutes,
                'matches' => $appearances,
                'xg_per_90' => $minutes > 0 ? round(($xg / $minutes) * 90, 2) : 0,
                'opponents' => array_values($opp_map),
            ];
        }

        $team_rows = array_map(function ($row) {
            $minutes = (int) ($row['minutes'] ?? 0);
            $xg = (float) ($row['xg'] ?? 0.0);

            return [
                'name' => (string) ($row['name'] ?? 'Unknown'),
                'xg' => round($xg, 2),
                'minutes' => $minutes,
                'matches' => (int) ($row['matches'] ?? 0),
                'xg_per_90' => $minutes > 0 ? round(($xg / $minutes) * 90, 2) : 0,
                'opponents' => array_values($row['opponents'] ?? []),
            ];
        }, array_values($team_rows));

        usort($team_rows, function ($a, $b) {
            return $b['xg'] <=> $a['xg'];
        });

        usort($player_rows, function ($a, $b) {
            return $b['xg'] <=> $a['xg'];
        });

        $player_rows = array_slice($player_rows, 0, $player_limit);

        return [
            'teamRows' => $team_rows,
            'playerRows' => $player_rows,
        ];
    }

    private function get_team_opponents_for_range(int $from_gw, int $to_gw, array $teams) {
        $team_names = [];
        foreach ($teams as $team) {
            $team_id = isset($team['id']) ? (int) $team['id'] : 0;
            if ($team_id > 0) {
                $team_names[$team_id] = (string) ($team['name'] ?? 'Unknown');
            }
        }

        $rows = [];

        for ($gw = $from_gw; $gw <= $to_gw; $gw++) {
            $fixtures = $this->get_fixtures_for_gw($gw);
            if (is_wp_error($fixtures)) {
                return $fixtures;
            }

            foreach ($fixtures as $fixture) {
                $home = isset($fixture['team_h']) ? (int) $fixture['team_h'] : 0;
                $away = isset($fixture['team_a']) ? (int) $fixture['team_a'] : 0;

                if ($home > 0 && $away > 0) {
                    if (! isset($rows[$home])) {
                        $rows[$home] = ['matches' => 0, 'opponents' => []];
                    }
                    if (! isset($rows[$away])) {
                        $rows[$away] = ['matches' => 0, 'opponents' => []];
                    }

                    $rows[$home]['matches']++;
                    $rows[$away]['matches']++;

                    if (isset($team_names[$away])) {
                        $rows[$home]['opponents'][$away] = $team_names[$away];
                    }
                    if (isset($team_names[$home])) {
                        $rows[$away]['opponents'][$home] = $team_names[$home];
                    }
                }
            }
        }

        foreach ($rows as &$row) {
            sort($row['opponents']);
            $row['opponents'] = array_values($row['opponents']);
        }

        return $rows;
    }

    private function get_fixtures_for_gw(int $gw) {
        $cache_key = 'fpl_xg_fixtures_' . $gw;
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $fixtures = $this->request_json('/fixtures/?event=' . $gw);

        if (is_wp_error($fixtures)) {
            return $fixtures;
        }

        set_transient($cache_key, $fixtures, 30 * MINUTE_IN_SECONDS);

        return $fixtures;
    }

    private function get_bootstrap_static() {
        $cache_key = 'fpl_xg_bootstrap_static';
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $bootstrap = $this->request_json('/bootstrap-static/');
        if (is_wp_error($bootstrap)) {
            return $bootstrap;
        }

        set_transient($cache_key, $bootstrap, 30 * MINUTE_IN_SECONDS);

        return $bootstrap;
    }

    private function get_player_summary(int $player_id) {
        $cache_key = 'fpl_xg_summary_' . $player_id;
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $summary = $this->request_json('/element-summary/' . $player_id . '/');
        if (is_wp_error($summary)) {
            return $summary;
        }

        set_transient($cache_key, $summary, 30 * MINUTE_IN_SECONDS);

        return $summary;
    }

    private function request_json(string $path) {
        $url = self::API_BASE . $path;
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'WordPress FPL xG Tool',
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('fpl_request_failed', 'Unable to reach FPL API.');
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code < 200 || $status_code >= 300 || empty($body)) {
            return new WP_Error('fpl_bad_response', 'FPL API returned an invalid response.');
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return new WP_Error('fpl_decode_failed', 'Unable to decode FPL API data.');
        }

        return $decoded;
    }
}

new FPL_XG_Weeks_Tool();
