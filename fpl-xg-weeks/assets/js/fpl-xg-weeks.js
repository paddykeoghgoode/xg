(function ($) {
  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function opponentsText(opponents) {
    if (!Array.isArray(opponents) || opponents.length === 0) {
      return '—';
    }
    return opponents.join(', ');
  }

  function renderTeams(rows) {
    const $body = $('#fpl-xg-teams-body');
    $body.empty();

    rows.forEach((row, index) => {
      $body.append(`
        <tr>
          <td>${index + 1}</td>
          <td><strong>${escapeHtml(row.name)}</strong></td>
          <td>${escapeHtml(row.xg)}</td>
          <td>${escapeHtml(row.xga)}</td>
          <td>${escapeHtml(row.goals_for)}</td>
          <td>${escapeHtml(row.goals_against)}</td>
          <td>${escapeHtml(row.xg_per_match)}</td>
          <td>${escapeHtml(row.expected_points)}</td>
          <td>${escapeHtml(row.matches)}</td>
          <td>${escapeHtml(opponentsText(row.opponents))}</td>
        </tr>
      `);
    });

    $('#fpl-xg-teams-table').prop('hidden', rows.length === 0);
  }

  function renderPlayers(rows) {
    const $body = $('#fpl-xg-players-body');
    $body.empty();

    rows.forEach((row, index) => {
      $body.append(`
        <tr>
          <td>${index + 1}</td>
          <td><strong>${escapeHtml(row.name)}</strong></td>
          <td>${escapeHtml(row.team)}</td>
          <td>${escapeHtml(row.position)}</td>
          <td>${escapeHtml(row.xg)}</td>
          <td>${escapeHtml(row.xa)}</td>
          <td>${escapeHtml(row.xgi)}</td>
          <td>${escapeHtml(row.expected_points)}</td>
          <td>${escapeHtml(row.goals)}</td>
          <td>${escapeHtml(row.assists)}</td>
          <td>${escapeHtml(row.points)}</td>
          <td>${escapeHtml(row.xg_per_90)}</td>
          <td>${escapeHtml(row.minutes)}</td>
          <td>${escapeHtml(opponentsText(row.opponents))}</td>
        </tr>
      `);
    });

    $('#fpl-xg-players-table').prop('hidden', rows.length === 0);
  }

  function setStatus(message, isError = false) {
    const $status = $('#fpl-xg-status');
    $status.text(message);
    $status.toggleClass('is-error', Boolean(isError));
  }

  function loadData() {
    const weeks = Number($('#fpl-xg-week-count').val() || 5);
    const playerLimit = Number($('#fpl-xg-player-limit').val() || 60);

    setStatus('Loading FPL xG rankings...');
    $('#fpl-xg-load').prop('disabled', true);

    $.post(FPLXGWeeks.ajaxUrl, {
      action: FPLXGWeeks.action,
      nonce: FPLXGWeeks.nonce,
      weeks,
      playerLimit,
    })
      .done((res) => {
        if (!res || !res.success) {
          setStatus((res && res.data && res.data.message) || 'Unable to load data.', true);
          $('#fpl-xg-teams-table').prop('hidden', true);
          $('#fpl-xg-players-table').prop('hidden', true);
          return;
        }

        const data = res.data || {};
        const teamRows = data.teamRows || [];
        const playerRows = data.playerRows || [];

        renderTeams(teamRows);
        renderPlayers(playerRows);

        setStatus(`Updated for GW${data.fromGw}–GW${data.toGw}: ${teamRows.length} teams, ${playerRows.length} players.`);
      })
      .fail(() => {
        setStatus('Request failed. Please try again.', true);
        $('#fpl-xg-teams-table').prop('hidden', true);
        $('#fpl-xg-players-table').prop('hidden', true);
      })
      .always(() => {
        $('#fpl-xg-load').prop('disabled', false);
      });
  }

  $(document).on('click', '#fpl-xg-load', loadData);
  $(document).ready(loadData);
})(jQuery);
