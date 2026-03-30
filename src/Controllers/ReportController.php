<?php

namespace App\Controllers;

use App\Helpers\Auth;
use App\Services\ProgressTestService;

/**
 * ReportController — PDF-Bericht für die Lehrerin
 *
 * Routen:
 *   GET /admin/report/{child_id}   → show()
 */
class ReportController
{
    /**
     * GET /admin/report/{child_id}
     * Erzeugt einen druckbaren HTML-Bericht (Browser → Drucken → Als PDF speichern).
     */
    public static function show(int $childId): void
    {
        Auth::requireRole('admin', 'superadmin');
        $adminId = (int)$_SESSION['user_id'];

        // Kind validieren — muss diesem Admin gehören
        $childStmt = db()->prepare("
            SELECT u.id, u.display_name, u.grade_level, u.school_type, u.theme,
                   u.created_at
            FROM users u
            JOIN child_admins ca ON u.id = ca.child_id
            WHERE u.id = ? AND ca.admin_id = ? AND u.role = 'child'
        ");
        $childStmt->execute([$childId, $adminId]);
        $child = $childStmt->fetch();

        // Superadmin darf alle Kinder sehen
        if (!$child && ($_SESSION['user_role'] ?? '') === 'superadmin') {
            $childStmt2 = db()->prepare(
                "SELECT id, display_name, grade_level, school_type, theme, created_at
                 FROM users WHERE id=? AND role='child'"
            );
            $childStmt2->execute([$childId]);
            $child = $childStmt2->fetch();
        }

        if (!$child) {
            http_response_code(404);
            echo '<h1>Kind nicht gefunden</h1>';
            return;
        }

        // ── Tests laden ──────────────────────────────────────────────────
        $testsStmt = db()->prepare(
            "SELECT id, type, status, completed_at FROM tests
             WHERE user_id=? AND status='completed'
             ORDER BY completed_at ASC"
        );
        $testsStmt->execute([$childId]);
        $allTests = $testsStmt->fetchAll();

        // Einstufungstest (ältester completed)
        $initialTest = null;
        $latestTest  = null;
        foreach ($allTests as $t) {
            if (!$initialTest && $t['type'] === 'initial') $initialTest = $t;
            $latestTest = $t;
        }

        if (!$initialTest) {
            echo '<h2>Noch kein abgeschlossener Test vorhanden.</h2>';
            return;
        }

        // Testergebnisse laden
        $initialResults = self::loadResults((int)$initialTest['id']);
        $latestResults  = ($latestTest && $latestTest['id'] !== $initialTest['id'])
                          ? self::loadResults((int)$latestTest['id'])
                          : $initialResults;

        // Vergleich berechnen
        $comparison = ($latestTest['id'] !== $initialTest['id'])
                      ? ProgressTestService::compareTests((int)$initialTest['id'], (int)$latestTest['id'])
                      : [];

        // ── Sessions / Aktivität ─────────────────────────────────────────
        $sessStmt = db()->prepare("
            SELECT COUNT(*)                         AS total_sessions,
                   COALESCE(SUM(duration_seconds),0)   AS total_seconds,
                   COALESCE(SUM(total_items),0)         AS total_items,
                   COALESCE(SUM(correct_first_try),0)   AS correct_first,
                   COALESCE(SUM(correct_second_try),0)  AS correct_second,
                   COALESCE(SUM(wrong_total),0)         AS wrong_total,
                   MIN(date(started_at))                AS first_session,
                   MAX(date(started_at))                AS last_session
            FROM sessions WHERE user_id=? AND status='completed'
        ");
        $sessStmt->execute([$childId]);
        $activity = $sessStmt->fetch();

        // Fehlertypen-Verteilung (Top-Kategorien aus Übungseinheiten)
        $errorStmt = db()->prepare("
            SELECT w.primary_category, COUNT(*) AS c
            FROM session_attempts sa
            JOIN session_items si  ON sa.item_id    = si.id
            JOIN sessions sess     ON si.session_id = sess.id
            JOIN words w           ON si.word_id    = w.id
            WHERE sess.user_id=? AND sa.is_correct=0
            GROUP BY w.primary_category
            ORDER BY c DESC LIMIT 10
        ");
        $errorStmt->execute([$childId]);
        $errorDistribution = $errorStmt->fetchAll();

        // ── Plan-Amendments seit letztem Test ────────────────────────────
        $amendStmt = db()->prepare("
            SELECT pa.created_at, pa.ai_reasoning, pa.trigger_type
            FROM plan_amendments pa
            JOIN learning_plans lp ON pa.plan_id = lp.id
            WHERE lp.user_id=? AND pa.admin_approved=0
            ORDER BY pa.created_at DESC LIMIT 5
        ");
        $amendStmt->execute([$childId]);
        $amendments = $amendStmt->fetchAll();

        // ── Stärken/Schwächen ────────────────────────────────────────────
        $strengths = [];
        $weaknesses = [];
        foreach ($latestResults as $r) {
            if ((float)$r['error_rate'] < 0.15) {
                $strengths[] = $r;
            } elseif ((float)$r['error_rate'] >= 0.40) {
                $weaknesses[] = $r;
            }
        }

        // Berichts-Datum
        $reportDate  = date('d.m.Y');
        $adminName   = $_SESSION['display_name'] ?? 'Admin';

        require __DIR__ . '/../Views/admin/report.php';
    }

    // ── Hilfsmethoden ────────────────────────────────────────────────────

    private static function loadResults(int $testId): array
    {
        $stmt = db()->prepare(
            "SELECT block, category, error_rate, severity, strategy_level,
                    total_items, correct_items
             FROM test_results WHERE test_id=? ORDER BY block, category"
        );
        $stmt->execute([$testId]);
        return $stmt->fetchAll();
    }
}
