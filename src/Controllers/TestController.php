<?php

namespace App\Controllers;

use App\Helpers\Auth;
use App\Services\TTSService;
use App\Services\ProgressTestService;

/**
 * TestController — Einstufungstest (und später Fortschrittstests)
 *
 * Routen:
 *   GET  /learn/test               → show()
 *   POST /learn/test               → startTest()
 *   GET  /learn/test/tts           → getTts()
 *   POST /learn/test/answer        → submitAnswer()
 *   POST /learn/test/section-complete → completeSection()
 *   POST /learn/test/pause         → pauseTest()
 *   GET  /learn/test/results       → showResults()
 */
class TestController
{
    // ── Fehlerkategorien pro Block ────────────────────────────────────────

    private const BLOCK_CATEGORIES = [
        'A' => ['A1', 'A2', 'A3'],
        'B' => ['B1', 'B2', 'B3', 'B4', 'B5'],
        'C' => ['C1', 'C2', 'C3'],
        'D' => ['D1', 'D2', 'D3', 'D4'],
    ];

    // Anzahl Wörter pro Kategorie im Einstufungstest
    private const WORDS_PER_CATEGORY = 3;

    // Menschenlesbare Block-Bezeichnungen
    private const BLOCK_LABELS = [
        'A' => 'Laut-Buchstaben-Zuordnung',
        'B' => 'Regelwissen',
        'C' => 'Ableitungswissen',
        'D' => 'Groß-/Kleinschreibung',
    ];

    // ── Öffentliche Handler ───────────────────────────────────────────────

    /**
     * GET /learn/test
     * Zeigt den aktuellen Test-Zustand: Start-Screen, Test oder Ergebnis-Weiterleitung.
     */
    public static function show(): void
    {
        Auth::requireRole('child');
        $userId = (int)$_SESSION['user_id'];
        $theme  = self::loadTheme($_SESSION['theme'] ?? 'minecraft');

        $test = self::loadActiveTest($userId);

        if (!$test) {
            $hasCompleted = self::hasCompletedInitialTest($userId);
            $viewState    = 'start';
            $section      = null;
            $item         = null;
            $progress     = null;
            require __DIR__ . '/../Views/learn/test.php';
            return;
        }

        // Aktuelle Sektion finden
        $section = self::getCurrentSection($test['id']);

        if (!$section) {
            // Alle Sektionen abgeschlossen
            self::markTestCompleted($test['id'], $userId);
            header('Location: /learn/test/results?test_id=' . $test['id']);
            exit;
        }

        // Sektion auf in_progress setzen wenn noch pending
        if ($section['status'] === 'pending') {
            db()->prepare(
                "UPDATE test_sections SET status='in_progress', started_at=CURRENT_TIMESTAMP WHERE id=?"
            )->execute([$section['id']]);
            $section['status'] = 'in_progress';
        }

        $item     = self::getNextUnansweredItem($section['id']);
        $progress = self::getTestProgress($test['id']);
        $viewState = 'test';

        require __DIR__ . '/../Views/learn/test.php';
    }

    /**
     * POST /learn/test
     * Startet einen neuen Einstufungs- oder Fortschrittstest.
     * POST-Parameter: type = 'initial' | 'progress'
     */
    public static function startTest(): void
    {
        Auth::requireRole('child');
        Auth::verifyCsrf();
        $userId     = (int)$_SESSION['user_id'];
        $gradeLevel = (int)($_SESSION['grade_level'] ?? 4);
        $type       = in_array($_POST['type'] ?? '', ['initial','progress'])
                      ? $_POST['type'] : 'initial';

        // Laufende Tests abbrechen
        db()->prepare(
            "UPDATE tests SET status='aborted' WHERE user_id=? AND status IN ('pending','in_progress')"
        )->execute([$userId]);

        // Beim Fortschrittstest: Wörter des letzten Tests ausschließen
        $excludeWordIds = [];
        if ($type === 'progress') {
            $lastTest = db()->prepare(
                "SELECT t.id FROM tests t WHERE t.user_id=? AND t.status='completed'
                 ORDER BY t.completed_at DESC LIMIT 1"
            );
            $lastTest->execute([$userId]);
            $lastTestRow = $lastTest->fetch();
            if ($lastTestRow) {
                $exStmt = db()->prepare(
                    "SELECT DISTINCT ti.word_id FROM test_items ti
                     JOIN test_sections ts ON ti.section_id=ts.id
                     WHERE ts.test_id=? AND ti.word_id IS NOT NULL"
                );
                $exStmt->execute([$lastTestRow['id']]);
                $excludeWordIds = $exStmt->fetchAll(\PDO::FETCH_COLUMN);
            }
        }

        $db = db();
        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO tests (user_id, type, status) VALUES (?, ?, 'in_progress')"
            )->execute([$userId, $type]);
            $testId = (int)$db->lastInsertId();

