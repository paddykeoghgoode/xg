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
          <td>${escapeHtml(row.avg_fdr)}</td>
          <td>${escapeHtml(row.xg_per_match)}</td>
          <td>${escapeHtml(row.median_xg_per_match)}</td>
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
          <td>${escapeHtml(row.xa_per_90)}</td>
          <td>${escapeHtml(row.xgi_per_90)}</td>
          <td>${escapeHtml(row.median_xg)}</td>
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

  function sortableValue(text) {
    const normalized = String(text).replaceAll(',', '').trim();
    const asNumber = Number(normalized);
    if (!Number.isNaN(asNumber) && normalized !== '') {
      return { type: 'number', value: asNumber };
    }
    return { type: 'text', value: normalized.toLowerCase() };
  }

  function makeTableSortable(tableSelector) {
    const $table = $(tableSelector);
    const $headers = $table.find('thead th');

    $headers.each((index, th) => {
      const $th = $(th);
      $th.css('cursor', 'pointer');
      $th.attr('title', 'Click to sort');
      $th.off('click.fplsort').on('click.fplsort', () => {
        const currentDir = $th.data('sortDir') === 'asc' ? 'desc' : 'asc';
        $headers.removeData('sortDir');
        $th.data('sortDir', currentDir);

        const rows = $table.find('tbody tr').get();
        rows.sort((a, b) => {
          const aText = $(a).children().eq(index).text();
          const bText = $(b).children().eq(index).text();
          const aVal = sortableValue(aText);
          const bVal = sortableValue(bText);

          let cmp = 0;
          if (aVal.type === 'number' && bVal.type === 'number') {
            cmp = aVal.value - bVal.value;
          } else {
            cmp = String(aVal.value).localeCompare(String(bVal.value));
          }

          return currentDir === 'asc' ? cmp : -cmp;
        });

        const $tbody = $table.find('tbody');
        rows.forEach((row) => $tbody.append(row));
      });
    });
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
        makeTableSortable('#fpl-xg-teams-table');
        makeTableSortable('#fpl-xg-players-table');

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
