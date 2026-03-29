<?php

namespace App\Controllers;

use App\Helpers\Auth;
use App\Services\TTSService;
use App\Services\AIService;

/**
 * SessionController — Übungseinheiten
 *
 * Routen:
 *   GET  /learn/questlog              → showQuestlog()
 *   GET  /learn/session               → show()
 *   POST /learn/session/start         → startSession()
 *   GET  /learn/session/tts           → getTts()
 *   POST /learn/session/answer        → submitAnswer()
 *   POST /learn/session/complete      → completeSession()
 */
class SessionController
{
    // Levenshtein-Abstand ≤ 1 → Tippfehler → zweiter Versuch erlaubt
    private const TYPO_LEVENSHTEIN_MAX = 1;

    // ── Öffentliche Handler ───────────────────────────────────────────────

    /**
     * GET /learn/questlog
     * Abenteuermap: aktiver Plan mit Biomen + Quests.
     */
    public static function showQuestlog(): void
    {
        Auth::requireRole('child');
        $userId = (int)$_SESSION['user_id'];
        $theme  = self::loadTheme($_SESSION['theme'] ?? 'minecraft');

        // Aktiven Plan laden
        $planStmt = db()->prepare(
            "SELECT id FROM learning_plans WHERE user_id=? AND status='active'
             ORDER BY activated_at DESC LIMIT 1"
        );
        $planStmt->execute([$userId]);
        $activePlan = $planStmt->fetch() ?: null;

        $biomes = [];
        $activeUnit    = null;
        $totalQuests   = 0;
        $completedQuests = 0;

        if ($activePlan) {
            $biomeStmt = db()->prepare(
                "SELECT pb.id, pb.block, pb.name, pb.theme_biome, pb.order_index, pb.status
                 FROM plan_biomes pb
                 WHERE pb.plan_id = ?
                 ORDER BY pb.order_index"
            );
            $biomeStmt->execute([$activePlan['id']]);
            $biomeRows = $biomeStmt->fetchAll();

            // Theme-Biome icons
            $themeMap = [];
            foreach ($theme['biomes'] ?? [] as $tb) {
                $themeMap[$tb['id']] = $tb['icon'] ?? '🌍';
            }

            foreach ($biomeRows as &$biome) {
                $biome['icon'] = $themeMap[$biome['theme_biome']] ?? '🌍';

                $qStmt = db()->prepare(
                    "SELECT q.id, q.category, q.title, q.description,
                            q.order_index, q.status, q.quest_id,
                            COUNT(pu.id)                                         AS total_units,
                            SUM(CASE WHEN pu.status='completed' THEN 1 ELSE 0 END) AS done_units,
                            SUM(CASE WHEN pu.status='pending'   THEN 1 ELSE 0 END) AS pending_units
                     FROM quests q
                     LEFT JOIN plan_units pu ON pu.quest_id = q.id
                     WHERE q.biome_id = ?
                     GROUP BY q.id
                     ORDER BY q.order_index"
                );
                $qStmt->execute([$biome['id']]);
                $biome['quests'] = $qStmt->fetchAll();

                foreach ($biome['quests'] as $q) {
                    $totalQuests++;
                    if ($q['status'] === 'completed') $completedQuests++;
                }
            }
            unset($biome);
            $biomes = $biomeRows;

            // Aktuell aktive plan_unit finden
            $unitStmt = db()->prepare(
                "SELECT pu.id, pu.quest_id, pu.format, pu.word_count, pu.difficulty, pu.order_index
                 FROM plan_units pu
                 JOIN quests q      ON pu.quest_id   = q.id
                 JOIN plan_biomes pb ON q.biome_id    = pb.id
                 WHERE pb.plan_id = ? AND pu.status = 'active'
                 ORDER BY pb.order_index, q.order_index, pu.order_index
                 LIMIT 1"
            );
            $unitStmt->execute([$activePlan['id']]);
            $activeUnit = $unitStmt->fetch() ?: null;
        }

        // Streak + Statistiken
        $streakDays    = self::getStreakDays($userId);
        $totalSessions = (int)db()->prepare(
            "SELECT COUNT(*) FROM sessions WHERE user_id=? AND status='completed'"
        )->execute([$userId]) && 0 ?: (int)db()->query(
            "SELECT COUNT(*) FROM sessions WHERE user_id={$userId} AND status='completed'"
        )->fetchColumn();
        $childName     = $_SESSION['display_name'] ?? 'Kind';

        require __DIR__ . '/../Views/learn/questlog.php';
    }

