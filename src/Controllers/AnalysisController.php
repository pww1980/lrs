<?php

namespace App\Controllers;

use App\Helpers\Auth;
use App\Services\AIService;
use App\Services\EncryptionService;

/**
 * AnalysisController — KI-Auswertung nach Testabschluss
 *
 * Routen:
 *   POST /learn/test/analyze      → runForChild()   (Kind-Session, beide Schritte)
 *   POST /admin/analysis/step1    → step1ForAdmin()  (Fehleranalyse, ~30s)
 *   POST /admin/analysis/step2    → step2ForAdmin()  (Plan generieren, ~30s)
 *
 * Aufgeteilt in zwei Steps um Gateway-Timeout (504) zu vermeiden.
 */
class AnalysisController
{
    // Plan-Einheiten nach Schweregrad (format, difficulty)
    private const UNITS_BY_SEVERITY = [
        'severe'   => [['word',1],['word',2],['gap',1],['gap',2]],
        'moderate' => [['word',1],['gap',1],['gap',2]],
        'mild'     => [['word',1],['gap',1]],
        'none'     => [],
    ];

    // ── Öffentliche Handler ───────────────────────────────────────────────

    /**
     * POST /learn/test/analyze  (AJAX, JSON-Body)
     * Wird vom Kind-Browser nach Testabschluss aufgerufen.
     */
    public static function runForChild(): void
    {
        Auth::requireRole('child');
        header('Content-Type: application/json');
        set_time_limit(180); // KI-Calls können 30-60 s dauern

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!hash_equals($_SESSION['csrf_token'] ?? '', $data['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF-Fehler']);
            exit;
        }

        $userId = (int)$_SESSION['user_id'];
        $testId = (int)($data['test_id'] ?? 0);

        // Test validieren — muss dem Kind gehören und abgeschlossen sein
        $stmt = db()->prepare(
            "SELECT * FROM tests WHERE id=? AND user_id=? AND status='completed'"
        );
        $stmt->execute([$testId, $userId]);
        $test = $stmt->fetch();

        if (!$test) {
            http_response_code(404);
            echo json_encode(['error' => 'Test nicht gefunden oder noch nicht abgeschlossen']);
            exit;
        }

        // Bereits analysiert?
        if (self::hasExistingAnalysis($testId)) {
            echo json_encode([
                'already_done' => true,
                'message'      => 'Test wurde bereits ausgewertet.',
                'plan_id'      => self::getPlanId($testId),
            ]);
            exit;
        }