            foreach (['A', 'B', 'C', 'D'] as $i => $block) {
                $db->prepare(
                    "INSERT INTO test_sections (test_id, block, status, order_index) VALUES (?, ?, 'pending', ?)"
                )->execute([$testId, $block, $i]);
                $sectionId = (int)$db->lastInsertId();

                $words = self::selectWordsForSection($block, $gradeLevel, $excludeWordIds);
                self::createTestItems($sectionId, $block, $words);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('TestController::startTest — ' . $e->getMessage());
            header('Location: /learn?error=test_start_failed');
            exit;
        }

        header('Location: /learn/test');
        exit;
    }

    /**
     * GET /learn/test/tts?item_id=X&speed=normal
     * Liefert TTS-Audio (binary) oder JSON-Config für Browser-TTS.
     * Setzt played_at beim ersten Aufruf, erhöht replay_count danach.
     */
    public static function getTts(): void
    {
        Auth::requireRole('child');
        $userId = (int)$_SESSION['user_id'];
        $itemId = (int)($_GET['item_id'] ?? 0);
        $speed  = in_array($_GET['speed'] ?? '', ['normal', 'slow']) ? $_GET['speed'] : 'normal';

        // Item validieren — muss zum aktiven Test des Users gehören
        $stmt = db()->prepare("
            SELECT ti.id, ti.format, ti.played_at, ti.replay_count,
                   w.word, s.sentence
            FROM test_items ti
            JOIN test_sections ts ON ti.section_id = ts.id
            JOIN tests t          ON ts.test_id    = t.id
            LEFT JOIN words     w ON ti.word_id     = w.id
            LEFT JOIN sentences s ON ti.sentence_id = s.id
            WHERE ti.id = ? AND t.user_id = ? AND t.status = 'in_progress'
        ");
        $stmt->execute([$itemId, $userId]);
        $item = $stmt->fetch();

        if (!$item) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Item nicht gefunden']);
            exit;
        }

        // Text bestimmen
        $text = '';
        if ($item['format'] === 'sentence' && $item['sentence']) {
            $text = $item['sentence'];
        } elseif ($item['word']) {
            $text = $item['word'];
        }
        if ($text === '') {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Kein Text verfügbar']);
            exit;
        }

        // Bei Gap-Format den Platzhalter etwas aussprechen
        if ($item['format'] === 'gap') {
            $text = str_replace(['____', '___', '__'], ' ... ', $text);
        }

        // played_at / replay_count pflegen
        if ($item['played_at'] === null) {
            db()->prepare("UPDATE test_items SET played_at=CURRENT_TIMESTAMP WHERE id=?")
               ->execute([$itemId]);
        } else {
            db()->prepare("UPDATE test_items SET replay_count=replay_count+1 WHERE id=?")
               ->execute([$itemId]);
        }

        // TTS generieren
        try {
            $tts = new TTSService($userId);

            if ($tts->isBrowserTTS()) {
                $cfg = $tts->getBrowserConfig();
                header('Content-Type: application/json');
                echo json_encode([
                    'browser' => true,
                    'text'    => $text,
                    'lang'    => $cfg['lang']  ?? 'de-DE',
                    'rate'    => $speed === 'slow' ? 0.6 : 1.0,
                ]);
                exit;
            }

            $result = $tts->synthesize($text, null, $speed);
            if ($result) {
                header('Content-Type: ' . $result['mime']);
                header('Cache-Control: no-store, no-cache');
                echo $result['audio'];
                exit;
            }
        } catch (\Throwable $e) {
            error_log('TestController::getTts TTS-Fehler — ' . $e->getMessage());
        }

        // Fallback: Browser-TTS
        header('Content-Type: application/json');
        echo json_encode([
            'browser' => true,
            'text'    => $text,
            'lang'    => 'de-DE',
            'rate'    => $speed === 'slow' ? 0.6 : 1.0,
        ]);
        exit;
    }

    /**
     * POST /learn/test/answer  (AJAX, JSON-Body)
     * Speichert die Antwort des Kindes und gibt Feedback zurück.
     */
    public static function submitAnswer(): void
    {
        Auth::requireRole('child');
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!isset($data['csrf_token'], $data['item_id'], $data['user_input'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Ungültige Anfrage']);
            exit;
        }
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $data['csrf_token'])) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF-Fehler']);
            exit;
        }

        $userId        = (int)$_SESSION['user_id'];
        $itemId        = (int)$data['item_id'];
        $userInput     = trim((string)($data['user_input'] ?? ''));
        $responseTimeMs = max(0, (int)($data['response_time_ms'] ?? 0));

        // Item laden — nur noch unbeantwortete Items
        $stmt = db()->prepare("
            SELECT ti.id, ti.section_id, ti.format,
                   w.word, w.primary_category,
                   ts.block
            FROM test_items ti
            JOIN test_sections ts ON ti.section_id = ts.id
            JOIN tests t          ON ts.test_id    = t.id
            LEFT JOIN words w     ON ti.word_id    = w.id
            WHERE ti.id = ? AND t.user_id = ? AND t.status = 'in_progress'
              AND ti.answered_at IS NULL
        ");
        $stmt->execute([$itemId, $userId]);
        $item = $stmt->fetch();

        if (!$item) {
            http_response_code(404);
            echo json_encode(['error' => 'Item nicht gefunden oder bereits beantwortet']);
            exit;
        }

        // Antwort bewerten
        $correct        = $item['word'] ?? '';
        $block          = $item['block'];
        $isCorrect      = self::scoreAnswer($userInput, $correct, $block);
        $errorCategories = $isCorrect ? [] : [$item['primary_category'] ?? ''];

        // In DB speichern
        db()->prepare("
            UPDATE test_items
            SET user_input       = ?,
                is_correct       = ?,
                answered_at      = CURRENT_TIMESTAMP,
                response_time_ms = ?,
                error_categories = ?
            WHERE id = ?
        ")->execute([
            $userInput,
            $isCorrect ? 1 : 0,
            $responseTimeMs,
            json_encode($errorCategories),
            $itemId,
        ]);

        // Nächstes unbeantwortetes Item
        $nextItem = self::getNextUnansweredItem($item['section_id']);

        echo json_encode([
            'correct'        => $isCorrect,
            'correct_answer' => $isCorrect ? null : $correct,
            'next_item_id'   => $nextItem ? (int)$nextItem['id'] : null,
            'section_done'   => ($nextItem === null),
            'feedback_text'  => $isCorrect
                ? '✅ Richtig! +XP'
                : ('❌ Das Wort lautet: <strong>' . htmlspecialchars($correct) . '</strong>'),
        ]);
        exit;
    }

    /**
     * POST /learn/test/section-complete  (AJAX, JSON-Body)
     * Schließt eine Sektion ab, prüft Ermüdung, gibt nächste Sektion zurück.
     */
    public static function completeSection(): void
    {
        Auth::requireRole('child');
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!isset($data['csrf_token'], $data['section_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Ungültige Anfrage']);
            exit;
        }
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $data['csrf_token'])) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF-Fehler']);
            exit;
        }

        $userId    = (int)$_SESSION['user_id'];
        $sectionId = (int)$data['section_id'];

        // Sektion validieren
        $stmt = db()->prepare("
            SELECT ts.*, t.id AS test_id
            FROM test_sections ts
            JOIN tests t ON ts.test_id = t.id
            WHERE ts.id = ? AND t.user_id = ? AND t.status = 'in_progress'
        ");
        $stmt->execute([$sectionId, $userId]);
        $section = $stmt->fetch();

        if (!$section) {
            http_response_code(404);
            echo json_encode(['error' => 'Sektion nicht gefunden']);
            exit;
        }

        // Ermüdungs-Analyse
        $fatigue = self::checkFatigue($sectionId);

        // Sektion abschließen
        db()->prepare(
            "UPDATE test_sections SET status='completed', completed_at=CURRENT_TIMESTAMP,
             ai_recommendation=? WHERE id=?"
        )->execute([json_encode($fatigue), $sectionId]);

        // Nächste Sektion
        $next     = self::getNextPendingSection($section['test_id']);
        $testDone = ($next === null);

        echo json_encode([
            'section_done'    => true,
            'fatigue'         => $fatigue,
            'recommend_pause' => $fatigue['recommend_pause'],
            'next_section_id' => $next ? (int)$next['id'] : null,
            'next_block'      => $next ? $next['block'] : null,
            'test_done'       => $testDone,
        ]);
        exit;
    }

    /**
     * POST /learn/test/pause  (AJAX, JSON-Body)
     * Hält den Test an (session_count erhöhen für Multi-Session-Tracking).
     */
    public static function pauseTest(): void
    {
        Auth::requireRole('child');
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $data['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF-Fehler']);
            exit;
        }

        $userId = (int)$_SESSION['user_id'];
        db()->prepare(
            "UPDATE tests SET session_count=session_count+1 WHERE user_id=? AND status='in_progress'"
        )->execute([$userId]);

        echo json_encode(['paused' => true]);
        exit;
    }

    /**
     * GET /learn/test/results?test_id=X
     * Ergebnisseite nach Testabschluss.
     */
    public static function showResults(): void
    {
        Auth::requireRole('child');
        $userId = (int)$_SESSION['user_id'];
        $testId = (int)($_GET['test_id'] ?? 0);

        $stmt = db()->prepare("SELECT * FROM tests WHERE id=? AND user_id=?");
        $stmt->execute([$testId, $userId]);
        $test = $stmt->fetch();

        if (!$test) {
            header('Location: /learn');
            exit;
        }

        // Ergebnisse pro Sektion für die Zusammenfassung
        $sectionsStmt = db()->prepare("
            SELECT ts.block,
                   COUNT(ti.id)                                              AS total,
                   SUM(CASE WHEN ti.is_correct = 1 THEN 1 ELSE 0 END)       AS correct,
                   SUM(CASE WHEN ti.is_correct = 0 THEN 1 ELSE 0 END)       AS wrong
            FROM test_sections ts
            LEFT JOIN test_items ti ON ti.section_id = ts.id
            WHERE ts.test_id = ?
            GROUP BY ts.id, ts.block
            ORDER BY ts.order_index
        ");
        $sectionsStmt->execute([$testId]);
        $sectionResults = $sectionsStmt->fetchAll();

        // Analyse-Status: pending | done
        $resultCount = db()->prepare("SELECT COUNT(*) FROM test_results WHERE test_id=?");
        $resultCount->execute([$testId]);
        $analysisStatus = ((int)$resultCount->fetchColumn() > 0) ? 'done' : 'pending';

        // Bei Fortschrittstests: Vergleich mit vorherigem Test
        $comparison = null;
        if ($test['type'] === 'progress' && $analysisStatus === 'done') {
            // Vorherigen abgeschlossenen Test (nicht dieser) suchen
            $prevStmt = db()->prepare(
                "SELECT id FROM tests WHERE user_id=? AND status='completed' AND id != ?
                 ORDER BY completed_at DESC LIMIT 1"
            );
            $prevStmt->execute([$userId, $testId]);
            $prevTest = $prevStmt->fetch();
            if ($prevTest) {
                $comparison = ProgressTestService::compareTests((int)$prevTest['id'], $testId);
            }
        }

        $theme     = self::loadTheme($_SESSION['theme'] ?? 'minecraft');
        $viewState = 'results';
        $section   = null;
        $item      = null;
        $progress  = null;

        require __DIR__ . '/../Views/learn/test.php';
    }

    // ── Private Hilfsfunktionen ───────────────────────────────────────────

    private static function loadActiveTest(int $userId): ?array
    {
        $stmt = db()->prepare(
            "SELECT * FROM tests WHERE user_id=? AND status='in_progress' ORDER BY started_at DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    private static function hasCompletedInitialTest(int $userId): bool
    {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM tests WHERE user_id=? AND type='initial' AND status='completed'"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function getCurrentSection(int $testId): ?array
    {
        $stmt = db()->prepare(
            "SELECT * FROM test_sections WHERE test_id=? AND status IN ('pending','in_progress')
             ORDER BY order_index ASC LIMIT 1"
        );
        $stmt->execute([$testId]);
        return $stmt->fetch() ?: null;
    }

    private static function getNextPendingSection(int $testId): ?array
    {
        $stmt = db()->prepare(
            "SELECT * FROM test_sections WHERE test_id=? AND status='pending'
             ORDER BY order_index ASC LIMIT 1"
        );
        $stmt->execute([$testId]);
        return $stmt->fetch() ?: null;
    }

    private static function getNextUnansweredItem(int $sectionId): ?array
    {
        $stmt = db()->prepare(
            "SELECT * FROM test_items WHERE section_id=? AND answered_at IS NULL
             ORDER BY order_index ASC LIMIT 1"
        );
        $stmt->execute([$sectionId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Wählt Wörter für eine Sektion aus. Pro Unterkategorie WORDS_PER_CATEGORY Wörter.
     * Fallback auf grade_level-unabhängige Suche wenn nicht genug Wörter vorhanden.
     *
     * @param array $excludeIds  Wort-IDs die ausgeschlossen werden sollen (z.B. vom letzten Test)
     */
    private static function selectWordsForSection(string $block, int $gradeLevel, array $excludeIds = []): array
    {
        $categories = self::BLOCK_CATEGORIES[$block] ?? [];
        $words      = [];
        $usedIds    = $excludeIds;

        foreach ($categories as $category) {
            $notIn  = count($usedIds)
                      ? implode(',', array_fill(0, count($usedIds), '?'))
                      : '0';

            $stmt = db()->prepare("
                SELECT * FROM words
                WHERE primary_category = ?
                  AND grade_level <= ?
                  AND active = 1
                  AND id NOT IN ($notIn)
                ORDER BY RANDOM()
                LIMIT ?
            ");
            $params = array_merge([$category, $gradeLevel], $usedIds, [self::WORDS_PER_CATEGORY]);
            $stmt->execute($params);
            $found = $stmt->fetchAll();

            // Wenn nicht genug Wörter: ohne Klassenstufen-Filter
            if (count($found) < self::WORDS_PER_CATEGORY) {
                $notIn2 = count($usedIds)
                          ? implode(',', array_fill(0, count($usedIds), '?'))
                          : '0';
                $stmt2 = db()->prepare("
                    SELECT * FROM words
                    WHERE primary_category = ?
                      AND active = 1
                      AND id NOT IN ($notIn2)
                    ORDER BY difficulty ASC, RANDOM()
                    LIMIT ?
                ");
                $params2 = array_merge([$category], $usedIds, [self::WORDS_PER_CATEGORY]);
                $stmt2->execute($params2);
                $found = $stmt2->fetchAll();
            }

            foreach ($found as $w) {
                $usedIds[] = (int)$w['id'];
                $words[]   = $w;
            }
        }

        return $words;
    }

    /**
     * Legt test_items für eine Sektion an.
     * Block D → format='word' mit Groß-/Kleinschreibung im Scoring beachten.
     */
    private static function createTestItems(int $sectionId, string $block, array $words): void
    {
        // Reihenfolge mischen für natürlichere Testerfahrung
        shuffle($words);

        foreach ($words as $idx => $word) {
            db()->prepare(
                "INSERT INTO test_items (section_id, word_id, format, order_index) VALUES (?, ?, 'word', ?)"
            )->execute([$sectionId, (int)$word['id'], $idx]);
        }
    }

    /** Gibt Fortschritts-Daten für alle Sektionen des Tests zurück. */
    private static function getTestProgress(int $testId): array
    {
        $stmt = db()->prepare("
            SELECT ts.id, ts.block, ts.status, ts.order_index,
                   COUNT(ti.id)                                          AS total_items,
                   SUM(CASE WHEN ti.answered_at IS NOT NULL THEN 1 ELSE 0 END) AS answered_items
            FROM test_sections ts
            LEFT JOIN test_items ti ON ti.section_id = ts.id
            WHERE ts.test_id = ?
            GROUP BY ts.id
            ORDER BY ts.order_index
        ");
        $stmt->execute([$testId]);
        $sections = $stmt->fetchAll();

        $totalItems    = array_sum(array_column($sections, 'total_items'));
        $answeredItems = array_sum(array_column($sections, 'answered_items'));

        return [
            'total'    => (int)$totalItems,
            'answered' => (int)$answeredItems,
            'percent'  => $totalItems > 0 ? (int)round($answeredItems / $totalItems * 100) : 0,
            'sections' => $sections,
        ];
    }

    /**
     * Lokale Ermüdungs-Heuristik nach einer Sektion.
     * Prüft: steigende Antwortzeiten, hoher Replay-Count, hohe Fehlerrate.
     */
    private static function checkFatigue(int $sectionId): array
    {
        $stmt = db()->prepare(
            "SELECT response_time_ms, replay_count, is_correct
             FROM test_items
             WHERE section_id = ? AND answered_at IS NOT NULL
             ORDER BY order_index ASC"
        );
        $stmt->execute([$sectionId]);
        $items = $stmt->fetchAll();

        if (count($items) < 3) {
            return ['score' => 0, 'recommend_pause' => false];
        }

        // Antwortzeit-Trend
        $timeScore = 0;
        $times     = array_filter(array_column($items, 'response_time_ms'), fn($t) => $t > 0);
        if (count($times) >= 4) {
            $half       = (int)(count($times) / 2);
            $vals       = array_values($times);
            $avgFirst   = array_sum(array_slice($vals, 0, $half)) / $half;
            $avgSecond  = array_sum(array_slice($vals, $half)) / (count($vals) - $half);
            if ($avgFirst > 0 && $avgSecond > $avgFirst * 1.4) {
                $timeScore = 30;
            }
        }

        // Replay-Häufigkeit
        $replays     = array_column($items, 'replay_count');
        $avgReplay   = array_sum($replays) / count($replays);
        $replayScore = $avgReplay > 1.5 ? 30 : 0;

        // Fehlerrate
        $errors     = count(array_filter($items, fn($i) => (int)$i['is_correct'] === 0));
        $errorRate  = $errors / count($items);
        $errorScore = $errorRate > 0.5 ? 40 : ($errorRate > 0.35 ? 20 : 0);

        $total = $timeScore + $replayScore + $errorScore;

        return [
            'score'           => $total,
            'recommend_pause' => $total >= 60,
            'time_increasing' => $timeScore > 0,
            'high_replay'     => $replayScore > 0,
            'high_error_rate' => $errorScore > 0,
            'error_rate'      => (int)round($errorRate * 100),
            'avg_replay'      => round($avgReplay, 1),
        ];
    }

    /**
     * Bewertet eine Antwort.
     * Block D: exakter Vergleich (Groß-/Kleinschreibung relevant).
     * Alle anderen Blöcke: Vergleich ohne Beachtung der Groß-/Kleinschreibung.
     */
    private static function scoreAnswer(string $userInput, string $correct, string $block): bool
    {
        $userInput = trim($userInput);
        $correct   = trim($correct);

        if ($block === 'D') {
            return $userInput === $correct;
        }

        return mb_strtolower($userInput, 'UTF-8') === mb_strtolower($correct, 'UTF-8');
    }

    /** Markiert Test als abgeschlossen. */
    private static function markTestCompleted(int $testId, int $userId): void
    {
        db()->prepare(
            "UPDATE tests SET status='completed', completed_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?"
        )->execute([$testId, $userId]);
    }

    /** Lädt theme.json für das gegebene Theme (Fallback: minecraft). */
    private static function loadTheme(string $themeKey): array
    {
        $path = BASE_DIR . '/themes/' . preg_replace('/[^a-z0-9_-]/', '', $themeKey) . '/theme.json';
        if (!file_exists($path)) {
            $path = BASE_DIR . '/themes/minecraft/theme.json';
        }
        return json_decode(file_get_contents($path), true) ?? [];
    }
}
