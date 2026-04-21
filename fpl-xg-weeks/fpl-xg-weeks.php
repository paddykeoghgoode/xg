<?php
/**
 * Plugin Name: FPL xG Over Weeks Tool
 * Description: Dynamic tool to view team and player expected goals (xG) over a selected number of recent FPL gameweeks.
 * Version: 1.1.3
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
        add_action('wp_ajax_' . self::ACTION, [$this, 'ajax_get_data']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'ajax_get_data']);
    }

    public function register_assets(): void {
        wp_register_style(
            'fpl-xg-weeks-style',
            plugin_dir_url(__FILE__) . 'assets/css/fpl-xg-weeks.css',
            [],
            '1.1.3'
        );

        wp_register_script(
            'fpl-xg-weeks-script',
            plugin_dir_url(__FILE__) . 'assets/js/fpl-xg-weeks.js',
            ['jquery'],
            '1.1.3',
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
                                    <th>xGA</th>
                                    <th>GF</th>
                                    <th>GA</th>
                                    <th>Avg FDR</th>
                                    <th>xG/Match</th>
                                    <th>Med xG/Match</th>
                                    <th>xPts</th>
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
                                    <th>Pos</th>
                                    <th>xG</th>
                                    <th>xA</th>
                                    <th>xGI</th>
                                    <th>xPts</th>
                                    <th>G</th>
                                    <th>A</th>
                                    <th>Pts</th>
                                    <th>xG/90</th>
                                    <th>xA/90</th>
                                    <th>xGI/90</th>
                                    <th>Med xG</th>
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
        $element_types = $bootstrap['element_types'] ?? [];

        $latest_finished_gw = $this->get_latest_finished_gw($events);

        if ($latest_finished_gw < 1) {
            wp_send_json_error(['message' => 'No completed gameweeks found yet.'], 400);
        }

        $from_gw = max(1, $latest_finished_gw - $weeks + 1);
        $fixtures_context = $this->get_team_opponents_for_range($from_gw, $latest_finished_gw, $teams);

        if (is_wp_error($fixtures_context)) {
            wp_send_json_error(['message' => $fixtures_context->get_error_message()], 500);
        }

        $dataset_cache_key = 'fpl_xg_dataset_' . md5($from_gw . '_' . $latest_finished_gw . '_' . $player_limit);
        $dataset = get_transient($dataset_cache_key);
        if (! is_array($dataset)) {
            $dataset = $this->build_dataset($from_gw, $latest_finished_gw, $elements, $teams, $element_types, $fixtures_context, $player_limit);
            if (! is_wp_error($dataset)) {
                set_transient($dataset_cache_key, $dataset, 30 * MINUTE_IN_SECONDS);
            }
        }

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

    private function build_dataset(int $from_gw, int $to_gw, array $elements, array $teams, array $element_types, array $fixtures_context, int $player_limit) {
        $team_names = [];
        $position_names = [];
        $elements_by_id = [];

        foreach ($teams as $team) {
            $team_id = isset($team['id']) ? (int) $team['id'] : 0;
            if ($team_id > 0) {
                $team_names[$team_id] = (string) ($team['name'] ?? 'Unknown');
            }
        }

        foreach ($elements as $player) {
            $player_id = isset($player['id']) ? (int) $player['id'] : 0;
            if ($player_id > 0) {
                $elements_by_id[$player_id] = $player;
            }
        }

        foreach ($element_types as $position) {
            $position_id = isset($position['id']) ? (int) $position['id'] : 0;
            if ($position_id > 0) {
                $position_names[$position_id] = (string) ($position['singular_name_short'] ?? $position['singular_name'] ?? 'UNK');
            }
        }

        $team_meta = $fixtures_context['teamMeta'] ?? [];
        $fixtures_by_id = $fixtures_context['fixtures'] ?? [];
        $team_stats = [];
        $team_gw_xg = [];
        $player_accumulators = [];

        for ($gw = $from_gw; $gw <= $to_gw; $gw++) {
            $event_live = $this->get_event_live($gw);
            if (is_wp_error($event_live)) {
                return $event_live;
            }

            $event_elements = $event_live['elements'] ?? [];
            foreach ($event_elements as $event_element) {
                $player_id = isset($event_element['id']) ? (int) $event_element['id'] : 0;
                if ($player_id < 1 || ! isset($elements_by_id[$player_id])) {
                    continue;
                }

                $bootstrap_player = $elements_by_id[$player_id];
                $team_id = (int) ($bootstrap_player['team'] ?? 0);
                if (! isset($player_accumulators[$player_id])) {
                    $player_accumulators[$player_id] = [
                        'team_id' => $team_id,
                        'xg' => 0.0,
                        'xa' => 0.0,
                        'xgi' => 0.0,
                        'minutes' => 0,
                        'matches' => 0,
                        'goals' => 0,
                        'assists' => 0,
                        'points' => 0,
                        'xg_samples' => [],
                        'opponents' => [],
                    ];
                }

                $stats = $event_element['stats'] ?? [];
                $entry_minutes = isset($stats['minutes']) ? (int) $stats['minutes'] : 0;
                $entry_xg = isset($stats['expected_goals']) ? (float) $stats['expected_goals'] : 0.0;
                $entry_xa = isset($stats['expected_assists']) ? (float) $stats['expected_assists'] : 0.0;
                $entry_xgi = isset($stats['expected_goal_involvements']) ? (float) $stats['expected_goal_involvements'] : ($entry_xg + $entry_xa);

                $player_accumulators[$player_id]['xg'] += $entry_xg;
                $player_accumulators[$player_id]['xa'] += $entry_xa;
                $player_accumulators[$player_id]['xgi'] += $entry_xgi;
                $player_accumulators[$player_id]['minutes'] += $entry_minutes;
                if ($entry_minutes > 0) {
                    $player_accumulators[$player_id]['matches']++;
                }
                $player_accumulators[$player_id]['goals'] += isset($stats['goals_scored']) ? (int) $stats['goals_scored'] : 0;
                $player_accumulators[$player_id]['assists'] += isset($stats['assists']) ? (int) $stats['assists'] : 0;
                $player_accumulators[$player_id]['points'] += isset($stats['total_points']) ? (int) $stats['total_points'] : 0;
                $player_accumulators[$player_id]['xg_samples'][] = $entry_xg;

                if (! isset($team_gw_xg[$team_id])) {
                    $team_gw_xg[$team_id] = [];
                }
                if (! isset($team_gw_xg[$team_id][$gw])) {
                    $team_gw_xg[$team_id][$gw] = 0.0;
                }
                $team_gw_xg[$team_id][$gw] += $entry_xg;

                $explain_rows = $event_element['explain'] ?? [];
                foreach ($explain_rows as $explain_row) {
                    $fixture_id = isset($explain_row['fixture']) ? (int) $explain_row['fixture'] : 0;
                    if ($fixture_id > 0 && isset($fixtures_by_id[$fixture_id])) {
                        $opponent_team_id = 0;
                        $fixture_home = (int) $fixtures_by_id[$fixture_id]['home'];
                        $fixture_away = (int) $fixtures_by_id[$fixture_id]['away'];
                        if ($team_id === $fixture_home) {
                            $opponent_team_id = $fixture_away;
                        } elseif ($team_id === $fixture_away) {
                            $opponent_team_id = $fixture_home;
                        }
                        if ($opponent_team_id > 0 && isset($team_names[$opponent_team_id])) {
                            $player_accumulators[$player_id]['opponents'][$opponent_team_id] = $team_names[$opponent_team_id];
                        }

                        $explain_stats = $explain_row['stats'] ?? [];
                        foreach ($explain_stats as $explain_stat) {
                            if (($explain_stat['identifier'] ?? '') !== 'expected_goals') {
                                continue;
                            }
                            $stat_points = $explain_stat['points'] ?? [];
                            foreach ($stat_points as $stat_point) {
                                $point_xg = isset($stat_point['value']) ? (float) $stat_point['value'] : 0.0;
                                if ($team_id === $fixture_home) {
                                    $fixtures_by_id[$fixture_id]['xg_home'] += $point_xg;
                                } elseif ($team_id === $fixture_away) {
                                    $fixtures_by_id[$fixture_id]['xg_away'] += $point_xg;
                                }
                            }
                        }
                    }
                }
            }
        }

        $player_rows = [];
        foreach ($player_accumulators as $player_id => $acc) {
            if (($acc['xg'] <= 0.0) && ((int) $acc['minutes'] <= 0)) {
                continue;
            }

            $bootstrap_player = $elements_by_id[$player_id] ?? [];
            $team_id = (int) ($acc['team_id'] ?? 0);
            $player_name = trim(((string) ($bootstrap_player['first_name'] ?? '')) . ' ' . ((string) ($bootstrap_player['second_name'] ?? '')));
            if ($player_name === '') {
                $player_name = (string) ($bootstrap_player['web_name'] ?? 'Unknown Player');
            }

            $position_id = (int) ($bootstrap_player['element_type'] ?? 0);
            $goal_points = 4;
            if (in_array($position_id, [1, 2], true)) {
                $goal_points = 6;
            } elseif ($position_id === 3) {
                $goal_points = 5;
            }

            sort($acc['opponents']);
            $player_expected_points = ($acc['xg'] * $goal_points) + ($acc['xa'] * 3);
            $minutes = (int) $acc['minutes'];

            $player_rows[] = [
                'name' => $player_name,
                'team' => $team_names[$team_id] ?? 'Unknown',
                'position' => $position_names[$position_id] ?? 'UNK',
                'xg' => round((float) $acc['xg'], 2),
                'xa' => round((float) $acc['xa'], 2),
                'xgi' => round((float) $acc['xgi'], 2),
                'expected_points' => round($player_expected_points, 2),
                'goals' => (int) $acc['goals'],
                'assists' => (int) $acc['assists'],
                'points' => (int) $acc['points'],
                'minutes' => $minutes,
                'matches' => (int) $acc['matches'],
                'xg_per_90' => $minutes > 0 ? round((((float) $acc['xg']) / $minutes) * 90, 2) : 0,
                'xa_per_90' => $minutes > 0 ? round((((float) $acc['xa']) / $minutes) * 90, 2) : 0,
                'xgi_per_90' => $minutes > 0 ? round((((float) $acc['xgi']) / $minutes) * 90, 2) : 0,
                'median_xg' => round($this->median($acc['xg_samples']), 2),
                'opponents' => array_values($acc['opponents']),
            ];
        }

        $team_gw_fixture_counts = $fixtures_context['teamGwFixtures'] ?? [];
        foreach ($fixtures_by_id as &$fixture) {
            $fixture_gw = (int) ($fixture['gw'] ?? 0);
            $home = (int) ($fixture['home'] ?? 0);
            $away = (int) ($fixture['away'] ?? 0);

            if (((float) ($fixture['xg_home'] ?? 0.0)) <= 0.0 && $fixture_gw > 0 && isset($team_gw_xg[$home][$fixture_gw])) {
                $fixture['xg_home'] = ((float) $team_gw_xg[$home][$fixture_gw]) / max(1, (int) ($team_gw_fixture_counts[$home][$fixture_gw] ?? 1));
            }
            if (((float) ($fixture['xg_away'] ?? 0.0)) <= 0.0 && $fixture_gw > 0 && isset($team_gw_xg[$away][$fixture_gw])) {
                $fixture['xg_away'] = ((float) $team_gw_xg[$away][$fixture_gw]) / max(1, (int) ($team_gw_fixture_counts[$away][$fixture_gw] ?? 1));
            }
        }
        unset($fixture);

        $team_xg_samples = [];
        foreach ($fixtures_by_id as $fixture) {
            $home = (int) ($fixture['home'] ?? 0);
            $away = (int) ($fixture['away'] ?? 0);
            $home_xg = (float) ($fixture['xg_home'] ?? 0.0);
            $away_xg = (float) ($fixture['xg_away'] ?? 0.0);
            $xp = $this->calculate_expected_points_for_fixture($home_xg, $away_xg);

            if (! isset($team_stats[$home])) {
                $team_stats[$home] = ['xg_for' => 0.0, 'xg_against' => 0.0, 'expected_points' => 0.0];
            }
            if (! isset($team_stats[$away])) {
                $team_stats[$away] = ['xg_for' => 0.0, 'xg_against' => 0.0, 'expected_points' => 0.0];
            }

            $team_stats[$home]['xg_for'] += $home_xg;
            $team_stats[$home]['xg_against'] += $away_xg;
            $team_stats[$home]['expected_points'] += $xp['home'];
            $team_xg_samples[$home][] = $home_xg;

            $team_stats[$away]['xg_for'] += $away_xg;
            $team_stats[$away]['xg_against'] += $home_xg;
            $team_stats[$away]['expected_points'] += $xp['away'];
            $team_xg_samples[$away][] = $away_xg;
        }

        $team_rows = [];
        foreach ($team_meta as $team_id => $meta) {
            $matches = (int) ($meta['matches'] ?? 0);
            $xg_for = (float) ($team_stats[$team_id]['xg_for'] ?? 0.0);
            $xg_against = (float) ($team_stats[$team_id]['xg_against'] ?? 0.0);

            $team_rows[] = [
                'name' => (string) ($meta['name'] ?? $team_names[$team_id] ?? 'Unknown'),
                'xg' => round($xg_for, 2),
                'xga' => round($xg_against, 2),
                'goals_for' => (int) ($meta['goals_for'] ?? 0),
                'goals_against' => (int) ($meta['goals_against'] ?? 0),
                'avg_fdr' => round((float) ($meta['avg_fdr'] ?? 0), 2),
                'matches' => $matches,
                'xg_per_match' => $matches > 0 ? round($xg_for / $matches, 2) : 0,
                'median_xg_per_match' => round($this->median($team_xg_samples[$team_id] ?? []), 2),
                'expected_points' => round((float) ($team_stats[$team_id]['expected_points'] ?? 0.0), 2),
                'opponents' => array_values($meta['opponents'] ?? []),
            ];
        }

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

    private function calculate_expected_points_for_fixture(float $home_xg, float $away_xg): array {
        $home_lambda = max(0.01, $home_xg);
        $away_lambda = max(0.01, $away_xg);
        $max_goals = 10;
        $home_points = 0.0;
        $away_points = 0.0;

        for ($home_goals = 0; $home_goals <= $max_goals; $home_goals++) {
            $p_home = $this->poisson_probability($home_goals, $home_lambda);
            for ($away_goals = 0; $away_goals <= $max_goals; $away_goals++) {
                $p_away = $this->poisson_probability($away_goals, $away_lambda);
                $joint = $p_home * $p_away;

                if ($home_goals > $away_goals) {
                    $home_points += 3 * $joint;
                } elseif ($home_goals < $away_goals) {
                    $away_points += 3 * $joint;
                } else {
                    $home_points += $joint;
                    $away_points += $joint;
                }
            }
        }

        return [
            'home' => $home_points,
            'away' => $away_points,
        ];
    }

    private function poisson_probability(int $goals, float $lambda): float {
        return exp(-$lambda) * pow($lambda, $goals) / $this->factorial($goals);
    }

    private function factorial(int $n): int {
        if ($n < 2) {
            return 1;
        }

        $result = 1;
        for ($i = 2; $i <= $n; $i++) {
            $result *= $i;
        }

        return $result;
    }

    private function median(array $values): float {
        if (empty($values)) {
            return 0.0;
        }

        $clean = array_map('floatval', $values);
        sort($clean);
        $count = count($clean);
        $middle = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($clean[$middle - 1] + $clean[$middle]) / 2;
        }

        return $clean[$middle];
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
        $fixtures_by_id = [];
        $team_gw_fixtures = [];

        for ($gw = $from_gw; $gw <= $to_gw; $gw++) {
            $fixtures = $this->get_fixtures_for_gw($gw);
            if (is_wp_error($fixtures)) {
                return $fixtures;
            }

            foreach ($fixtures as $fixture) {
                $home = isset($fixture['team_h']) ? (int) $fixture['team_h'] : 0;
                $away = isset($fixture['team_a']) ? (int) $fixture['team_a'] : 0;
                $fixture_id = isset($fixture['id']) ? (int) $fixture['id'] : 0;
                $home_score = isset($fixture['team_h_score']) ? (int) $fixture['team_h_score'] : null;
                $away_score = isset($fixture['team_a_score']) ? (int) $fixture['team_a_score'] : null;
                $home_difficulty = isset($fixture['team_h_difficulty']) ? (int) $fixture['team_h_difficulty'] : 0;
                $away_difficulty = isset($fixture['team_a_difficulty']) ? (int) $fixture['team_a_difficulty'] : 0;

                if ($home > 0 && $away > 0) {
                    if (! isset($rows[$home])) {
                        $rows[$home] = ['name' => $team_names[$home] ?? 'Unknown', 'matches' => 0, 'goals_for' => 0, 'goals_against' => 0, 'fdr_sum' => 0.0, 'fdr_count' => 0, 'opponents' => []];
                    }
                    if (! isset($rows[$away])) {
                        $rows[$away] = ['name' => $team_names[$away] ?? 'Unknown', 'matches' => 0, 'goals_for' => 0, 'goals_against' => 0, 'fdr_sum' => 0.0, 'fdr_count' => 0, 'opponents' => []];
                    }

                    $rows[$home]['matches']++;
                    $rows[$away]['matches']++;
                    $team_gw_fixtures[$home][$gw] = ($team_gw_fixtures[$home][$gw] ?? 0) + 1;
                    $team_gw_fixtures[$away][$gw] = ($team_gw_fixtures[$away][$gw] ?? 0) + 1;

                    if (isset($team_names[$away])) {
                        $rows[$home]['opponents'][] = $team_names[$away] . ($home_difficulty > 0 ? ' (FDR ' . $home_difficulty . ')' : '');
                    }
                    if (isset($team_names[$home])) {
                        $rows[$away]['opponents'][] = $team_names[$home] . ($away_difficulty > 0 ? ' (FDR ' . $away_difficulty . ')' : '');
                    }

                    if ($home_difficulty > 0) {
                        $rows[$home]['fdr_sum'] += $home_difficulty;
                        $rows[$home]['fdr_count']++;
                    }
                    if ($away_difficulty > 0) {
                        $rows[$away]['fdr_sum'] += $away_difficulty;
                        $rows[$away]['fdr_count']++;
                    }

                    if ($home_score !== null && $away_score !== null) {
                        $rows[$home]['goals_for'] += $home_score;
                        $rows[$home]['goals_against'] += $away_score;
                        $rows[$away]['goals_for'] += $away_score;
                        $rows[$away]['goals_against'] += $home_score;
                    }

                    if ($fixture_id > 0) {
                        $fixtures_by_id[$fixture_id] = [
                            'home' => $home,
                            'away' => $away,
                            'gw' => $gw,
                            'xg_home' => 0.0,
                            'xg_away' => 0.0,
                        ];
                    }

                    if ($home_score !== null && $away_score !== null) {
                        $rows[$home]['goals_for'] += $home_score;
                        $rows[$home]['goals_against'] += $away_score;
                        $rows[$away]['goals_for'] += $away_score;
                        $rows[$away]['goals_against'] += $home_score;
                    }

                    if ($fixture_id > 0) {
                        $fixtures_by_id[$fixture_id] = [
                            'home' => $home,
                            'away' => $away,
                            'xg_home' => 0.0,
                            'xg_away' => 0.0,
                        ];
                    }
                }
            }
        }

        foreach ($rows as &$row) {
            $row['avg_fdr'] = ($row['fdr_count'] ?? 0) > 0 ? ((float) $row['fdr_sum'] / (int) $row['fdr_count']) : 0.0;
            sort($row['opponents']);
            $row['opponents'] = array_values($row['opponents']);
        }

        return [
            'teamMeta' => $rows,
            'fixtures' => $fixtures_by_id,
            'teamGwFixtures' => $team_gw_fixtures,
        ];
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

    private function get_event_live(int $gw) {
        $cache_key = 'fpl_xg_event_live_' . $gw;
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $event_live = $this->request_json('/event/' . $gw . '/live/');
        if (is_wp_error($event_live)) {
            return $event_live;
        }

        set_transient($cache_key, $event_live, 30 * MINUTE_IN_SECONDS);

        return $event_live;
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
