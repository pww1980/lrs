<?php

namespace App\Controllers;

use App\Helpers\Auth;
use App\Services\ProgressTestService;

/**
 * DashboardController — Papa-Dashboard
 *
 * Routen:
 *   GET  /admin/dashboard          → show()
 *   POST /admin/plan/approve       → approvePlan()
 *   POST /admin/plan/quest-toggle  → toggleQuest()
 */
class DashboardController
{
    // ── Öffentliche Handler ───────────────────────────────────────────────

    /**
     * GET /admin/dashboard
     * Zeigt Kinder-Übersicht, ausstehende Auswertungen, Draft-Pläne.
     */
    public static function show(): void
    {
        Auth::requireRole('admin', 'superadmin');
        $adminId = (int)$_SESSION['user_id'];

        // Wenn noch kein Kind → Wizard
        $childCount = (int)db()->query("SELECT COUNT(*) FROM users WHERE role='child'")->fetchColumn();
        if ($childCount === 0) {
            redirect('/setup/wizard');
        }

        $children   = self::loadChildrenData($adminId);
        $flash      = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        require __DIR__ . '/../Views/admin/dashboard.php';
    }

    /**
     * GET /admin/sessions/detail?session_id=X  (AJAX)
     * Gibt alle Items einer Session mit Eingaben zurück (für Admin-Verlauf).
     */
    public static function sessionDetail(): void
    {
        Auth::requireRole('admin', 'superadmin');
        ini_set('display_errors', '0');
        header('Content-Type: application/json');

        $adminId      = (int)$_SESSION['user_id'];
        $isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
        $sessionId    = (int)($_GET['session_id'] ?? 0);

        if (!$sessionId) {
            echo json_encode(['error' => 'Ungültige Session-ID']);
            exit;
        }

        // Prüfen, ob diese Session einem Kind des Admins gehört
        if ($isSuperadmin) {
            $sesStmt = db()->prepare("SELECT user_id FROM sessions WHERE id=?");
            $sesStmt->execute([$sessionId]);
        } else {
            $sesStmt = db()->prepare(
                "SELECT s.user_id FROM sessions s
                 JOIN child_admins ca ON s.user_id = ca.child_id
                 WHERE s.id=? AND ca.admin_id=?"
            );
            $sesStmt->execute([$sessionId, $adminId]);
        }
        $sesRow = $sesStmt->fetch();
        if (!$sesRow) {
            http_response_code(403);
            echo json_encode(['error' => 'Zugriff verweigert']);
            exit;
        }

        // Items laden
        $itemStmt = db()->prepare(
            "SELECT si.id, si.format, si.final_correct, si.custom_text,
                    w.word,
                    s.sentence,
                    (SELECT sa.user_input FROM session_attempts sa
                     WHERE sa.item_id = si.id
                     ORDER BY sa.attempt_number DESC LIMIT 1) AS user_input
             FROM session_items si
             LEFT JOIN words w ON si.word_id = w.id
             LEFT JOIN sentences s ON si.sentence_id = s.id
             WHERE si.session_id = ?
             ORDER BY si.order_index"
        );
        $itemStmt->execute([$sessionId]);
        $rows = $itemStmt->fetchAll();

        $items = [];
        foreach ($rows as $r) {
            $text = $r['custom_text'] ?? $r['word'] ?? $r['sentence'] ?? '—';
            $items[] = [
                'text'        => $text,
                'user_input'  => $r['user_input'],
                'final_correct'=> $r['final_correct'] !== null ? (bool)$r['final_correct'] : null,
            ];
        }

        echo json_encode(['items' => $items]);
        exit;
    }

    /**
     * POST /admin/plan/approve  (AJAX, JSON-Body)
     * Aktiviert einen Draft-Plan: status → 'active', erste Sektion freischalten.
     */
    public static function approvePlan(): void
    {
        Auth::requireRole('admin', 'superadmin');
        ini_set('display_errors', '0');
        ob_start();
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!hash_equals($_SESSION['csrf_token'] ?? '', $data['csrf_token'] ?? '')) {
            ob_end_clean();
            http_response_code(403);
            echo json_encode(['error' => 'CSRF-Fehler']);
            exit;
        }

        $adminId      = (int)$_SESSION['user_id'];
        $isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
        $planId       = (int)($data['plan_id'] ?? 0);

