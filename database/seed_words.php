<?php
/**
 * Seed-Script: Beispielwörter für alle 13 LRS-Kategorien (A1–D4)
 *
 * Aufruf: php database/seed_words.php
 * Oder über Admin → System → "Beispielwörter laden"
 *
 * Fügt nur fehlende Wörter ein (idempotent).
 */

define('BASE_DIR', dirname(__DIR__));
define('DATA_DIR', BASE_DIR . '/data');

require BASE_DIR . '/config/app.php';

$words = [

    // ── Block A: Laut-Buchstaben-Zuordnung ────────────────────────────────

    // A1 — Auslautverhärtung (Hund→Hunt, Weg→Wek)
    ['Hund',     'A1', 1, 4], ['Weg',       'A1', 1, 4], ['Rad',    'A1', 1, 4],
    ['Bad',      'A1', 1, 4], ['Feld',      'A1', 1, 4], ['Kind',   'A1', 1, 4],
    ['Geld',     'A1', 1, 4], ['Band',      'A1', 1, 4], ['Wand',   'A1', 1, 4],
    ['Lied',     'A1', 1, 4], ['Pferd',     'A1', 2, 4], ['Wald',   'A1', 2, 4],
    ['Sand',     'A1', 1, 4], ['Land',      'A1', 1, 4], ['Bund',   'A1', 2, 4],

    // A2 — Vokallänge kurz/lang (Miete↔Mitte)
    ['Miete',    'A2', 2, 4], ['Mitte',     'A2', 2, 4], ['Beet',   'A2', 1, 4],
    ['Bett',     'A2', 1, 4], ['Hüte',      'A2', 2, 4], ['Hütte',  'A2', 2, 4],
    ['bieten',   'A2', 2, 4], ['bitten',    'A2', 2, 4], ['Titel',  'A2', 2, 4],
    ['Ofen',     'A2', 1, 4], ['offen',     'A2', 1, 4], ['Wagen',  'A2', 1, 4],
    ['Wiese',    'A2', 1, 4], ['Siele',     'A2', 2, 4], ['Sille',  'A2', 2, 4],

    // A3 — Konsonantenhäufungen (Strumpf→Sturmpf)
    ['Strumpf',  'A3', 2, 4], ['Strand',    'A3', 2, 4], ['Pflanze','A3', 2, 4],
    ['Schrank',  'A3', 2, 4], ['Herbst',    'A3', 2, 4], ['Schmuck','A3', 2, 4],
    ['Pflug',    'A3', 2, 4], ['Knopf',     'A3', 2, 4], ['Straße', 'A3', 1, 4],
    ['Zweck',    'A3', 2, 4], ['Zwerg',     'A3', 2, 4], ['Strudel','A3', 3, 4],
    ['Schrift',  'A3', 2, 4], ['Streich',   'A3', 2, 4], ['Sprung', 'A3', 2, 4],

    // ── Block B: Regelwissen ──────────────────────────────────────────────

    // B1 — Doppelkonsonanten (Mutter→Muter)
    ['Mutter',   'B1', 1, 4], ['Teller',    'B1', 1, 4], ['Kammer', 'B1', 2, 4],
    ['Hammer',   'B1', 1, 4], ['Wasser',    'B1', 1, 4], ['Messer', 'B1', 1, 4],
    ['Butter',   'B1', 1, 4], ['Sommer',    'B1', 1, 4], ['Zimmer', 'B1', 1, 4],
    ['Rolle',    'B1', 1, 4], ['Welle',     'B1', 1, 4], ['Wolle',  'B1', 1, 4],
    ['Stille',   'B1', 2, 4], ['Hülle',     'B1', 2, 4], ['Fülle',  'B1', 2, 4],

    // B2 — ck und tz (Brücke→Brüke, Katze→Katse)
    ['Brücke',   'B2', 2, 4], ['Katze',     'B2', 1, 4], ['backen', 'B2', 1, 4],
    ['Stück',    'B2', 1, 4], ['Jacke',     'B2', 1, 4], ['Mütze',  'B2', 1, 4],
    ['Ecke',     'B2', 1, 4], ['Wecker',    'B2', 1, 4], ['Blitz',  'B2', 2, 4],
    ['Witze',    'B2', 2, 4], ['Spitze',    'B2', 2, 4], ['Lücke',  'B2', 2, 4],
    ['packen',   'B2', 1, 4], ['Schatz',    'B2', 2, 4], ['Rücken', 'B2', 2, 4],

    // B3 — ie / ih / i (Tier→Tir, ihm→im)
    ['Tier',     'B3', 1, 4], ['ihm',       'B3', 1, 4], ['ihn',    'B3', 1, 4],
    ['ihr',      'B3', 1, 4], ['Spiel',     'B3', 1, 4], ['Bier',   'B3', 1, 4],
    ['Liebe',    'B3', 1, 4], ['Rief',      'B3', 2, 4], ['Dieb',   'B3', 2, 4],
    ['Sieg',     'B3', 1, 4], ['Brief',     'B3', 1, 4], ['nieder', 'B3', 2, 4],
    ['sieben',   'B3', 1, 4], ['Lied',      'B3', 1, 4], ['Ried',   'B3', 3, 4],

    // B4 — Dehnungs-h (fahren→faren)
    ['fahren',   'B4', 1, 4], ['nehmen',    'B4', 1, 4], ['Mehl',   'B4', 2, 4],
    ['Stahl',    'B4', 2, 4], ['Kohl',      'B4', 1, 4], ['Stuhl',  'B4', 1, 4],
    ['Zahl',     'B4', 1, 4], ['zahlen',    'B4', 1, 4], ['Bahnen', 'B4', 2, 4],
    ['dehnen',   'B4', 2, 4], ['Lehne',     'B4', 2, 4], ['Wahl',   'B4', 1, 4],
    ['fühlen',   'B4', 2, 4], ['Höhle',     'B4', 2, 4], ['Kahle',  'B4', 2, 4],

    // B5 — sp und st (Straße→Sdrasse)
    ['Stein',    'B5', 1, 4], ['springen',  'B5', 1, 4], ['sprechen','B5',1, 4],
    ['stark',    'B5', 1, 4], ['spät',      'B5', 1, 4], ['stellen', 'B5',1, 4],
    ['spüren',   'B5', 2, 4], ['Sport',     'B5', 1, 4], ['Spule',  'B5', 2, 4],
    ['Stern',    'B5', 1, 4], ['Strauch',   'B5', 2, 4], ['Speck',  'B5', 1, 4],
    ['Sprung',   'B5', 2, 4], ['Stelle',    'B5', 1, 4], ['speisen','B5', 2, 4],

    // ── Block C: Ableitungswissen ─────────────────────────────────────────

    // C1 — ä vs. e (Hände→Hende)
    ['Hände',    'C1', 1, 4], ['Zähne',     'C1', 1, 4], ['Männer', 'C1', 1, 4],
    ['Käse',     'C1', 1, 4], ['Mädchen',   'C1', 1, 4], ['stärker','C1', 2, 4],
    ['älter',    'C1', 2, 4], ['Gärten',    'C1', 2, 4], ['Väter',  'C1', 2, 4],
    ['Läden',    'C1', 2, 4], ['Wärme',     'C1', 2, 4], ['zählen', 'C1', 1, 4],
    ['Täler',    'C1', 2, 4], ['Räder',     'C1', 2, 4], ['Häfen',  'C1', 2, 4],

    // C2 — äu vs. eu (Häuser→Heuser)
    ['Häuser',   'C2', 1, 4], ['Bäume',     'C2', 1, 4], ['Träume', 'C2', 2, 4],
    ['Mäuse',    'C2', 1, 4], ['Freude',    'C2', 1, 4], ['Leute',  'C2', 1, 4],
    ['läuft',    'C2', 2, 4], ['Sträuße',   'C2', 2, 4], ['Gebäude','C2', 2, 4],
    ['Neugier',  'C2', 3, 4], ['aufräumen', 'C2', 2, 4], ['treu',   'C2', 2, 4],
    ['häuft',    'C2', 2, 4], ['Läufer',    'C2', 2, 4], ['Bräute', 'C2', 3, 4],

    // C3 — dass / das
    ['dass',     'C3', 1, 4], ['das',       'C3', 1, 4], ['sodass', 'C3', 2, 4],
    ['Wissen',   'C3', 1, 4], ['Sehen',     'C3', 1, 4], ['Gehen',  'C3', 1, 4],
    ['Laufen',   'C3', 1, 4], ['Spielen',   'C3', 1, 4], ['Kochen', 'C3', 1, 4],
    ['Schreiben','C3', 2, 4], ['Träumen',   'C3', 2, 4], ['Glauben','C3', 2, 4],
    ['Sprechen', 'C3', 2, 4], ['Denken',    'C3', 2, 4], ['Fühlen', 'C3', 2, 4],

    // ── Block D: Groß-/Kleinschreibung ────────────────────────────────────

    // D1 — Konkrete Nomen (Fahrrad, Hund)
    ['Fahrrad',  'D1', 1, 4], ['Hund',      'D1', 1, 4], ['Katze',  'D1', 1, 4],
    ['Schule',   'D1', 1, 4], ['Tisch',     'D1', 1, 4], ['Stuhl',  'D1', 1, 4],
    ['Buch',     'D1', 1, 4], ['Haus',      'D1', 1, 4], ['Baum',   'D1', 1, 4],
    ['Auto',     'D1', 1, 4], ['Fenster',   'D1', 1, 4], ['Tür',    'D1', 1, 4],
    ['Bleistift','D1', 1, 4], ['Rucksack',  'D1', 1, 4], ['Schmetterling','D1',2,4],

    // D2 — Abstrakte Nomen (Freundschaft, Angst)
    ['Freundschaft','D2',2,4], ['Angst',    'D2', 1, 4], ['Liebe',  'D2', 1, 4],
    ['Hoffnung', 'D2', 2, 4], ['Freude',    'D2', 1, 4], ['Trauer', 'D2', 1, 4],
    ['Mut',      'D2', 1, 4], ['Freiheit',  'D2', 2, 4], ['Stärke', 'D2', 2, 4],
    ['Glaube',   'D2', 2, 4], ['Geduld',    'D2', 2, 4], ['Wahrheit','D2',2, 4],
    ['Weisheit', 'D2', 2, 4], ['Tapferkeit','D2',3, 4], ['Neugier', 'D2', 2, 4],

    // D3 — Nominalisierungen (das Laufen, beim Essen)
    ['Essen',    'D3', 1, 4], ['Laufen',    'D3', 1, 4], ['Spielen','D3', 1, 4],
    ['Lernen',   'D3', 1, 4], ['Schlafen',  'D3', 1, 4], ['Trinken','D3', 1, 4],
    ['Lachen',   'D3', 1, 4], ['Singen',    'D3', 1, 4], ['Malen',  'D3', 1, 4],
    ['Bauen',    'D3', 2, 4], ['Hören',     'D3', 1, 4], ['Denken', 'D3', 2, 4],
    ['Schreiben','D3', 2, 4], ['Lesen',     'D3', 1, 4], ['Kochen', 'D3', 1, 4],

    // D4 — Satzanfang
    ['Der',      'D4', 1, 4], ['Die',       'D4', 1, 4], ['Das',    'D4', 1, 4],
    ['Ein',      'D4', 1, 4], ['Eine',      'D4', 1, 4], ['Als',    'D4', 1, 4],
    ['Wenn',     'D4', 1, 4], ['Weil',      'D4', 1, 4], ['Obwohl', 'D4', 2, 4],
    ['Während',  'D4', 2, 4], ['Danach',    'D4', 1, 4], ['Zuerst', 'D4', 1, 4],
    ['Schließlich','D4',2,4], ['Deshalb',   'D4', 2, 4], ['Außerdem','D4',2, 4],
];

$db = db();
$stmt = $db->prepare(
    "INSERT OR IGNORE INTO words (word, primary_category, grade_level, difficulty, source, active)
     VALUES (?, ?, ?, ?, 'manual', 1)"
);

$inserted = 0;
$skipped  = 0;

foreach ($words as [$word, $cat, $diff, $grade]) {
    // Prüfen ob bereits vorhanden
    $exists = $db->prepare("SELECT COUNT(*) FROM words WHERE word=? AND primary_category=?");
    $exists->execute([$word, $cat]);
    if ((int)$exists->fetchColumn() > 0) {
        $skipped++;
        continue;
    }
    $stmt->execute([$word, $cat, $grade, $diff]);
    $inserted++;
}

$total = (int)$db->query("SELECT COUNT(*) FROM words WHERE active=1")->fetchColumn();

if (PHP_SAPI === 'cli') {
    echo "Fertig. $inserted neue Wörter eingefügt, $skipped bereits vorhanden.\n";
    echo "Gesamt aktive Wörter in DB: $total\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['inserted' => $inserted, 'skipped' => $skipped, 'total' => $total]);
}
