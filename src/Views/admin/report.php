<?php
/**
 * Druckbarer Lehrerin-Bericht — rendert als HTML, kann als PDF gedruckt werden.
 *
 * Variablen von ReportController::show():
 *   $child              array   — {display_name, grade_level, school_type}
 *   $initialTest        array   — {id, type, completed_at}
 *   $latestTest         array   — {id, type, completed_at}
 *   $initialResults     array   — [{block, category, error_rate, severity, ...}]
 *   $latestResults      array   — dto, aktueller Stand
 *   $comparison         array   — [{category, old_rate, new_rate, delta, improved}]
 *   $activity           array   — {total_sessions, total_seconds, total_items, ...}
 *   $errorDistribution  array   — [{primary_category, c}]
 *   $strengths          array   — Kategorien mit error_rate < 15%
 *   $weaknesses         array   — Kategorien mit error_rate >= 40%
 *   $amendments         array   — Plan-Änderungen durch KI
 *   $reportDate         string
 *   $adminName          string
 */

$blockLabel = [
    'A' => 'Block A — Laut-Buchstaben-Zuordnung',
    'B' => 'Block B — Regelwissen',
    'C' => 'Block C — Ableitungswissen',
    'D' => 'Block D — Groß-/Kleinschreibung',
];
$categoryDesc = [
    'A1' => 'Auslautverhärtung',
    'A2' => 'Vokallänge',
    'A3' => 'Konsonantenhäufungen',
    'B1' => 'Doppelkonsonanten',
    'B2' => 'ck / tz',
    'B3' => 'ie / ih / i',
    'B4' => 'Dehnungs-h',
    'B5' => 'sp / st',
    'C1' => 'ä vs. e (Ableitungen)',
    'C2' => 'äu vs. eu',
    'C3' => 'dass / das',
    'D1' => 'Konkrete Nomen',
    'D2' => 'Abstrakte Nomen',
    'D3' => 'Nominalisierungen',
    'D4' => 'Satzanfang',
];
$severityLabel = ['none'=>'Gut','mild'=>'Leicht','moderate'=>'Mittel','severe'=>'Schwer'];
$severityColor = ['none'=>'#2e7d32','mild'=>'#e65100','moderate'=>'#bf360c','severe'=>'#b71c1c'];

$totalMinutes  = round(($activity['total_seconds'] ?? 0) / 60);
$correctTotal  = ($activity['correct_first'] ?? 0) + ($activity['correct_second'] ?? 0);
$totalWords    = (int)($activity['total_items'] ?? 0);
$accuracyPct   = $totalWords > 0 ? round($correctTotal / $totalWords * 100) : 0;

// Alle Kategorien aus initialResults für vollständige Tabelle
$allCategories = [];
foreach ($initialResults as $r) {
    $allCategories[$r['category']] = true;
}
foreach ($latestResults as $r) {
    $allCategories[$r['category']] = true;
}
ksort($allCategories);

// Ergebnisse als Map
$initMap   = array_column($initialResults, null, 'category');
$latestMap = array_column($latestResults,  null, 'category');
$compMap   = [];
foreach ($comparison as $c) { $compMap[$c['category']] = $c; }

$isSameTest = ($latestTest['id'] === $initialTest['id']);