    /**
     * GET /learn/session?unit_id=X
     * Zeigt die Übungseinheit (lädt oder erstellt Session).
     */
    public static function show(): void
    {
        Auth::requireRole('child');
        $userId = (int)$_SESSION['user_id'];
        $unitId = (int)($_GET['unit_id'] ?? 0);

        if (!$unitId) {
            header('Location: /learn/questlog');
            exit;
        }

        // plan_unit validieren — muss zum aktiven Plan des Kindes gehören
        $unit = self::validateUnit($unitId, $userId);
        if (!$unit) {
            header('Location: /learn/questlog');
            exit;
        }

        // Aktive Session für diese Unit finden
        $session = self::getActiveSession($userId, $unitId);

        $theme    = self::loadTheme($_SESSION['theme'] ?? 'minecraft');
        $items    = [];
        $progress = null;

        if ($session) {
            $items    = self::getSessionItems($session['id']);
            $progress = self::getSessionProgress($session['id']);
        }

        require __DIR__ . '/../Views/learn/session.php';
    }

    /**
     * POST /learn/session/start  (Form POST)
     * Erstellt eine neue Session für eine plan_unit und befüllt sie mit Items.
     */
    public static function startSession(): void
    {
        Auth::requireRole('child');
        Auth::verifyCsrf();
        $userId = (int)$_SESSION['user_id'];
        $unitId = (int)($_POST['unit_id'] ?? 0);

        if (!$unitId) {
            header('Location: /learn/questlog');
            exit;
        }

        $unit = self::validateUnit($unitId, $userId);
        if (!$unit) {
            header('Location: /learn/questlog');
            exit;
        }

        // Laufende Sessions für diese Unit abbrechen
        db()->prepare(
            "UPDATE sessions SET status='aborted' WHERE user_id=? AND plan_unit_id=? AND status='active'"
        )->execute([$userId, $unitId]);

        $db = db();
        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO sessions (user_id, plan_unit_id, status) VALUES (?, ?, 'active')"
            )->execute([$userId, $unitId]);
            $sessionId = (int)$db->lastInsertId();

            // Items für Session generieren
            self::createSessionItems($sessionId, $unit, $userId);

            $db->prepare(
                "UPDATE sessions SET total_items=(SELECT COUNT(*) FROM session_items WHERE session_id=?) WHERE id=?"
            )->execute([$sessionId, $sessionId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('SessionController::startSession — ' . $e->getMessage());
            header('Location: /learn/questlog?error=start_failed');
            exit;
        }

        header('Location: /learn/session?unit_id=' . $unitId);
        exit;
    }

