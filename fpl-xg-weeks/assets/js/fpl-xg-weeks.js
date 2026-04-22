(function ($) {
  let allPlayerRows = [];

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function formatNum(value, decimals = 2) {
    const num = Number(value);
    if (Number.isNaN(num)) return escapeHtml(value);
    return num.toFixed(decimals);
  }

  function formatInt(value) {
    const num = Number(value);
    if (Number.isNaN(num)) return escapeHtml(value);
    return String(Math.round(num));
  }

  function opponentsHtml(opponents) {
    if (!Array.isArray(opponents) || opponents.length === 0) return '—';
    return opponents.map((opp) => `<span class="fpl-xg-opp-chip">${escapeHtml(opp)}</span>`).join(' ');
  }

  function setStatus(message, isError = false) {
    const $status = $('#fpl-xg-status');
    $status.text(message);
    $status.toggleClass('is-error', Boolean(isError));
  }

  function renderTeams(rows) {
    const $body = $('#fpl-xg-teams-body');
    $body.empty();

    rows.forEach((row, index) => {
      $body.append(`
        <tr>
          <td class="is-num">${index + 1}</td>
          <td><strong>${escapeHtml(row.name)}</strong></td>
          <td class="is-num">${formatNum(row.xg)}</td>
          <td class="is-num">${formatNum(row.xga)}</td>
          <td class="is-num">${formatInt(row.goals_for)}</td>
          <td class="is-num">${formatInt(row.goals_against)}</td>
          <td class="is-num">${formatNum(row.avg_fdr, 1)}</td>
          <td class="is-num">${formatNum(row.xg_per_match)}</td>
          <td class="is-num">${formatNum(row.median_xg_per_match)}</td>
          <td class="is-num">${formatNum(row.expected_points)}</td>
          <td class="is-num">${formatInt(row.matches)}</td>
          <td class="fpl-xg-opp-list">${opponentsHtml(row.opponents)}</td>
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
          <td class="is-num">${index + 1}</td>
          <td><strong>${escapeHtml(row.name)}</strong></td>
          <td>${escapeHtml(row.team)}</td>
          <td>${escapeHtml(row.position)}</td>
          <td class="is-num">${formatNum(row.xg)}</td>
          <td class="is-num">${formatNum(row.xa)}</td>
          <td class="is-num">${formatNum(row.xgi)}</td>
          <td class="is-num">${formatInt(row.goals)}</td>
          <td class="is-num">${formatInt(row.assists)}</td>
          <td class="is-num">${formatInt(row.points)}</td>
          <td class="is-num">${formatNum(row.xg_per_90)}</td>
          <td class="is-num">${formatNum(row.xa_per_90)}</td>
          <td class="is-num">${formatNum(row.xgi_per_90)}</td>
          <td class="is-num">${formatNum(row.median_xg)}</td>
          <td class="is-num">${formatInt(row.minutes)}</td>
          <td class="fpl-xg-opp-list">${opponentsHtml(row.opponents)}</td>
        </tr>
      `);
    });

    $('#fpl-xg-players-table').prop('hidden', rows.length === 0);
  }

  function sortableValue(text) {
    const normalized = String(text).replaceAll(',', '').trim();
    const asNumber = Number(normalized);
    if (!Number.isNaN(asNumber) && normalized !== '') return { type: 'number', value: asNumber };
    return { type: 'text', value: normalized.toLowerCase() };
  }

  function makeTableSortable(tableSelector) {
    const $table = $(tableSelector);
    const $headers = $table.find('thead th');

    $headers.each((index, th) => {
      const $th = $(th);
      if (!$th.find('.fpl-sort-indicator').length) {
        $th.append('<span class="fpl-sort-indicator" aria-hidden="true"></span>');
      }

      $th.css('cursor', 'pointer');
      $th.attr('title', 'Click to sort');
      $th.attr('aria-sort', 'none');
      $th.off('click.fplsort').on('click.fplsort', () => {
        const currentDir = $th.data('sortDir') === 'asc' ? 'desc' : 'asc';
        $headers.removeData('sortDir').removeClass('is-sorted-asc is-sorted-desc').attr('aria-sort', 'none');
        $th.data('sortDir', currentDir);
        $th.addClass(currentDir === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');
        $th.attr('aria-sort', currentDir === 'asc' ? 'ascending' : 'descending');

        const rows = $table.find('tbody tr').get();
        rows.sort((a, b) => {
          const aText = $(a).children().eq(index).text();
          const bText = $(b).children().eq(index).text();
          const aVal = sortableValue(aText);
          const bVal = sortableValue(bText);
          let cmp = 0;
          if (aVal.type === 'number' && bVal.type === 'number') cmp = aVal.value - bVal.value;
          else cmp = String(aVal.value).localeCompare(String(bVal.value));
          return currentDir === 'asc' ? cmp : -cmp;
        });

        const $tbody = $table.find('tbody');
        rows.forEach((row, idx) => {
          $(row).children().eq(0).text(idx + 1);
          $tbody.append(row);
        });
      });
    });
  }

  function populatePlayerFilters(rows) {
    const teams = [...new Set(rows.map((r) => r.team).filter(Boolean))].sort();
    const positions = [...new Set(rows.map((r) => r.position).filter(Boolean))].sort();

    const $team = $('#fpl-xg-player-team');
    const $pos = $('#fpl-xg-player-pos');
    $team.find('option:not(:first)').remove();
    $pos.find('option:not(:first)').remove();

    teams.forEach((team) => $team.append(`<option value="${escapeHtml(team)}">${escapeHtml(team)}</option>`));
    positions.forEach((pos) => $pos.append(`<option value="${escapeHtml(pos)}">${escapeHtml(pos)}</option>`));
  }

  function applyPlayerFilters() {
    const query = String($('#fpl-xg-player-search').val() || '').toLowerCase().trim();
    const team = String($('#fpl-xg-player-team').val() || '');
    const pos = String($('#fpl-xg-player-pos').val() || '');
    const minMinutes = Number($('#fpl-xg-player-minutes').val() || 0);

    const filtered = allPlayerRows.filter((row) => {
      if (query && !String(row.name || '').toLowerCase().includes(query)) return false;
      if (team && row.team !== team) return false;
      if (pos && row.position !== pos) return false;
      if (!Number.isNaN(minMinutes) && Number(row.minutes || 0) < minMinutes) return false;
      return true;
    });

    renderPlayers(filtered);
    makeTableSortable('#fpl-xg-players-table');
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
        allPlayerRows = data.playerRows || [];

        renderTeams(teamRows);
        populatePlayerFilters(allPlayerRows);
        applyPlayerFilters();
        makeTableSortable('#fpl-xg-teams-table');

        setStatus(`Updated for GW${data.fromGw}–GW${data.toGw}: ${teamRows.length} teams, ${allPlayerRows.length} players.`);
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
  $(document).on('input change', '#fpl-xg-player-search, #fpl-xg-player-team, #fpl-xg-player-pos, #fpl-xg-player-minutes', applyPlayerFilters);
  $(document).ready(loadData);
})(jQuery);
