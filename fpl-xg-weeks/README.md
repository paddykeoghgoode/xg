# FPL xG Over Weeks Tool (WordPress Plugin)

## What it does
This plugin adds a dynamic dashboard showing:
- **Team xG ranking table** over selected recent gameweeks.
- **Opponents faced** by each team in that gameweek window.
- **Individual player xG list** (ranked) for the same period.

Data is pulled from the official FPL API and refreshed live through AJAX.

## Shortcodes
- Primary (unique): `[xg_fpl_rankings_pro]`

## Install
1. Copy the `fpl-xg-weeks` folder into your WordPress `wp-content/plugins/` directory.
2. Activate **FPL xG Over Weeks Tool** in WordPress admin.
3. Add shortcode `[xg_fpl_rankings_pro]` to any page/post.

## Data sources
- `/bootstrap-static/` for players, teams, and events.
- `/element-summary/{player_id}/` for per-gameweek expected goals (`expected_goals`).
- `/fixtures/?event={gw}` for opponent lists by gameweek.

## Notes
- Team xG is aggregated from player xG totals.
- Fixtures are used to show opponent context (to reduce inflated interpretation).
- Uses WordPress transients (30 minutes) for bootstrap-static, fixtures, and player summaries.
