<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');

// LOCATION still read via filter_input for validation
$locationRaw = filter_input(INPUT_GET, 'LOCATION', FILTER_DEFAULT, FILTER_NULL_ON_FAILURE);
$LOCATION = filter_var($locationRaw, FILTER_VALIDATE_INT) ?: 0;

// Read DIV_GROUP directly from $_GET so it can be selected via URL (e.g., ?DIV_GROUP=MA-LINE)
$DIV_GROUP = isset($_GET['DIV_GROUP']) ? (string)$_GET['DIV_GROUP'] : '';

// Build reload URL preserving query string
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$reloadUrl = htmlspecialchars($_SERVER['PHP_SELF'] . ($queryString ? '?' . $queryString : ''), ENT_QUOTES);

// Fetch data filtered by selected LOCATION
$summary = fetch_picklist_summary($LOCATION);
$rows = fetch_open_picklist_rows($LOCATION);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
$rowsJson = json_encode($rows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$summaryJson = json_encode($summary, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$initialDivGroup = json_encode($DIV_GROUP, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Open Picklists Dashboard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="refresh" content="120;url=<?php echo $reloadUrl; ?>">
  <link rel="stylesheet" href="styles.css">
  <style>
    .controls .filter-row { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
    .controls label { margin:0; }
    .controls select, .controls input[type="search"], .controls button { padding:6px 8px; font-size:14px; }
    .muted { color:#666; font-size:0.9rem; }
    .table-wrapper { overflow:auto; }
    table.open-to-table { width:100%; border-collapse:collapse; }
    table.open-to-table th, table.open-to-table td { padding:8px 10px; border-bottom:1px solid #eee; text-align:left; }
    .download-btn { background:#0078d4; color:#fff; border:none; border-radius:4px; cursor:pointer; padding:6px 10px; }
    .download-btn:active { transform:translateY(1px); }
  </style>
</head>
<body>
<header class="site-header">
  <div class="brand">
    <h1>Open Picklists Dashboard</h1>
    <p class="muted">Auto-refresh every 120 seconds</p>
  </div>
  <div class="controls">
    <form id="locationForm" method="get" style="display:inline-block;margin-right:12px;">
      <label>Location
        <select name="LOCATION" onchange="document.getElementById('locationForm').submit()">
          <option value="0"<?php if ($LOCATION===0) echo ' selected'; ?>>All</option>
          <option value="1"<?php if ($LOCATION===1) echo ' selected'; ?>>COMPOWH</option>
          <option value="2"<?php if ($LOCATION===2) echo ' selected'; ?>>AUTOMATED</option>
          <option value="3"<?php if ($LOCATION===3) echo ' selected'; ?>>MSU</option>
          <option value="4"<?php if ($LOCATION===4) echo ' selected'; ?>>INFLAM</option>
          <option value="5"<?php if ($LOCATION===5) echo ' selected'; ?>>RAWMAT</option>
          <option value="6"<?php if ($LOCATION===6) echo ' selected'; ?>>AUTOMATED + MSU</option>
          <option value="10"<?php if ($LOCATION===10) echo ' selected'; ?>>AUTOMATED/MSU/INFLAM/RAWMAT</option>
        </select>
      </label>
    </form>

    <div class="filter-row">
      <label>Div Group
        <select id="divGroupSelect" name="DIV_GROUP">
          <option value="">All</option>
        </select>
      </label>

      <label>Search <input id="searchInput" type="search" placeholder="Search picklist, priority, location, delivery, div group, require on..."></label>

      <button id="downloadBtn" class="download-btn" type="button" title="Download visible rows as CSV">Download CSV</button>

      <div class="countdown">Refresh in <span id="countdown">120</span>s</div>
    </div>
  </div>
</header>

<main class="container">
  <!-- Summary cards reflect counts for the selected LOCATION only -->
  <section class="summary-cards" aria-hidden="false">
    <article class="card card-aog"><div class="card-title">AOG</div><div class="card-value"><?php echo h($summary['PRIORITY']['AOG'] ?? 0); ?></div></article>
    <article class="card card-wsp"><div class="card-title">WSP</div><div class="card-value"><?php echo h($summary['PRIORITY']['WSP'] ?? 0); ?></div></article>
    <article class="card card-others"><div class="card-title">OTHERS</div><div class="card-value"><?php echo h($summary['PRIORITY']['OTHERS'] ?? 0); ?></div></article>
  </section>

  <section class="table-panel">
    <h2>Open Picklists</h2>
    <div class="table-wrapper">
      <table class="open-to-table" role="table" aria-label="Open picklists">
        <thead>
          <tr>
            <th>Date</th>
            <th>Picklist</th>
            <th>Running Hrs</th>
            <th>Target Hrs</th>
            <th>Remaining Hrs</th>
            <th>Target Date</th>
            <th>Priority</th>
            <th>From</th>
            <th>Delivery To</th>
            <th>Require On</th>
            <th>Div Group</th>
          </tr>
        </thead>
        <tbody id="tableBody"></tbody>
      </table>
    </div>
  </section>
</main>

<footer class="site-footer">
  <small class="muted">Last updated: <?php echo date('Y-m-d H:i:s'); ?></small>
</footer>

<script>
(function(){
  const REFRESH_SECONDS = 120;
  const countdownEl = document.getElementById('countdown');
  const reloadUrl = "<?php echo $reloadUrl; ?>";
  const rows = <?php echo $rowsJson; ?>;
  const summary = <?php echo $summaryJson; ?>;
  const initialDivGroup = <?php echo $initialDivGroup; ?>;
  const tableBody = document.getElementById('tableBody');
  const searchInput = document.getElementById('searchInput');
  const divGroupSelect = document.getElementById('divGroupSelect');
  const downloadBtn = document.getElementById('downloadBtn');

  // Populate divGroup dropdown with counts from summary.DIVISION
  (function populateDivGroupOptions(){
    const counts = (summary && summary.DIVISION) ? summary.DIVISION : {};
    const groups = ['MA-LINE','MA-BASE','AO','OTHER'];

    // Compute total for "All"
    let total = 0;
    groups.forEach(g => {
      const v = Number(counts[g] || 0);
      if (Number.isFinite(v)) total += v;
    });

    // Update the "All" option text to include total
    const allOpt = divGroupSelect.querySelector('option[value=""]');
    if (allOpt) allOpt.textContent = `All (${total})`;

    groups.forEach(g=>{
      const opt = document.createElement('option');
      const cnt = Number(counts[g] || 0);
      opt.value = g;
      opt.textContent = `${g} (${cnt})`;
      divGroupSelect.appendChild(opt);
    });

    if (initialDivGroup) {
      for (const opt of divGroupSelect.options) {
        if (opt.value === initialDivGroup) { opt.selected = true; break; }
      }
    }
  })();

  let deadline = Date.now() + REFRESH_SECONDS * 1000;
  let timerId;
  function updateCountdown(){
    const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
    countdownEl.textContent = remaining;
    if (remaining <= 0) { location.replace(reloadUrl); }
    else { timerId = setTimeout(updateCountdown, 1000); }
  }
  updateCountdown();
  document.addEventListener('visibilitychange', ()=>{ if (!document.hidden) { clearTimeout(timerId); updateCountdown(); } });

  function escapeHtml(s){ return s ? String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/\"/g,"&quot;").replace(/'/g,"&#39;") : ""; }

  function toWholeHours(value){
    const n = Number(value);
    if (!Number.isFinite(n)) return 0;
    return Math.round(n);
  }

  function rowBgClass(rem,i){
    if (rem === 0) return "bg-due";
    if (rem < 0) return "bg-expired";
    return (i % 2 === 0) ? "bg-odd" : "bg-even";
  }

  function renderTable(data){
    tableBody.innerHTML = "";
    data.forEach((r,i) => {
      const running = toWholeHours(r.RUNNING_HOURS);
      const target = toWholeHours(r.TARGET_HOURS);
      const remaining = toWholeHours(r.REMAINING_HOURS);

      const tr = document.createElement("tr");
      tr.className = rowBgClass(remaining, i);

      // REQUIRE_ON may be missing from some rows; show empty string in that case
      const requireOn = r.REQUIRE_ON ? escapeHtml(r.REQUIRE_ON) : '';

      tr.innerHTML = `<td>${escapeHtml(r.PICK_DATE)}</td><td>${escapeHtml(r.PICKLIST)}</td>
      <td>${escapeHtml(String(running))}</td><td>${escapeHtml(String(target))}</td>
      <td>${escapeHtml(String(remaining))}</td><td>${escapeHtml(r.TARGET_DATE)}</td>
      <td>${escapeHtml(r.PRIORITY)}</td><td>${escapeHtml(r.LOCATION)}</td>
      <td>${escapeHtml(r.DELIVERY_LOCATION)}</td><td>${requireOn}</td><td>${escapeHtml(r.DIV_GROUP)}</td>`;
      tableBody.appendChild(tr);
    });
  }

  function applyFilters(){
    const q = searchInput.value.trim().toLowerCase();
    const selectedGroup = divGroupSelect.value;
    const filtered = rows.filter(r => {
      if (selectedGroup && String(r.DIV_GROUP) !== selectedGroup) return false;
      if (!q) return true;
      return String(r.PICKLIST).toLowerCase().includes(q)
        || String(r.PRIORITY).toLowerCase().includes(q)
        || String(r.LOCATION).toLowerCase().includes(q)
        || String(r.DELIVERY_LOCATION).toLowerCase().includes(q)
        || String(r.DIV_GROUP).toLowerCase().includes(q)
        || (r.REQUIRE_ON && String(r.REQUIRE_ON).toLowerCase().includes(q));
    });

    filtered.sort((a,b) => {
      const ra = toWholeHours(a.REMAINING_HOURS);
      const rb = toWholeHours(b.REMAINING_HOURS);
      return ra - rb;
    });

    renderTable(filtered);
    return filtered;
  }

  searchInput.addEventListener('input', applyFilters);
  divGroupSelect.addEventListener('change', applyFilters);

  // CSV download helpers
  function csvEscape(value) {
    if (value === null || value === undefined) return '';
    const s = String(value);
    if (/[",\r\n]/.test(s)) {
      return '"' + s.replace(/"/g, '""') + '"';
    }
    return s;
  }

  function rowsToCsv(data) {
    const headers = ['Date','Picklist','Running Hrs','Target Hrs','Remaining Hrs','Target Date','Priority','From','Delivery To','Require On','Div Group'];
    const lines = [headers.map(csvEscape).join(',')];
    data.forEach(r => {
      const running = toWholeHours(r.RUNNING_HOURS);
      const target = toWholeHours(r.TARGET_HOURS);
      const remaining = toWholeHours(r.REMAINING_HOURS);
      const line = [
        r.PICK_DATE || '',
        r.PICKLIST || '',
        String(running),
        String(target),
        String(remaining),
        r.TARGET_DATE || '',
        r.PRIORITY || '',
        r.LOCATION || '',
        r.DELIVERY_LOCATION || '',
        r.REQUIRE_ON || '',
        r.DIV_GROUP || ''
      ].map(csvEscape).join(',');
      lines.push(line);
    });
    return lines.join('\r\n');
  }

  function downloadCsv(filename, csvContent) {
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  downloadBtn.addEventListener('click', function(){
    const filtered = applyFilters(); // returns currently visible rows
    const now = new Date();
    const ts = now.toISOString().replace(/[:\-]/g,'').replace(/\..+$/,'');
    const filename = `open_picklists_${ts}.csv`;
    const csv = rowsToCsv(filtered);
    downloadCsv(filename, csv);
  });

  // Initial render (if DIV_GROUP was provided in URL, it's already selected)
  applyFilters();
})();
</script>
</body>
</html>