        // Plan validieren — Superadmin sieht alle, Admin nur eigene Kinder
        if ($isSuperadmin) {
            $stmt = db()->prepare(
                "SELECT id, user_id FROM learning_plans WHERE id=? AND status='draft'"
            );
            $stmt->execute([$planId]);
        } else {
            $stmt = db()->prepare("
                SELECT lp.id, lp.user_id
                FROM learning_plans lp
                JOIN child_admins ca ON lp.user_id = ca.child_id
                WHERE lp.id = ? AND ca.admin_id = ? AND lp.status = 'draft'
            ");
            $stmt->execute([$planId, $adminId]);
        }
        $plan = $stmt->fetch();

        if (!$plan) {
            ob_end_clean();
            http_response_code(404);
            echo json_encode(['error' => 'Plan nicht gefunden oder bereits aktiv']);
            exit;
        }

        $db = db();
        $db->beginTransaction();
        try {
            // Plan aktivieren
            $db->prepare(
                "UPDATE learning_plans SET status='active', activated_at=CURRENT_TIMESTAMP WHERE id=?"
            )->execute([$planId]);

            // Alle anderen aktiven Pläne dieses Kindes auf 'superseded' setzen
            $db->prepare(
                "UPDATE learning_plans SET status='superseded'
                 WHERE user_id=? AND status='active' AND id != ?"
            )->execute([$plan['user_id'], $planId]);

            // Erstes nicht-skipped Biom freischalten
            $firstBiome = $db->prepare(
                "SELECT id FROM plan_biomes WHERE plan_id=? AND status='locked'
                 ORDER BY order_index ASC LIMIT 1"
            );
            $firstBiome->execute([$planId]);
            $biome = $firstBiome->fetch();

            if ($biome) {
                $db->prepare(
                    "UPDATE plan_biomes SET status='active', unlocked_at=CURRENT_TIMESTAMP WHERE id=?"
                )->execute([$biome['id']]);

                // Erste nicht-skipped Quest des Bioms freischalten
                $firstQuest = $db->prepare(
                    "SELECT id FROM quests WHERE biome_id=? AND status='locked'
                     ORDER BY order_index ASC LIMIT 1"
                );
                $firstQuest->execute([$biome['id']]);
                $quest = $firstQuest->fetch();

                if ($quest) {
                    $db->prepare(
                        "UPDATE quests SET status='active', unlocked_at=CURRENT_TIMESTAMP WHERE id=?"
                    )->execute([$quest['id']]);

                    // Erste plan_unit der Quest aktivieren
                    $firstUnit = $db->prepare(
                        "SELECT id FROM plan_units WHERE quest_id=? AND status='pending'
                         ORDER BY order_index ASC LIMIT 1"
                    );
                    $firstUnit->execute([$quest['id']]);
                    $unit = $firstUnit->fetch();

                    if ($unit) {
                        $db->prepare(
                            "UPDATE plan_units SET status='active' WHERE id=?"
                        )->execute([$unit['id']]);
                    }
                }
            }

            $db->commit();
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'Plan aktiviert!']);
        } catch (\Throwable $e) {
            ob_end_clean();
            $db->rollBack();
            error_log('DashboardController::approvePlan — ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * POST /admin/plan/quest-toggle  (AJAX, JSON-Body)
     * Schaltet eine Quest ein (locked) oder aus (skipped) und erstellt/löscht plan_units.
     */
    public static function toggleQuest(): void
    {
        Auth::requireRole('admin', 'superadmin');
        ini_set('display_errors', '0');
        ob_start();
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!hash_equals($_SESSION['csrf_token'] ?? '', $data['csrf_token'] ?? '')) {
            ob_end_clean();
            http_response_code(403);
            echo json_encode(['error' => 'CSRF-Fehler']);
            exit;
        }

        $adminId      = (int)$_SESSION['user_id'];
        $isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
        $questId      = (int)($data['quest_id'] ?? 0);

        // Quest validieren — Superadmin sieht alle, Admin nur eigene Kinder
        if ($isSuperadmin) {
            $stmt = db()->prepare("
                SELECT q.id, q.status, q.biome_id, q.category,
                       tr.severity, tr.strategy_level
                FROM quests q
                JOIN plan_biomes pb    ON q.biome_id  = pb.id
                JOIN learning_plans lp ON pb.plan_id  = lp.id
                LEFT JOIN test_results tr ON (tr.test_id = lp.test_id AND tr.category = q.category)
                WHERE q.id = ? AND lp.status = 'draft'
            ");
            $stmt->execute([$questId]);
        } else {
            $stmt = db()->prepare("
                SELECT q.id, q.status, q.biome_id, q.category,
                       tr.severity, tr.strategy_level
                FROM quests q
                JOIN plan_biomes pb     ON q.biome_id   = pb.id
                JOIN learning_plans lp  ON pb.plan_id   = lp.id
                JOIN child_admins ca    ON lp.user_id   = ca.child_id
                LEFT JOIN test_results tr ON (tr.test_id = lp.test_id AND tr.category = q.category)
                WHERE q.id = ? AND ca.admin_id = ? AND lp.status = 'draft'
            ");
            $stmt->execute([$questId, $adminId]);
        }
        $quest = $stmt->fetch();

        if (!$quest) {
            ob_end_clean();
            http_response_code(404);
            echo json_encode(['error' => 'Quest nicht gefunden']);
            exit;
        }

        // Aktive/abgeschlossene Quests dürfen nicht umgeschaltet werden
        if (in_array($quest['status'], ['active', 'completed'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Aktive oder abgeschlossene Quests können nicht geändert werden']);
            exit;
        }

        $db = db();
        if ($quest['status'] === 'skipped') {
            // Einschalten: locked + plan_units erstellen
            $db->prepare("UPDATE quests SET status='locked' WHERE id=?")->execute([$questId]);

            $severity = $quest['severity']       ?? 'mild';
            $strategy = (int)($quest['strategy_level'] ?? 1);
            $units = self::buildUnits($severity, $strategy);

            $stmt2 = $db->prepare(
                "INSERT INTO plan_units (quest_id, order_index, format, word_count, difficulty, status)
                 VALUES (?, ?, ?, 20, ?, 'pending')"
            );
            foreach ($units as $i => [$format, $diff]) {
                $stmt2->execute([$questId, $i, $format, $diff]);
            }
            $newStatus = 'locked';
        } else {
            // Ausschalten: skipped + plan_units löschen
            $db->prepare("UPDATE quests SET status='skipped' WHERE id=?")->execute([$questId]);
            $db->prepare("DELETE FROM plan_units WHERE quest_id=? AND status='pending'")
               ->execute([$questId]);
            $newStatus = 'skipped';
        }

        ob_end_clean();
        echo json_encode(['success' => true, 'new_status' => $newStatus]);
        exit;
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────

    /**
     * Lädt alle Kinder dieses Admins mit vollständigen Statusinformationen.
     */
    private static function loadChildrenData(int $adminId): array
    {
        // Kinder laden
        $stmt = db()->prepare("
            SELECT u.id, u.display_name, u.grade_level, u.school_type, u.theme,
                   u.last_login, u.active, ca.role AS admin_role
            FROM users u
            JOIN child_admins ca ON u.id = ca.child_id
            WHERE ca.admin_id = ? AND u.role = 'child'
            ORDER BY u.display_name ASC
        ");
        $stmt->execute([$adminId]);
        $children = $stmt->fetchAll();

        foreach ($children as &$child) {
            $cid = (int)$child['id'];

            // Letzter abgeschlossener Test
            $testStmt = db()->prepare(
                "SELECT id, type, status, completed_at FROM tests
                 WHERE user_id=? ORDER BY started_at DESC LIMIT 1"
            );
            $testStmt->execute([$cid]);
            $child['latest_test'] = $testStmt->fetch() ?: null;

            // Auswertungs-Status ermitteln
            $child['analysis_status'] = 'none'; // none | pending | done
            if ($child['latest_test'] && $child['latest_test']['status'] === 'completed') {
                $resStmt = db()->prepare("SELECT COUNT(*) FROM test_results WHERE test_id=?");
                $resStmt->execute([$child['latest_test']['id']]);
                $child['analysis_status'] = ((int)$resStmt->fetchColumn() > 0) ? 'done' : 'pending';
            }

            // Test-Ergebnisse pro Kategorie (für Error-Profil)
            $child['test_results'] = [];
            if ($child['latest_test']) {
                $resStmt2 = db()->prepare(
                    "SELECT block, category, error_rate, severity, strategy_level, total_items, correct_items
                     FROM test_results WHERE test_id=? ORDER BY block, category"
                );
                $resStmt2->execute([$child['latest_test']['id']]);
                $child['test_results'] = $resStmt2->fetchAll();
            }

            // Draft-Plan (neuester)
            $planStmt = db()->prepare(
                "SELECT id, status, created_at, ai_notes
                 FROM learning_plans
                 WHERE user_id=? AND status='draft'
                 ORDER BY created_at DESC LIMIT 1"
            );
            $planStmt->execute([$cid]);
            $child['draft_plan'] = $planStmt->fetch() ?: null;

            // Biome + Quests des Draft-Plans
            $child['plan_biomes'] = [];
            if ($child['draft_plan']) {
                $biomeStmt = db()->prepare(
                    "SELECT id, block, name, theme_biome, order_index, status
                     FROM plan_biomes WHERE plan_id=? ORDER BY order_index"
                );
                $biomeStmt->execute([$child['draft_plan']['id']]);
                $biomes = $biomeStmt->fetchAll();

                foreach ($biomes as &$biome) {
                    $qStmt = db()->prepare(
                        "SELECT q.id, q.category, q.title, q.description,
                                q.order_index, q.status, q.difficulty, q.required_score,
                                q.ai_notes,
                                COUNT(pu.id) AS unit_count
                         FROM quests q
                         LEFT JOIN plan_units pu ON pu.quest_id = q.id
                         WHERE q.biome_id = ?
                         GROUP BY q.id
                         ORDER BY q.order_index"
                    );
                    $qStmt->execute([$biome['id']]);
                    $biome['quests'] = $qStmt->fetchAll();
                }
                unset($biome);
                $child['plan_biomes'] = $biomes;
            }

            // Aktiver Plan (für Fortschrittsanzeige)
            $activePlanStmt = db()->prepare(
                "SELECT id, status, activated_at FROM learning_plans
                 WHERE user_id=? AND status='active' ORDER BY activated_at DESC LIMIT 1"
            );
            $activePlanStmt->execute([$cid]);
            $child['active_plan'] = $activePlanStmt->fetch() ?: null;

            // Fortschrittstest-Status
            $child['progress_test'] = ProgressTestService::isDue($cid);
            $child['has_initial_test'] = ProgressTestService::hasInitialTest($cid);

            // Gesamt-Statistiken (Einheiten, Wörter)
            $statsStmt = db()->prepare("
                SELECT COUNT(*) AS sessions,
                       COALESCE(SUM(correct_first_try + correct_second_try), 0) AS correct_words
                FROM sessions WHERE user_id=? AND status='completed'
            ");
            $statsStmt->execute([$cid]);
            $child['stats'] = $statsStmt->fetch();

            // Chart-Daten: Fehlerrate pro Kategorie über alle abgeschlossenen Tests
            // Gibt [{test_date, category, error_rate}] zurück, chronologisch
            $chartStmt = db()->prepare("
                SELECT date(t.completed_at)  AS test_date,
                       tr.block,
                       tr.category,
                       tr.error_rate
                FROM test_results tr
                JOIN tests t ON tr.test_id = t.id
                WHERE t.user_id = ? AND t.status = 'completed'
                ORDER BY t.completed_at ASC
            ");
            $chartStmt->execute([$cid]);
            $chartRows = $chartStmt->fetchAll();

            // In JS-taugliche Struktur umwandeln:
            // chart_data[block] = {labels:[dates], datasets:[{label:cat, data:[rates]}]}
            $byBlock = [];
            $testDates = []; // alle Test-Daten in Reihenfolge (dedupliziert)
            $catData   = []; // [cat => [test_date => error_rate]]

            foreach ($chartRows as $row) {
                $testDates[$row['test_date']] = true;
                $catData[$row['category']][$row['test_date']] = round((float)$row['error_rate'] * 100, 1);
                $byBlock[$row['block']][$row['category']] = true;
            }
            $testDates = array_keys($testDates);

            $chartData = [];
            foreach ($byBlock as $block => $cats) {
                $datasets = [];
                $palette  = ['#4caf50','#2196f3','#ff9800','#e91e63','#9c27b0'];
                $pi = 0;
                foreach ($cats as $cat => $_) {
                    $points = [];
                    foreach ($testDates as $d) {
                        $points[] = $catData[$cat][$d] ?? null;
                    }
                    $datasets[] = [
                        'label'           => $cat,
                        'data'            => $points,
                        'borderColor'     => $palette[$pi % count($palette)],
                        'backgroundColor' => $palette[$pi % count($palette)] . '22',
                        'tension'         => 0.3,
                        'fill'            => false,
                    ];
                    $pi++;
                }
                $chartData[$block] = [
                    'labels'   => $testDates,
                    'datasets' => $datasets,
                ];
            }
            $child['chart_data'] = $chartData;
        }
        unset($child);

        return $children;
    }

    /**
     * GET /admin/child/{id}/edit
     * Zeigt Bearbeitungsformular für ein Kind.
     */
    public static function editChild(int $childId = 0): void
    {
        Auth::requireRole('admin', 'superadmin');
        $adminId = (int)$_SESSION['user_id'];

        $child = self::loadChildForAdmin($adminId, $childId);
        if (!$child) {
            redirect('/admin/dashboard');
        }

        // Aktuelle Kind-Settings laden (für tts_speed etc.)
        $childSettings = [];
        try {
            $childSettings = \App\Services\EncryptionService::make()->loadUserSettings($childId);
        } catch (\Throwable) {}

        $error = $_SESSION['child_edit_error'] ?? null;
        unset($_SESSION['child_edit_error']);

        require __DIR__ . '/../Views/admin/child_edit.php';
    }

    /**
     * POST /admin/child/{id}/edit
     * Speichert Änderungen am Kindprofil.
     */
    public static function updateChild(int $childId = 0): void
    {
        Auth::requireRole('admin', 'superadmin');
        Auth::verifyCsrf();

        $adminId = (int)$_SESSION['user_id'];
        if ($childId === 0) $childId = (int)($_POST['child_id'] ?? 0);

        $child = self::loadChildForAdmin($adminId, $childId);
        if (!$child) {
            redirect('/admin/dashboard');
        }

        $displayName = trim($_POST['display_name'] ?? '');
        $gradeLevel  = (int)($_POST['grade_level']  ?? 4);
        $schoolType  = trim($_POST['school_type']   ?? 'Grundschule');
        $theme       = trim($_POST['theme']         ?? 'minecraft');
        $active      = isset($_POST['active']) ? 1 : 0;
        $ttsSpeed    = in_array($_POST['tts_speed'] ?? '', ['normal','slow','very_slow']) ? $_POST['tts_speed'] : 'normal';

        if ($displayName === '') {
            $_SESSION['child_edit_error'] = 'Name darf nicht leer sein.';
            redirect('/admin/child/' . $childId . '/edit');
        }

        $validThemes = ['minecraft', 'space', 'ocean'];
        if (!in_array($theme, $validThemes, true)) {
            $theme = 'minecraft';
        }

        db()->prepare(
            "UPDATE users SET display_name=?, grade_level=?, school_type=?, theme=?, active=?
             WHERE id=? AND role='child'"
        )->execute([$displayName, $gradeLevel, $schoolType, $theme, $active, $childId]);

        // tts_speed als verschlüsseltes Kind-Setting speichern
        try {
            $enc = \App\Services\EncryptionService::make();
            $enc->saveSetting($childId, 'tts_speed', $ttsSpeed);
        } catch (\Throwable) {}

        $_SESSION['flash'] = ['type' => 'success', 'text' => 'Profil von ' . htmlspecialchars($displayName) . ' gespeichert.'];
        redirect('/admin/dashboard');
    }

    /** Lädt ein Kind nur wenn der Admin Zugriff hat (oder Superadmin). */
    private static function loadChildForAdmin(int $adminId, int $childId): ?array
    {
        $role = $_SESSION['user_role'] ?? '';
        if ($role === 'superadmin') {
            $stmt = db()->prepare("SELECT * FROM users WHERE id=? AND role='child'");
            $stmt->execute([$childId]);
        } else {
            $stmt = db()->prepare("
                SELECT u.* FROM users u
                JOIN child_admins ca ON u.id = ca.child_id
                WHERE u.id=? AND ca.admin_id=? AND u.role='child'
            ");
            $stmt->execute([$childId, $adminId]);
        }
        return $stmt->fetch() ?: null;
    }

    /**
     * Berechnet plan_units für eine Kombination aus severity + strategy_level.
     * Identisch zur Logik in AnalysisController.
     */
    private static function buildUnits(string $severity, int $strategyLevel): array
    {
        $base = match($severity) {
            'severe'   => [['word',1],['word',2],['gap',1],['gap',2]],
            'moderate' => [['word',1],['gap',1],['gap',2]],
            'mild'     => [['word',1],['gap',1]],
            default    => [['word',1]],
        };

        if ($strategyLevel >= 3) {
            return array_map(fn($u) => match($u[0]) {
                'word'  => ['gap',      $u[1]],
                'gap'   => ['sentence', $u[1]],
                default => $u,
            }, $base);
        }

        return $base;
    }
}