        try {
            $result = self::runAnalysis($testId, $userId);
            echo json_encode([
                'success' => true,
                'plan_id' => $result['plan_id'],
                'message' => 'Auswertung abgeschlossen. Papa kann jetzt deinen Plan bestätigen.',
            ]);
        } catch (\Throwable $e) {
            error_log('AnalysisController::runForChild — ' . $e->getMessage());
            $msg = $e->getMessage();

            // Kein API-Key konfiguriert → verständliche Meldung, kein fataler Fehler
            $noKey = str_contains($msg, 'API-Key') || str_contains($msg, 'api_key')
                  || str_contains($msg, '401') || str_contains($msg, 'provider');
            echo json_encode([
                'error'      => true,
                'no_ai_key'  => $noKey,
                'message'    => $noKey
                    ? 'Kein KI-Key konfiguriert. Papa kann die Auswertung manuell im Dashboard starten.'
                    : 'Auswertung fehlgeschlagen. Papa kann sie manuell im Dashboard starten.',
            ]);
        }
        exit;
    }

    /**
     * POST /admin/analysis/run  (AJAX, JSON-Body)
     * Admin kann Auswertung manuell anstoßen (z. B. bei Fehler).
     */
    public static function runForAdmin(): void
    {
        Auth::requireRole('admin', 'superadmin');
        // PHP-Warnungen/-Notices nicht in den JSON-Output mischen
        ini_set('display_errors', '0');
        ob_start();
        header('Content-Type: application/json');
        set_time_limit(180);

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!hash_equals($_SESSION['csrf_token'] ?? '', $data['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF-Fehler']);
            exit;
        }

        $adminId      = (int)$_SESSION['user_id'];
        $isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
        $testId       = (int)($data['test_id'] ?? 0);

        // Test validieren — Superadmin sieht alle Tests, Admin nur eigene Kinder
        if ($isSuperadmin) {
            $stmt = db()->prepare("
                SELECT t.*, u.id AS child_id
                FROM tests t
                JOIN users u ON t.user_id = u.id
                WHERE t.id = ? AND t.status = 'completed'
            ");
            $stmt->execute([$testId]);
        } else {
            $stmt = db()->prepare("
                SELECT t.*, u.id AS child_id
                FROM tests t
                JOIN users u ON t.user_id = u.id
                JOIN child_admins ca ON u.id = ca.child_id
                WHERE t.id = ? AND ca.admin_id = ? AND t.status = 'completed'
            ");
            $stmt->execute([$testId, $adminId]);
        }
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Test nicht gefunden']);
            exit;
        }

        // Bereits analysiert?
        if (self::hasExistingAnalysis($testId)) {
            echo json_encode([
                'already_done' => true,
                'plan_id'      => self::getPlanId($testId),
            ]);
            exit;
        }

        try {
            $result = self::runAnalysis($testId, (int)$row['child_id'], $adminId);
            ob_end_clean();
            echo json_encode(['success' => true, 'plan_id' => $result['plan_id']]);
        } catch (\Throwable $e) {
            $spurious = ob_get_clean();
            error_log('AnalysisController::runForAdmin — ' . $e->getMessage()
                . ($spurious ? ' | spurious output: ' . substr($spurious, 0, 200) : ''));
            echo json_encode(['error' => true, 'message' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * POST /admin/analysis/step1  — Schritt 1: Fehleranalyse (~30s)
     * Lädt Items, ruft AIService::analyzeTest() auf, speichert test_results.
     */
    public static function step1ForAdmin(): void
    {
        Auth::requireRole('admin', 'superadmin');
        ini_set('display_errors', '0');
        ob_start();
        header('Content-Type: application/json');
        set_time_limit(120);

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $data['csrf_token'] ?? '')) {
            ob_end_clean();
            http_response_code(403);
            echo json_encode(['error' => 'CSRF-Fehler']);
            exit;
        }

        $adminId      = (int)$_SESSION['user_id'];
        $isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
        $testId       = (int)($data['test_id'] ?? 0);

        $row = self::fetchTestForAdmin($testId, $adminId, $isSuperadmin);
        if (!$row) {
            ob_end_clean();
            http_response_code(404);
            echo json_encode(['error' => 'Test nicht gefunden']);
            exit;
        }

        if (self::hasExistingAnalysis($testId)) {
            ob_end_clean();
            echo json_encode(['already_done' => true, 'plan_id' => self::getPlanId($testId)]);
            exit;
        }

        try {
            $childId = (int)$row['child_id'];
            $ai      = self::makeAIService($childId, $adminId);
            [$testMeta, $userProfile, $items] = self::loadTestContext($testId, $childId);

            if (empty($items)) {
                throw new \RuntimeException("Keine beantworteten Items für Test {$testId} gefunden.");
            }
            $analysisResult = $ai->analyzeTest($testMeta, $items, $userProfile, $testId);
            self::saveTestResults($testId, $analysisResult['results']);

            ob_end_clean();
            echo json_encode([
                'success'  => true,
                'test_id'  => $testId,
                'child_id' => $childId,
            ]);
        } catch (\Throwable $e) {
            $spurious = ob_get_clean();
            error_log('AnalysisController::step1 — ' . $e->getMessage()
                . ($spurious ? ' | extra: ' . substr($spurious, 0, 200) : ''));
            echo json_encode(['error' => true, 'message' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * POST /admin/analysis/step2  — Schritt 2: Lernplan generieren (~30s)
     * Lädt gespeicherte test_results, ruft AIService::generatePlan() auf, speichert Plan.
     */
    public static function step2ForAdmin(): void
    {
        Auth::requireRole('admin', 'superadmin');
        ini_set('display_errors', '0');
        ob_start();
        header('Content-Type: application/json');
        set_time_limit(120);

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $data['csrf_token'] ?? '')) {
            ob_end_clean();
            http_response_code(403);
            echo json_encode(['error' => 'CSRF-Fehler']);
            exit;
        }

        $adminId      = (int)$_SESSION['user_id'];
        $isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
        $testId       = (int)($data['test_id']  ?? 0);
        $childId      = (int)($data['child_id'] ?? 0);

        // child_id not provided (planOnly mode) — resolve from test
        if (!$childId && $testId) {
            $row = self::fetchTestForAdmin($testId, $adminId, $isSuperadmin);
            if ($row) $childId = (int)$row['child_id'];
        }

        if (!$childId) {
            ob_end_clean();
            http_response_code(404);
            echo json_encode(['error' => 'Kind nicht ermittelt']);
            exit;
        }

        try {
            $ai = self::makeAIService($childId, $adminId);
            [, $userProfile,] = self::loadTestContext($testId, $childId);

            // Gespeicherte test_results laden
            $savedResults = db()->prepare(
                "SELECT block, category, error_rate, severity, strategy_level
                 FROM test_results WHERE test_id=?"
            );
            $savedResults->execute([$testId]);
            $results = $savedResults->fetchAll();

            if (empty($results)) {
                throw new \RuntimeException('Keine Analyseergebnisse gefunden. Bitte Schritt 1 erneut ausführen.');
            }

            $analysisResult = [
                'results'            => $results,
                'overall_notes'      => '',
                'fatigue_detected'   => false,
                'recommended_blocks' => [],
            ];

            $planResult = $ai->generatePlan($analysisResult, $userProfile, $testId);

            $severityMap = [];
            $strategyMap = [];
            foreach ($results as $r) {
                $severityMap[$r['category']] = $r['severity']      ?? 'none';
                $strategyMap[$r['category']] = (int)($r['strategy_level'] ?? 1);
            }

            $planId = self::saveLearningPlan(
                $testId, $childId, $planResult, $severityMap, $strategyMap,
                $planResult['overall_notes'] ?? ''
            );

            ob_end_clean();
            echo json_encode(['success' => true, 'plan_id' => $planId]);
        } catch (\Throwable $e) {
            $spurious = ob_get_clean();
            error_log('AnalysisController::step2 — ' . $e->getMessage()
                . ($spurious ? ' | extra: ' . substr($spurious, 0, 200) : ''));
            echo json_encode(['error' => true, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── Kern-Logik ────────────────────────────────────────────────────────

    /**
     * Führt die vollständige KI-Auswertung durch:
     *   1. Test-Items laden
     *   2. AIService::analyzeTest() aufrufen
     *   3. test_results speichern
     *   4. AIService::generatePlan() aufrufen
     *   5. learning_plan + Struktur speichern
     *
     * @return array{plan_id: int}
     * @throws \RuntimeException bei KI-Fehlern
     */
    public static function runAnalysis(int $testId, int $userId, ?int $callerAdminId = null): array
    {
        $db = db();

        // ── Profil des Kindes laden ───────────────────────────────────────
        $childStmt = $db->prepare(
            "SELECT id, display_name, grade_level, school_type, theme FROM users WHERE id=?"
        );
        $childStmt->execute([$userId]);
        $child = $childStmt->fetch();

        if (!$child) {
            throw new \RuntimeException("Kind (userId={$userId}) nicht gefunden.");
        }

        $childSettings = EncryptionService::make()->loadUserSettings($userId);

        $userProfile = [
            'grade_level'   => (int)($child['grade_level'] ?? 4),
            'school_type'   => $child['school_type']   ?? 'Grundschule',
            'federal_state' => $childSettings['federal_state'] ?? 'Bayern',
            'theme'         => $child['theme']          ?? 'minecraft',
            'display_name'  => $child['display_name']   ?? 'Kind',
        ];

        // ── Test-Metadaten ────────────────────────────────────────────────
        $testStmt = $db->prepare("SELECT type FROM tests WHERE id=?");
        $testStmt->execute([$testId]);
        $testRow = $testStmt->fetch();

        $testMeta = [
            'type'      => $testRow['type'] ?? 'initial',
            'user_name' => $child['display_name'],
        ];

        // ── Test-Items laden (mit korrekten Antworten) ────────────────────
        $items = self::loadTestItems($testId);

        if (empty($items)) {
            throw new \RuntimeException("Keine beantworteten Items für Test {$testId} gefunden.");
        }

        // ── KI: Test auswerten ────────────────────────────────────────────
        // Zuerst Kind-ID versuchen (sucht Primary Admin). Fallback: aufrufender Admin.
        try {
            $ai = new AIService($userId);
        } catch (\RuntimeException $e) {
            if ($callerAdminId !== null && str_contains($e->getMessage(), 'Primary-Admin')) {
                $ai = new AIService($callerAdminId);
            } else {
                throw $e;
            }
        }
        $analysisResult = $ai->analyzeTest($testMeta, $items, $userProfile, $testId);

        // ── test_results speichern ────────────────────────────────────────
        self::saveTestResults($testId, $analysisResult['results']);

        // ── KI: Lernplan generieren ───────────────────────────────────────
        $planResult = $ai->generatePlan($analysisResult, $userProfile, $testId);

        // ── Severity-Map aus test_results aufbauen (für plan_units) ───────
        $severityMap  = [];
        $strategyMap  = [];
        foreach ($analysisResult['results'] as $r) {
            $severityMap[$r['category']]  = $r['severity']       ?? 'none';
            $strategyMap[$r['category']]  = (int)($r['strategy_level'] ?? 1);
        }

        // ── learning_plan + Struktur speichern ────────────────────────────
        $aiNotes = $planResult['overall_notes'] ?? '';
        $planId  = self::saveLearningPlan(
            $testId, $userId, $planResult, $severityMap, $strategyMap, $aiNotes
        );

        return ['plan_id' => $planId];
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────

    /**
     * Lädt den Test-Datensatz und prüft Zugriffsrecht (Superadmin vs. Admin via child_admins).
     * Gibt die Zeile mit child_id zurück, oder null wenn nicht berechtigt.
     */
    private static function fetchTestForAdmin(int $testId, int $adminId, bool $isSuperadmin): ?array
    {
        if ($isSuperadmin) {
            $stmt = db()->prepare("
                SELECT t.*, u.id AS child_id
                FROM tests t JOIN users u ON t.user_id = u.id
                WHERE t.id = ? AND t.status = 'completed'
            ");
            $stmt->execute([$testId]);
        } else {
            $stmt = db()->prepare("
                SELECT t.*, u.id AS child_id
                FROM tests t
                JOIN users u ON t.user_id = u.id
                JOIN child_admins ca ON u.id = ca.child_id
                WHERE t.id = ? AND ca.admin_id = ? AND t.status = 'completed'
            ");
            $stmt->execute([$testId, $adminId]);
        }
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Erstellt einen AIService für das Kind (versucht Primary-Admin, Fallback: aufrufender Admin).
     */
    private static function makeAIService(int $childId, int $callerAdminId): AIService
    {
        try {
            return new AIService($childId);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Primary-Admin') || str_contains($e->getMessage(), 'api_key')) {
                return new AIService($callerAdminId);
            }
            throw $e;
        }
    }

    /**
     * Lädt Testmetadaten, Kindprofil und beantwortete Items.
     * @return array{0: array, 1: array, 2: array}  [$testMeta, $userProfile, $items]
     */
    private static function loadTestContext(int $testId, int $childId): array
    {
        $childStmt = db()->prepare(
            "SELECT id, display_name, grade_level, school_type, theme FROM users WHERE id=?"
        );
        $childStmt->execute([$childId]);
        $child = $childStmt->fetch();
        if (!$child) throw new \RuntimeException("Kind (id={$childId}) nicht gefunden.");

        $childSettings = EncryptionService::make()->loadUserSettings($childId);

        $userProfile = [
            'grade_level'   => (int)($child['grade_level'] ?? 4),
            'school_type'   => $child['school_type']   ?? 'Grundschule',
            'federal_state' => $childSettings['federal_state'] ?? 'Bayern',
            'theme'         => $child['theme']          ?? 'minecraft',
            'display_name'  => $child['display_name']   ?? 'Kind',
        ];

        $testStmt = db()->prepare("SELECT type FROM tests WHERE id=?");
        $testStmt->execute([$testId]);
        $testRow = $testStmt->fetch();

        $testMeta = [
            'type'      => $testRow['type'] ?? 'initial',
            'user_name' => $child['display_name'],
        ];

        $items = self::loadTestItems($testId);

        return [$testMeta, $userProfile, $items];
    }

    /**
     * Lädt alle beantworteten Test-Items mit korrekten Antworten aus der words-Tabelle.
     */
    private static function loadTestItems(int $testId): array
    {
        $stmt = db()->prepare("
            SELECT ti.id, ti.format, ti.user_input, ti.is_correct,
                   ti.error_categories, ti.response_time_ms, ti.replay_count,
                   w.word            AS correct_word,
                   w.primary_category,
                   ts.block,
                   ts.order_index    AS section_order,
                   ti.order_index    AS item_order
            FROM test_items    ti
            JOIN test_sections ts ON ti.section_id = ts.id
            LEFT JOIN words    w  ON ti.word_id    = w.id
            WHERE ts.test_id = ? AND ti.answered_at IS NOT NULL
            ORDER BY ts.order_index ASC, ti.order_index ASC
        ");
        $stmt->execute([$testId]);
        $rows = $stmt->fetchAll();

        return array_map(fn($r) => [
            'primary_category' => $r['primary_category']  ?? 'A1',
            'is_correct'       => (int)($r['is_correct']  ?? 0),
            'user_input'       => $r['user_input']         ?? '',
            'correct'          => $r['correct_word']       ?? '',
            'error_categories' => json_decode($r['error_categories'] ?? '[]', true) ?? [],
            'response_time_ms' => (int)($r['response_time_ms'] ?? 0),
            'replay_count'     => (int)($r['replay_count']     ?? 0),
            'block'            => $r['block']              ?? 'A',
        ], $rows);
    }

    /**
     * Speichert test_results (eine Zeile pro Fehlerkategorie).
     * Überschreibt bestehende Einträge (bei Wiederholung).
     */
    private static function saveTestResults(int $testId, array $results): void
    {
        $stmt = db()->prepare("
            INSERT OR REPLACE INTO test_results
              (test_id, block, category, total_items, correct_items,
               error_rate, severity, strategy_level, compared_delta)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)
        ");

        foreach ($results as $r) {
            $category  = $r['category']       ?? 'A1';
            $block     = substr($category, 0, 1); // 'A1' → 'A'
            $errorRate = (float)($r['error_rate']  ?? 0.0);
            $total     = (int)round(
                $errorRate > 0 ? ($r['correct_items_calc'] ?? 1) / max(1 - $errorRate, 0.01) : 3
            );
            // Falls total/correct direkt geliefert werden, nutze diese
            if (isset($r['total_items'], $r['correct_items'])) {
                $total   = (int)$r['total_items'];
                $correct = (int)$r['correct_items'];
            } else {
                $correct = (int)round($total * (1 - $errorRate));
            }

            $stmt->execute([
                $testId,
                $block,
                $category,
                $total,
                $correct,
                $errorRate,
                $r['severity']       ?? 'none',
                (int)($r['strategy_level'] ?? 1),
            ]);
        }
    }

    /**
     * Speichert learning_plan mit allen Biomen, Quests und plan_units.
     *
     * @return int  ID des neuen learning_plan
     */
    private static function saveLearningPlan(
        int    $testId,
        int    $userId,
        array  $planResult,
        array  $severityMap,
        array  $strategyMap,
        string $aiNotes
    ): int {
        $db = db();
        $db->beginTransaction();

        try {
            // learning_plans
            $db->prepare(
                "INSERT INTO learning_plans (user_id, test_id, status, ai_notes)
                 VALUES (?, ?, 'draft', ?)"
            )->execute([$userId, $testId, $aiNotes]);
            $planId = (int)$db->lastInsertId();

            foreach ($planResult['biomes'] ?? [] as $biomeData) {
                // plan_biomes
                $db->prepare(
                    "INSERT INTO plan_biomes
                       (plan_id, block, name, theme_biome, order_index, status)
                     VALUES (?, ?, ?, ?, ?, 'locked')"
                )->execute([
                    $planId,
                    $biomeData['block']       ?? 'A',
                    $biomeData['name']        ?? 'Biom',
                    $biomeData['theme_biome'] ?? 'forest',
                    (int)($biomeData['order_index'] ?? 0),
                ]);
                $biomeId = (int)$db->lastInsertId();

                foreach ($biomeData['quests'] ?? [] as $questData) {
                    $category = $questData['category'] ?? '';
                    $severity = $severityMap[$category]  ?? 'mild';
                    $strategy = $strategyMap[$category]  ?? 1;

                    // Quests mit severity='none' überspringen
                    $status = ($severity === 'none') ? 'skipped' : 'locked';

                    $db->prepare(
                        "INSERT INTO quests
                           (biome_id, category, title, description,
                            order_index, status, difficulty, required_score, ai_notes)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    )->execute([
                        $biomeId,
                        $category,
                        $questData['title']          ?? 'Quest',
                        $questData['description']    ?? '',
                        (int)($questData['order_index']  ?? 0),
                        $status,
                        (int)($questData['difficulty']   ?? 1),
                        (int)($questData['required_score'] ?? 80),
                        $questData['notes']          ?? '',
                    ]);
                    $questId = (int)$db->lastInsertId();

                    // plan_units nur für aktive Quests
                    if ($status !== 'skipped') {
                        self::createPlanUnits($questId, $severity, $strategy, $db);
                    }
                }
            }

            $db->commit();
            return $planId;

        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Erstellt plan_units für eine Quest basierend auf Schweregrad und strategy_level.
     * Format-Progression: word → gap → sentence nach Mastery-Stufe.
     */
    private static function createPlanUnits(
        int    $questId,
        string $severity,
        int    $strategyLevel,
        \PDO   $db
    ): void {
        $units = self::UNITS_BY_SEVERITY[$severity] ?? self::UNITS_BY_SEVERITY['mild'];

        // Bei hohem strategy_level Formate nach vorne verschieben
        if ($strategyLevel >= 3) {
            $units = array_map(fn($u) => match($u[0]) {
                'word' => ['gap',      $u[1]],
                'gap'  => ['sentence', $u[1]],
                default => $u,
            }, $units);
        }

        $stmt = $db->prepare(
            "INSERT INTO plan_units (quest_id, order_index, format, word_count, difficulty, status)
             VALUES (?, ?, ?, 20, ?, 'pending')"
        );
        foreach ($units as $i => [$format, $difficulty]) {
            $stmt->execute([$questId, $i, $format, $difficulty]);
        }
    }

    /**
     * Prüft ob test_results für diesen Test bereits existieren.
     */
    private static function hasExistingAnalysis(int $testId): bool
    {
        $stmt = db()->prepare("SELECT COUNT(*) FROM test_results WHERE test_id=?");
        $stmt->execute([$testId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Liefert die plan_id des neuesten Plans für diesen Test (oder null).
     */
    private static function getPlanId(int $testId): ?int
    {
        $stmt = db()->prepare(
            "SELECT id FROM learning_plans WHERE test_id=? ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$testId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }
}