// SVG-Balken-Hilfsfunktion
function pct2w(float $rate): int { return max(2, min(100, (int)round($rate * 100))); }
function severityFill(string $sev): string {
    return match($sev) {
        'severe'   => '#f44336',
        'moderate' => '#ff9800',
        'mild'     => '#ffc107',
        default    => '#4caf50',
    };
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bericht <?= htmlspecialchars($child['display_name']) ?> — <?= $reportDate ?></title>
  <style>
    /* ── Base ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: Georgia, 'Times New Roman', serif;
      font-size: 11pt;
      color: #111;
      background: #fff;
      line-height: 1.5;
    }
    h1 { font-size: 18pt; font-weight: bold; }
    h2 { font-size: 13pt; font-weight: bold; margin: 1.5rem 0 .5rem; border-bottom: 1px solid #ccc; padding-bottom: .25rem; }
    h3 { font-size: 11pt; font-weight: bold; margin: 1rem 0 .3rem; }
    p  { margin-bottom: .5rem; }
    table { border-collapse: collapse; width: 100%; font-size: 10pt; }
    th, td { padding: .3rem .6rem; border: 1px solid #ddd; text-align: left; }
    th { background: #f5f5f5; font-weight: bold; }
    .good   { color: #2e7d32; }
    .mild   { color: #e65100; }
    .mod    { color: #bf360c; }
    .severe { color: #b71c1c; }

    /* ── Layout ── */
    .page {
      max-width: 210mm;
      margin: 0 auto;
      padding: 20mm 18mm;
    }

    /* ── Header ── */
    .report-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 1.5rem;
      padding-bottom: 1rem;
      border-bottom: 2px solid #2e7d32;
    }
    .report-logo { font-size: 11pt; color: #2e7d32; font-weight: bold; }
    .report-meta { font-size: 9pt; color: #666; text-align: right; line-height: 1.6; }

    /* ── Summary boxes ── */
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: .75rem;
      margin: 1rem 0;
    }
    .summary-box {
      border: 1px solid #e0e0e0;
      border-radius: 4px;
      padding: .6rem .8rem;
      text-align: center;
      background: #fafafa;
    }
    .summary-box .val { font-size: 18pt; font-weight: bold; color: #2e7d32; }
    .summary-box .lbl { font-size: 8pt; color: #666; }

    /* ── SVG Bar chart ── */
    .bar-chart { margin: .5rem 0 1rem; }
    .bar-row {
      display: flex;
      align-items: center;
      gap: .5rem;
      margin-bottom: 4px;
      font-size: 9pt;
    }
    .bar-label { width: 4.5rem; flex-shrink: 0; color: #444; }
    .bar-outer {
      flex: 1;
      background: #e8e8e8;
      height: 14px;
      border-radius: 3px;
      overflow: hidden;
    }
    .bar-fill  { height: 100%; border-radius: 3px; transition: width .3s; }
    .bar-pct   { width: 3rem; flex-shrink: 0; font-size: 9pt; text-align: right; }
    .bar-delta { width: 4rem; flex-shrink: 0; font-size: 8.5pt; text-align: right; }

    /* ── Comparison table ── */
    .comp-improved { color: #2e7d32; font-weight: bold; }
    .comp-worse    { color: #b71c1c; }
    .comp-same     { color: #888; }

    /* ── Print ── */
    @media print {
      body { font-size: 10pt; }
      .no-print { display: none !important; }
      .page { padding: 10mm; max-width: 100%; }
      a { color: inherit; text-decoration: none; }
      .page-break { page-break-before: always; }
    }

    /* ── Screen controls ── */
    .print-bar {
      background: #2e7d32;
      color: #fff;
      padding: .75rem 1.5rem;
      display: flex;
      align-items: center;
      gap: 1rem;
      position: sticky;
      top: 0;
      z-index: 100;
    }
    .print-bar h1 { font-size: 1rem; color: #fff; margin: 0; }
    .btn-print {
      background: #fff;
      color: #2e7d32;
      border: none;
      padding: .4rem 1.25rem;
      border-radius: 20px;
      font-size: .9rem;
      font-weight: bold;
      cursor: pointer;
    }
    .btn-print:hover { background: #f1f8e9; }
    .back-link { color: rgba(255,255,255,.85); font-size: .85rem; text-decoration: none; margin-left: auto; }
  </style>
</head>
<body>

<!-- Screen-only controls -->
<div class="print-bar no-print">
  <h1>📄 Bericht für die Lehrerin</h1>
  <button class="btn-print" onclick="window.print()">🖨️ Drucken / Als PDF speichern</button>
  <a href="<?= url('/admin/dashboard') ?>" class="back-link">← Dashboard</a>
</div>

<div class="page">

  <!-- ══════════════════════ KOPFZEILE ══════════════════════ -->
  <div class="report-header">
    <div>
      <div class="report-logo">⛏️ Lennarts Diktat-Trainer</div>
      <h1><?= htmlspecialchars($child['display_name']) ?> — Lernbericht</h1>
      <p style="margin-top:.25rem;font-size:10pt;color:#555">
        Klasse <?= htmlspecialchars($child['grade_level'] ?? '?') ?> ·
        <?= htmlspecialchars($child['school_type'] ?? '') ?> ·
        Bericht erstellt: <?= $reportDate ?> von <?= htmlspecialchars($adminName) ?>
      </p>
    </div>
    <div class="report-meta">
      Einstufungstest:<br><?= date('d.m.Y', strtotime($initialTest['completed_at'])) ?><br>
      <?php if (!$isSameTest): ?>
      Letzter Test:<br><?= date('d.m.Y', strtotime($latestTest['completed_at'])) ?><br>
      <?php endif; ?>
      Zeitraum: <?= htmlspecialchars($activity['first_session'] ?? '—') ?>
      <?php if ($activity['last_session'] && $activity['last_session'] !== $activity['first_session']): ?>
        bis <?= htmlspecialchars($activity['last_session']) ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══════════════════════ KURZÜBERSICHT ══════════════════════ -->
  <h2>Kurzübersicht</h2>
  <div class="summary-grid">
    <div class="summary-box">
      <div class="val"><?= (int)$activity['total_sessions'] ?></div>
      <div class="lbl">Übungseinheiten</div>
    </div>
    <div class="summary-box">
      <div class="val"><?= $totalMinutes ?></div>
      <div class="lbl">Minuten geübt</div>
    </div>
    <div class="summary-box">
      <div class="val"><?= $totalWords ?></div>
      <div class="lbl">Wörter bearbeitet</div>
    </div>
    <div class="summary-box">
      <div class="val"><?= $accuracyPct ?>%</div>
      <div class="lbl">Trefferquote</div>
    </div>
  </div>

  <?php if (!empty($strengths)): ?>
  <p>
    <strong>Stärken:</strong>
    <?= implode(', ', array_map(fn($r) =>
      ($categoryDesc[$r['category']] ?? $r['category']) . ' (' . $r['category'] . ')',
      $strengths
    )) ?>
  </p>
  <?php endif; ?>

  <?php if (!empty($weaknesses)): ?>
  <p>
    <strong>Aktuelle Schwachstellen:</strong>
    <?= implode(', ', array_map(fn($r) =>
      ($categoryDesc[$r['category']] ?? $r['category']) . ' (' . $r['category'] . ')',
      $weaknesses
    )) ?>
  </p>
  <?php endif; ?>

  <!-- ══════════════════════ FEHLERPROFIL ══════════════════════ -->
  <h2>Fehlerprofil — aktueller Stand</h2>
  <p style="font-size:9pt;color:#666">Fehlerrate pro Kategorie. Grün &lt; 15 % = gut beherrscht, Rot &gt; 60 % = starker Förderbedarf.</p>

  <?php
  $currentBlock = null;
  foreach ($allCategories as $cat => $_):
    $r = $latestMap[$cat] ?? null;
    if (!$r) continue;
    $block = $r['block'];
    $rate  = (float)$r['error_rate'];
    $sev   = $r['severity'] ?? 'none';
    $fill  = severityFill($sev);
    if ($block !== $currentBlock):
      $currentBlock = $block;
  ?>
    <h3><?= htmlspecialchars($blockLabel[$block] ?? $block) ?></h3>
    <div class="bar-chart">
  <?php endif; ?>
      <div class="bar-row">
        <span class="bar-label"><?= htmlspecialchars($cat) ?></span>
        <div class="bar-outer">
          <div class="bar-fill" style="width:<?= pct2w($rate) ?>%;background:<?= $fill ?>"></div>
        </div>
        <span class="bar-pct"><?= round($rate * 100) ?>%</span>
        <span class="bar-delta">
          <?php $comp = $compMap[$cat] ?? null; ?>
          <?php if ($comp && $comp['delta'] !== null): ?>
            <?php $d = (float)$comp['delta']; ?>
            <span class="<?= $d < 0 ? 'comp-improved' : ($d > 0 ? 'comp-worse' : 'comp-same') ?>">
              <?= $d < 0 ? '↓' : ($d > 0 ? '↑' : '=') ?> <?= abs(round($d * 100)) ?>%
            </span>
          <?php endif; ?>
        </span>
      </div>
  <?php
  // Close bar-chart div after last category of a block
  $cats = array_keys($allCategories);
  $nextCat = $cats[array_search($cat, $cats) + 1] ?? null;
  $nextBlock = $nextCat ? ($latestMap[$nextCat]['block'] ?? null) : null;
  if ($nextBlock !== $currentBlock): ?>
    </div>
  <?php endif; ?>
  <?php endforeach; ?>

  <!-- ══════════════════════ VORHER/NACHHER TABELLE ══════════════════════ -->
  <?php if (!$isSameTest && !empty($comparison)): ?>
  <div class="page-break"></div>
  <h2>Vergleich: Einstufungstest → Aktuell</h2>
  <table>
    <thead>
      <tr>
        <th>Kategorie</th>
        <th>Bezeichnung</th>
        <th>Fehlerrate vorher</th>
        <th>Fehlerrate aktuell</th>
        <th>Veränderung</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($comparison as $comp):
        $d = $comp['delta'];
        $dStr = $d !== null
                ? (($d < 0 ? '↓ −' : ($d > 0 ? '↑ +' : '= ')) . abs(round($d * 100)) . ' %')
                : '—';
        $cls = $d === null ? '' : ($d < 0 ? 'comp-improved' : ($d > 0 ? 'comp-worse' : 'comp-same'));
      ?>
      <tr>
        <td><?= htmlspecialchars($comp['category']) ?></td>
        <td><?= htmlspecialchars($categoryDesc[$comp['category']] ?? $comp['category']) ?></td>
        <td>
          <?= $comp['old_rate'] !== null ? round($comp['old_rate'] * 100) . ' %' : '—' ?>
          <?php if ($comp['old_severity']): ?>
            <small style="color:<?= $severityColor[$comp['old_severity']] ?? '#888' ?>">
              (<?= $severityLabel[$comp['old_severity']] ?? '' ?>)
            </small>
          <?php endif; ?>
        </td>
        <td>
          <?= $comp['new_rate'] !== null ? round($comp['new_rate'] * 100) . ' %' : '—' ?>
          <?php if ($comp['new_severity']): ?>
            <small style="color:<?= $severityColor[$comp['new_severity']] ?? '#888' ?>">
              (<?= $severityLabel[$comp['new_severity']] ?? '' ?>)
            </small>
          <?php endif; ?>
        </td>
        <td class="<?= $cls ?>"><?= htmlspecialchars($dStr) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- ══════════════════════ AKTIVITÄTSDETAILS ══════════════════════ -->
  <h2>Übungsdetails</h2>
  <table>
    <tr>
      <th>Einheiten gesamt</th>
      <td><?= (int)$activity['total_sessions'] ?></td>
      <th>Bearbeitete Wörter</th>
      <td><?= $totalWords ?></td>
    </tr>
    <tr>
      <th>Gesamtdauer</th>
      <td><?= $totalMinutes ?> Minuten</td>
      <th>Trefferquote (1. Versuch)</th>
      <td>
        <?php $p1 = $totalWords > 0 ? round($activity['correct_first'] / $totalWords * 100) : 0; ?>
        <?= $p1 ?>%
      </td>
    </tr>
    <tr>
      <th>Zeitraum</th>
      <td colspan="3">
        <?= htmlspecialchars($activity['first_session'] ?? '—') ?>
        <?php if ($activity['last_session']): ?>
          bis <?= htmlspecialchars($activity['last_session']) ?>
        <?php endif; ?>
      </td>
    </tr>
  </table>

  <?php if (!empty($errorDistribution)): ?>
  <h3 style="margin-top:1rem">Fehler-Schwerpunkte (Übungseinheiten)</h3>
  <table>
    <thead>
      <tr>
        <th>Kategorie</th>
        <th>Bezeichnung</th>
        <th>Fehler-Häufigkeit</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($errorDistribution as $ed): ?>
      <tr>
        <td><?= htmlspecialchars($ed['primary_category']) ?></td>
        <td><?= htmlspecialchars($categoryDesc[$ed['primary_category']] ?? $ed['primary_category']) ?></td>
        <td><?= (int)$ed['c'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- ══════════════════════ KI-ANMERKUNGEN ══════════════════════ -->
  <?php if (!empty($amendments)): ?>
  <h2>Beobachtungen des KI-Lernsystems</h2>
  <p style="font-size:9pt;color:#666">Das KI-System hat folgende Auffälligkeiten festgestellt:</p>
  <?php foreach ($amendments as $a): ?>
  <div style="background:#f9f9f9;border-left:3px solid #2e7d32;padding:.5rem .75rem;margin-bottom:.5rem;font-size:10pt;">
    <strong><?= date('d.m.Y', strtotime($a['created_at'])) ?></strong> —
    <?= htmlspecialchars($a['ai_reasoning']) ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- ══════════════════════ FUSSNOTE ══════════════════════ -->
  <hr style="margin-top:2rem;border:none;border-top:1px solid #ccc">
  <p style="font-size:8pt;color:#888;margin-top:.5rem">
    Dieser Bericht wurde automatisch vom Lennarts Diktat-Trainer generiert.
    Die Auswertung basiert auf <?= (int)$activity['total_sessions'] ?> Übungseinheiten
    mit insgesamt <?= $totalWords ?> bearbeiteten Wörtern.
    Erstellt am <?= $reportDate ?> · Für <?= htmlspecialchars($child['display_name']) ?>,
    Klasse <?= htmlspecialchars($child['grade_level'] ?? '?') ?>.
  </p>

</div><!-- .page -->

</body>
</html>