    /**
     * GET /learn/session/tts?item_id=X&speed=normal
     * Liefert TTS-Audio oder JSON-Config für Browser-TTS.
     */
    public static function getTts(): void
    {
        Auth::requireRole('child');
        $userId = (int)$_SESSION['user_id'];
        $itemId = (int)($_GET['item_id'] ?? 0);
        $speed  = in_array($_GET['speed'] ?? '', ['normal', 'slow']) ? $_GET['speed'] : 'normal';

        // Item validieren — muss zur aktiven Session des Users gehören
        $stmt = db()->prepare("
            SELECT si.id, si.format,
                   w.word, w.primary_category,
                   si.tts_replays, si.tts_slow_replays,
                   s.sentence
            FROM session_items si
            JOIN sessions sess   ON si.session_id  = sess.id
            LEFT JOIN words w    ON si.word_id      = w.id
            LEFT JOIN sentences s ON si.sentence_id = s.id
            WHERE si.id = ? AND sess.user_id = ? AND sess.status = 'active'
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

        if ($item['format'] === 'gap') {
            $text = preg_replace('/_{2,}/', '...', $text);
        }

        // Replay-Counter pflegen
        if ($speed === 'slow') {
            db()->prepare("UPDATE session_items SET tts_slow_replays=tts_slow_replays+1 WHERE id=?")
               ->execute([$itemId]);
        } else {
            db()->prepare("UPDATE session_items SET tts_replays=tts_replays+1 WHERE id=?")
               ->execute([$itemId]);
        }

        try {
            $tts = new TTSService($userId);

            if ($tts->isBrowserTTS()) {
                $cfg = $tts->getBrowserConfig();
                header('Content-Type: application/json');
                echo json_encode([
                    'browser' => true,
                    'text'    => $text,
                    'lang'    => $cfg['lang'] ?? 'de-DE',
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
            error_log('SessionController::getTts TTS-Fehler — ' . $e->getMessage());
        }

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
     * POST /learn/session/answer  (AJAX, JSON-Body)
     * Speichert Antwort, gibt Feedback zurück.
     * Zweiter Versuch wird lokal (Levenshtein) entschieden — kein KI-Aufruf.
     */
    public static function submitAnswer(): void
    {
        Auth::requireRole('child');
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!hash_equals($_SESSION['csrf_token'] ?? '', $data['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF-Fehler']);
            exit;
        }

        $userId         = (int)$_SESSION['user_id'];
        $itemId         = (int)($data['item_id']         ?? 0);
        $userInput      = trim((string)($data['user_input']      ?? ''));
        $responseTimeMs = (int)($data['response_time_ms'] ?? 0);
        $attemptNumber  = (int)($data['attempt_number']  ?? 1);

        // Item validieren
        $stmt = db()->prepare("
            SELECT si.id, si.final_correct, si.second_try_allowed,
                   si.format, si.session_id,
                   w.word, w.primary_category,
                   s.sentence
            FROM session_items si
            JOIN sessions sess   ON si.session_id  = sess.id
            LEFT JOIN words w    ON si.word_id      = w.id
            LEFT JOIN sentences s ON si.sentence_id = s.id
            WHERE si.id = ? AND sess.user_id = ? AND sess.status = 'active'
        ");
        $stmt->execute([$itemId, $userId]);
        $item = $stmt->fetch();

        if (!$item) {
            http_response_code(404);
            echo json_encode(['error' => 'Item nicht gefunden']);
            exit;
        }

        if ($item['final_correct'] !== null) {
            http_response_code(422);
            echo json_encode(['error' => 'Item bereits beantwortet']);
            exit;
        }

        // Korrekte Antwort bestimmen
        $correct = '';
        if (in_array($item['format'], ['sentence', 'mini_diktat']) && $item['sentence']) {
            $correct = $item['sentence'];
        } elseif ($item['word']) {
            $correct = $item['word'];
        }

        // Für Gap: nur das Lückenwort vergleichen
        if ($item['format'] === 'gap' && $item['word']) {
            $correct = $item['word'];
        }

        // Korrektheit prüfen (Block D → case-sensitive, Rest → case-insensitive)
        $category  = $item['primary_category'] ?? '';
        $isBlockD  = str_starts_with($category, 'D');
        $isCorrect = $isBlockD
            ? (trim($correct) === trim($userInput))
            : (mb_strtolower(trim($correct)) === mb_strtolower(trim($userInput)));

        // Zweiter Versuch entscheiden (nur beim ersten Versuch)
        $secondTryAllowed = false;
        if (!$isCorrect && $attemptNumber === 1) {
            $secondTryAllowed = self::checkSecondTry($correct, $userInput, $category);
        }

        // Attempt speichern
        db()->prepare(
            "INSERT INTO session_attempts (item_id, attempt_number, user_input, is_correct, answered_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)"
        )->execute([$itemId, $attemptNumber, $userInput, (int)$isCorrect]);

        // Item finalisieren wenn: korrekt ODER kein zweiter Versuch ODER zweiter Versuch ausgeschöpft
        $isFinal = $isCorrect || !$secondTryAllowed || $attemptNumber >= 2;

        if ($isFinal) {
            // Wenn zweiter Versuch lief, schauen ob erster Versuch korrekt war
            $correctFirstTry  = ($isCorrect && $attemptNumber === 1) ? 1 : 0;
            $correctSecondTry = ($isCorrect && $attemptNumber === 2) ? 1 : 0;

            db()->prepare(
                "UPDATE session_items
                 SET final_correct=?, response_time_ms=?,
                     second_try_allowed=0
                 WHERE id=?"
            )->execute([(int)$isCorrect, $responseTimeMs, $itemId]);

            // Session-Statistiken aktualisieren
            if ($correctFirstTry) {
                db()->prepare("UPDATE sessions SET correct_first_try=correct_first_try+1 WHERE id=?")
                   ->execute([$item['session_id']]);
            } elseif ($correctSecondTry) {
                db()->prepare("UPDATE sessions SET correct_second_try=correct_second_try+1 WHERE id=?")
                   ->execute([$item['session_id']]);
            } else {
                db()->prepare("UPDATE sessions SET wrong_total=wrong_total+1 WHERE id=?")
                   ->execute([$item['session_id']]);
            }
        } else {
            // Zweiter Versuch erlaubt: markieren
            db()->prepare("UPDATE session_items SET second_try_allowed=1 WHERE id=?")
               ->execute([$itemId]);
        }

        // Feedback vorbereiten (local, kein AI bei jedem Item)
        $feedback = self::buildLocalFeedback($correct, $userInput, $isCorrect, $category, $secondTryAllowed && !$isFinal);

        // Nächstes Item laden
        $sessionId  = (int)$item['session_id'];
        $nextItem   = $isFinal ? self::getNextUnansweredItem($sessionId) : null;
        $isLastItem = ($nextItem === null && $isFinal);

        echo json_encode([
            'is_correct'        => $isCorrect,
            'is_final'          => $isFinal,
            'second_try'        => $secondTryAllowed && !$isFinal,
            'correct_answer'    => $isFinal ? $correct : null,
            'feedback'          => $feedback['text'],
            'hint'              => $feedback['hint'],
            'next_item_id'      => $nextItem ? $nextItem['id'] : null,
            'is_last_item'      => $isLastItem,
            'session_id'        => $sessionId,
        ]);
        exit;
    }

    /**
     * POST /learn/session/complete  (AJAX, JSON-Body)
     * Schließt eine Session ab, schreitet im Plan voran, ruft KI-Feedback (optional).
     */
    public static function completeSession(): void
    {
        Auth::requireRole('child');
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!hash_equals($_SESSION['csrf_token'] ?? '', $data['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF-Fehler']);
            exit;
        }

        $userId    = (int)$_SESSION['user_id'];
        $sessionId = (int)($data['session_id'] ?? 0);

        // Session validieren
        $sesStmt = db()->prepare(
            "SELECT s.*, pu.id AS unit_id, pu.quest_id,
                    q.biome_id, pb.plan_id
             FROM sessions s
             JOIN plan_units pu ON s.plan_unit_id = pu.id
             JOIN quests q      ON pu.quest_id    = q.id
             JOIN plan_biomes pb ON q.biome_id    = pb.id
             WHERE s.id = ? AND s.user_id = ? AND s.status = 'active'"
        );
        $sesStmt->execute([$sessionId, $userId]);
        $session = $sesStmt->fetch();

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'Session nicht gefunden']);
            exit;
        }

        // Alle Items müssen beantwortet sein
        $pending = (int)db()->prepare(
            "SELECT COUNT(*) FROM session_items WHERE session_id=? AND final_correct IS NULL"
        )->execute([$sessionId]) ? 0 : 99;
        $pendingStmt = db()->prepare(
            "SELECT COUNT(*) FROM session_items WHERE session_id=? AND final_correct IS NULL"
        );
        $pendingStmt->execute([$sessionId]);
        $pending = (int)$pendingStmt->fetchColumn();

        if ($pending > 0) {
            http_response_code(422);
            echo json_encode(['error' => "$pending Items noch offen"]);
            exit;
        }

        $db = db();
        $db->beginTransaction();
        try {
            // Session abschließen
            $db->prepare(
                "UPDATE sessions
                 SET status='completed', completed_at=CURRENT_TIMESTAMP,
                     duration_seconds = CAST((julianday('now') - julianday(started_at)) * 86400 AS INTEGER)
                 WHERE id=?"
            )->execute([$sessionId]);

            // plan_unit abschließen
            $db->prepare(
                "UPDATE plan_units SET status='completed', completed_at=CURRENT_TIMESTAMP WHERE id=?"
            )->execute([$session['unit_id']]);

            // Nächste plan_unit dieser Quest aktivieren
            $nextUnit = $db->prepare(
                "SELECT id FROM plan_units WHERE quest_id=? AND status='pending'
                 ORDER BY order_index ASC LIMIT 1"
            );
            $nextUnit->execute([$session['quest_id']]);
            $nextUnitRow = $nextUnit->fetch();

            $questCompleted = false;
            $biomeCompleted = false;
            $planCompleted  = false;

            if ($nextUnitRow) {
                $db->prepare("UPDATE plan_units SET status='active' WHERE id=?")
                   ->execute([$nextUnitRow['id']]);
            } else {
                // Quest abschließen
                $db->prepare(
                    "UPDATE quests SET status='completed', completed_at=CURRENT_TIMESTAMP WHERE id=?"
                )->execute([$session['quest_id']]);
                $questCompleted = true;

                // Nächste Quest des Bioms aktivieren
                $nextQuest = $db->prepare(
                    "SELECT id FROM quests WHERE biome_id=? AND status='locked'
                     ORDER BY order_index ASC LIMIT 1"
                );
                $nextQuest->execute([$session['biome_id']]);
                $nextQuestRow = $nextQuest->fetch();

                if ($nextQuestRow) {
                    $db->prepare(
                        "UPDATE quests SET status='active', unlocked_at=CURRENT_TIMESTAMP WHERE id=?"
                    )->execute([$nextQuestRow['id']]);

                    $firstUnit = $db->prepare(
                        "SELECT id FROM plan_units WHERE quest_id=? AND status='pending'
                         ORDER BY order_index ASC LIMIT 1"
                    );
                    $firstUnit->execute([$nextQuestRow['id']]);
                    $firstUnitRow = $firstUnit->fetch();
                    if ($firstUnitRow) {
                        $db->prepare("UPDATE plan_units SET status='active' WHERE id=?")
                           ->execute([$firstUnitRow['id']]);
                    }
                } else {
                    // Biom abschließen
                    // Alle Quests des Bioms sind done (oder skipped)
                    $openStmt = $db->prepare(
                        "SELECT COUNT(*) FROM quests WHERE biome_id=? AND status NOT IN ('completed','skipped')"
                    );
                    $openStmt->execute([$session['biome_id']]);
                    if ((int)$openStmt->fetchColumn() === 0) {
                        $db->prepare(
                            "UPDATE plan_biomes SET status='completed', completed_at=CURRENT_TIMESTAMP WHERE id=?"
                        )->execute([$session['biome_id']]);
                        $biomeCompleted = true;

                        // Nächstes Biom freischalten
                        $nextBiome = $db->prepare(
                            "SELECT id FROM plan_biomes WHERE plan_id=? AND status='locked'
                             ORDER BY order_index ASC LIMIT 1"
                        );
                        $nextBiome->execute([$session['plan_id']]);
                        $nextBiomeRow = $nextBiome->fetch();

                        if ($nextBiomeRow) {
                            $db->prepare(
                                "UPDATE plan_biomes SET status='active', unlocked_at=CURRENT_TIMESTAMP WHERE id=?"
                            )->execute([$nextBiomeRow['id']]);

                            // Erste Quest + Unit des neuen Bioms aktivieren
                            $firstQ = $db->prepare(
                                "SELECT id FROM quests WHERE biome_id=? AND status='locked'
                                 ORDER BY order_index ASC LIMIT 1"
                            );
                            $firstQ->execute([$nextBiomeRow['id']]);
                            $firstQRow = $firstQ->fetch();
                            if ($firstQRow) {
                                $db->prepare(
                                    "UPDATE quests SET status='active', unlocked_at=CURRENT_TIMESTAMP WHERE id=?"
                                )->execute([$firstQRow['id']]);

                                $firstU = $db->prepare(
                                    "SELECT id FROM plan_units WHERE quest_id=? AND status='pending'
                                     ORDER BY order_index ASC LIMIT 1"
                                );
                                $firstU->execute([$firstQRow['id']]);
                                $firstURow = $firstU->fetch();
                                if ($firstURow) {
                                    $db->prepare("UPDATE plan_units SET status='active' WHERE id=?")
                                       ->execute([$firstURow['id']]);
                                }
                            }
                        } else {
                            // Alle Biome abgeschlossen → Plan fertig?
                            $openBiomes = $db->prepare(
                                "SELECT COUNT(*) FROM plan_biomes WHERE plan_id=? AND status NOT IN ('completed')"
                            );
                            $openBiomes->execute([$session['plan_id']]);
                            if ((int)$openBiomes->fetchColumn() === 0) {
                                $db->prepare(
                                    "UPDATE learning_plans SET status='completed' WHERE id=?"
                                )->execute([$session['plan_id']]);
                                $planCompleted = true;
                            }
                        }
                    }
                }
            }

            $db->commit();

            // Statistiken für Antwort
            $statsStmt = db()->prepare(
                "SELECT total_items, correct_first_try, correct_second_try, wrong_total
                 FROM sessions WHERE id=?"
            );
            $statsStmt->execute([$sessionId]);
            $stats = $statsStmt->fetch();

            echo json_encode([
                'success'         => true,
                'quest_completed' => $questCompleted,
                'biome_completed' => $biomeCompleted,
                'plan_completed'  => $planCompleted,
                'stats'           => $stats,
            ]);

        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('SessionController::completeSession — ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────

    /**
     * plan_unit validieren — muss zum aktiven Plan des Kindes gehören und aktiv sein.
     */
    private static function validateUnit(int $unitId, int $userId): ?array
    {
        $stmt = db()->prepare("
            SELECT pu.id, pu.quest_id, pu.format, pu.word_count,
                   pu.difficulty, pu.order_index, pu.status,
                   q.category, q.title AS quest_title,
                   pb.block, pb.name AS biome_name, pb.theme_biome,
                   lp.id AS plan_id
            FROM plan_units pu
            JOIN quests q      ON pu.quest_id   = q.id
            JOIN plan_biomes pb ON q.biome_id    = pb.id
            JOIN learning_plans lp ON pb.plan_id = lp.id
            WHERE pu.id = ? AND lp.user_id = ?
              AND lp.status = 'active'
              AND pu.status IN ('active','pending')
        ");
        $stmt->execute([$unitId, $userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Aktive Session für user + unit finden.
     */
    private static function getActiveSession(int $userId, int $unitId): ?array
    {
        $stmt = db()->prepare(
            "SELECT * FROM sessions WHERE user_id=? AND plan_unit_id=? AND status='active' LIMIT 1"
        );
        $stmt->execute([$userId, $unitId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Alle session_items mit Wort/Satz laden.
     */
    private static function getSessionItems(int $sessionId): array
    {
        $stmt = db()->prepare("
            SELECT si.id, si.format, si.order_index,
                   si.final_correct, si.second_try_allowed,
                   si.tts_replays,
                   w.word, w.primary_category,
                   s.sentence
            FROM session_items si
            LEFT JOIN words w    ON si.word_id     = w.id
            LEFT JOIN sentences s ON si.sentence_id = s.id
            WHERE si.session_id = ?
            ORDER BY si.order_index
        ");
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }

    /**
     * Fortschritt der Session (beantwortet / gesamt).
     */
    private static function getSessionProgress(int $sessionId): array
    {
        $stmt = db()->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN final_correct IS NOT NULL THEN 1 ELSE 0 END) AS answered,
                    SUM(CASE WHEN final_correct = 1 THEN 1 ELSE 0 END)         AS correct
             FROM session_items WHERE session_id=?"
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetch() ?: ['total' => 0, 'answered' => 0, 'correct' => 0];
    }

    /**
     * Nächstes unbeantwortetes session_item.
     */
    private static function getNextUnansweredItem(int $sessionId): ?array
    {
        $stmt = db()->prepare(
            "SELECT si.id, si.format, si.order_index,
                    w.word, w.primary_category,
                    s.sentence
             FROM session_items si
             LEFT JOIN words w    ON si.word_id     = w.id
             LEFT JOIN sentences s ON si.sentence_id = s.id
             WHERE si.session_id = ? AND si.final_correct IS NULL
             ORDER BY si.order_index ASC LIMIT 1"
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Wörter für eine Session-Unit auswählen und session_items anlegen.
     */
    private static function createSessionItems(int $sessionId, array $unit, int $userId): void
    {
        $category   = $unit['category'];
        $wordCount  = (int)($unit['word_count'] ?? 20);
        $difficulty = (int)($unit['difficulty'] ?? 1);
        $format     = $unit['format'];
        $gradeLevel = (int)($_SESSION['grade_level'] ?? 4);

        // Wörter auswählen — schon in Sessions verwendete Wörter vermeiden
        $words = self::selectWords($category, $wordCount, $gradeLevel, $difficulty, $userId);

        $insertItem = db()->prepare(
            "INSERT INTO session_items (session_id, word_id, format, order_index)
             VALUES (?, ?, ?, ?)"
        );

        foreach ($words as $i => $word) {
            $insertItem->execute([$sessionId, $word['id'], $format, $i]);
        }
    }

    /**
     * Wörter für eine Kategorie auswählen (vermeidet kürzlich verwendete).
     */
    private static function selectWords(
        string $category,
        int    $count,
        int    $gradeLevel,
        int    $difficulty,
        int    $userId
    ): array {
        // Bereits verwendete Wörter in den letzten 30 Sessions
        $recentStmt = db()->prepare("
            SELECT DISTINCT si.word_id
            FROM session_items si
            JOIN sessions sess ON si.session_id = sess.id
            WHERE sess.user_id = ?
              AND sess.status = 'completed'
              AND sess.started_at >= datetime('now', '-30 days')
              AND si.word_id IS NOT NULL
        ");
        $recentStmt->execute([$userId]);
        $recentIds = $recentStmt->fetchAll(\PDO::FETCH_COLUMN);

        // Wörter suchen — mit Ausschluss
        $placeholders = $recentIds ? implode(',', array_fill(0, count($recentIds), '?')) : '0';
        $params = array_merge([$category, $gradeLevel, $difficulty], $recentIds, [$count]);
        $stmt = db()->prepare("
            SELECT id, word, primary_category, difficulty
            FROM words
            WHERE primary_category = ?
              AND grade_level <= ?
              AND difficulty <= ?
              AND active = 1
              AND id NOT IN ($placeholders)
            ORDER BY RANDOM()
            LIMIT ?
        ");
        $stmt->execute($params);
        $words = $stmt->fetchAll();

        // Fallback ohne Ausschluss wenn zu wenige
        if (count($words) < $count) {
            $stmt2 = db()->prepare("
                SELECT id, word, primary_category, difficulty
                FROM words
                WHERE primary_category = ?
                  AND grade_level <= ?
                  AND active = 1
                ORDER BY RANDOM()
                LIMIT ?
            ");
            $stmt2->execute([$category, $gradeLevel, $count]);
            $extra = $stmt2->fetchAll();

            // Deduplizieren
            $existing = array_column($words, 'id');
            foreach ($extra as $w) {
                if (!in_array($w['id'], $existing)) {
                    $words[] = $w;
                    $existing[] = $w['id'];
                    if (count($words) >= $count) break;
                }
            }
        }

        return array_slice($words, 0, $count);
    }

    /**
     * Zweiter Versuch erlaubt?
     * Lokal entschieden: Levenshtein ≤ 1 oder nur Groß-/Kleinschreibungsfehler,
     * außer bei Kategorie A1 (Auslautverhärtung).
     */
    private static function checkSecondTry(
        string $correct,
        string $userInput,
        string $category
    ): bool {
        // A1 → nie zweiten Versuch
        if ($category === 'A1') {
            return false;
        }

        $c = trim($correct);
        $u = trim($userInput);

        // Nur Groß-/Kleinschreibung falsch?
        if (mb_strtolower($c) === mb_strtolower($u) && $c !== $u) {
            return true;
        }

        // Tippfehler: Levenshtein ≤ 1
        if (levenshtein(mb_strtolower($c), mb_strtolower($u)) <= self::TYPO_LEVENSHTEIN_MAX) {
            return true;
        }

        return false;
    }

    /**
     * Lokales (kein AI) Feedback zu einer Antwort.
     */
    private static function buildLocalFeedback(
        string $correct,
        string $userInput,
        bool   $isCorrect,
        string $category,
        bool   $isSecondTry
    ): array {
        if ($isCorrect) {
            $texts = [
                'Super! Genau richtig! ✅',
                'Richtig! Weiter so! ⭐',
                'Perfekt! +XP 🎉',
                'Toll gemacht! ✅',
            ];
            return ['text' => $texts[array_rand($texts)], 'hint' => null];
        }

        if ($isSecondTry) {
            // Groß-/Kleinschreibung?
            if (mb_strtolower(trim($correct)) === mb_strtolower(trim($userInput))) {
                return [
                    'text' => 'Fast! Achte auf die Groß- oder Kleinschreibung.',
                    'hint' => 'Versuch es noch einmal — du schaffst das!',
                ];
            }
            return [
                'text' => 'Beinahe! Ein kleiner Tippfehler.',
                'hint' => 'Noch ein Versuch!',
            ];
        }

        return [
            'text' => 'Das war leider nicht ganz richtig.',
            'hint' => null,
        ];
    }

    /**
     * Theme laden.
     */
    private static function loadTheme(string $name): array
    {
        $path = __DIR__ . '/../../themes/' . preg_replace('/[^a-z0-9_-]/i', '', $name) . '/theme.json';
        if (file_exists($path)) {
            return json_decode(file_get_contents($path), true) ?? [];
        }
        return [];
    }

    /**
     * Streak-Tage für den User berechnen.
     */
    private static function getStreakDays(int $userId): int
    {
        $stmt = db()->prepare(
            "SELECT DISTINCT date(started_at) AS d
             FROM sessions WHERE user_id=? AND status='completed'
             ORDER BY d DESC LIMIT 60"
        );
        $stmt->execute([$userId]);
        $dates = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($dates)) return 0;

        $streak = 0;
        $today  = new \DateTimeImmutable('today');
        foreach ($dates as $i => $d) {
            $expected = $today->modify("-{$i} days")->format('Y-m-d');
            if ($d === $expected) {
                $streak++;
            } else {
                break;
            }
        }
        return $streak;
    }
}
