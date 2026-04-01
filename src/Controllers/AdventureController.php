<?php

namespace App\Controllers;

use App\Helpers\Auth;
use App\Services\AIService;
use App\Services\TTSService;
use App\Services\EncryptionService;

/**
 * AdventureController — Zusätzliche Abenteuer (Schulwörter + KI-Diktat)
 *
 * Routen:
 *   GET  /admin/adventures?child_id=X       → list()
 *   POST /admin/adventures/save             → save()        (Abenteuer + Wörter speichern)
 *   POST /admin/adventures/generate-diktat  → generateDiktat() (KI, AJAX)
 *   POST /admin/adventures/delete           → delete()
 *   POST /admin/adventure-groups/save        → saveGroup()
 *   POST /admin/adventure-groups/delete      → deleteGroup()
 *   POST /learn/adventure/start              → startSession()       (Kind)
 *   POST /learn/adventure-group/start        → startGroupSession()  (Kind)
 *   POST /learn/adventure/complete           → completeSession()    (Kind, AJAX)
 */
class AdventureController
{
    // ── Admin ────────────────────────────────────────────────────────────

    public static function list(): void
    {
        Auth::requireRole('admin', 'superadmin');
        $adminId      = (int)$_SESSION['user_id'];
        $isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
        $childId      = (int)($_GET['child_id'] ?? 0);

        // Kind validieren
        $child = self::loadChild($childId, $adminId, $isSuperadmin);
        if (!$child) { redirect('/admin/dashboard'); }

        // Alle Adventures des Kindes laden
        $stmt = db()->prepare(
            "SELECT ca.*, COUNT(caw.id) AS word_count,
                    COUNT(cas.id) AS sentence_count
             FROM custom_adventures ca
             LEFT JOIN custom_adventure_words caw ON caw.adventure_id = ca.id
             LEFT JOIN custom_adventure_sentences cas ON cas.adventure_id = ca.id
             WHERE ca.child_id = ?
             GROUP BY ca.id
             ORDER BY ca.scheduled_date DESC, ca.created_at DESC"
        );
        $stmt->execute([$childId]);
        $adventures = $stmt->fetchAll();

        // Abenteuer-Gruppen laden
        $grpStmt = db()->prepare(
            "SELECT ag.*,
                    COUNT(DISTINCT agi.adventure_id) AS adventure_count
             FROM adventure_groups ag
             LEFT JOIN adventure_group_items agi ON agi.group_id = ag.id
             WHERE ag.child_id = ?
             GROUP BY ag.id
             ORDER BY ag.scheduled_date DESC, ag.created_at DESC"
        );
        $grpStmt->execute([$childId]);
        $adventureGroups = $grpStmt->fetchAll();

        // Kinder des Admins für Switcher
        $children = self::loadChildrenForAdmin($adminId, $isSuperadmin);

        require __DIR__ . '/../Views/admin/adventures.php';
    }

    public static function save(): void
    {
        Auth::requireRole('admin', 'superadmin');
        Auth::verifyCsrf();

        $adminId      = (int)$_SESSION['user_id'];
        $isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
        $childId      = (int)($_POST['child_id'] ?? 0);
        $title        = trim($_POST['title']          ?? 'Schulaufgabe');
        $schoolDate   = trim($_POST['school_date']     ?? '');
        $schedDate    = trim($_POST['scheduled_date']  ?? date('Y-m-d'));
        $wordsRaw     = trim($_POST['words']           ?? '');
        $repeatable   = isset($_POST['repeatable']) ? 1 : 0;

        $child = self::loadChild($childId, $adminId, $isSuperadmin);
        if (!$child) {
            redirect('/admin/dashboard');
        }

        // Wörter parsen (eine pro Zeile, kommagetrennt auch erlaubt)
        $words = array_values(array_filter(
            array_map('trim', preg_split('/[\n,]+/', $wordsRaw)),
            fn($w) => $w !== ''
        ));

        if (empty($words)) {
            redirect(url('/admin/adventures?child_id=' . $childId) . '&error=no_words');
        }

        $db = db();
        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO custom_adventures
                 (child_id, created_by, title, school_date, scheduled_date, status, repeatable)
                 VALUES (?, ?, ?, ?, ?, 'pending', ?)"
            )->execute([
                $childId,
                $adminId,
                $title ?: 'Schulaufgabe',
                $schoolDate ?: null,
                $schedDate,
                $repeatable,
            ]);
            $adventureId = (int)$db->lastInsertId();

            $wStmt = $db->prepare(
                "INSERT INTO custom_adventure_words (adventure_id, word, order_index) VALUES (?, ?, ?)"
            );
            foreach ($words as $i => $word) {
                $wStmt->execute([$adventureId, $word, $i]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('AdventureController::save — ' . $e->getMessage());
            redirect('/admin/adventures?child_id=' . $childId . '&error=save_failed');
        }

        redirect('/admin/adventures?child_id=' . $childId . '&saved=' . $adventureId);
    }

    /**
     * POST /admin/adventures/generate-diktat  (AJAX)
     * Ruft KI auf, speichert Sätze, cached TTS.
     */
    public static function generateDiktat(): void
    {
        Auth::requireRole('admin', 'superadmin');
        ini_set('display_errors', '0');
        ob_start();
        header('Content-Type: application/json');
        set_time_limit(90);

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $data['csrf_token'] ?? '')) {
            ob_end_clean();
            http_response_code(403);
            echo json_encode(['error' => 'CSRF-Fehler']);
            exit;
        }

        $adminId     = (int)$_SESSION['user_id'];
        $adventureId = (int)($data['adventure_id'] ?? 0);

        // Adventure validieren
        $adv = self::loadAdventureForAdmin($adventureId, $adminId);
        if (!$adv) {
            ob_end_clean();
            http_response_code(404);
            echo json_encode(['error' => 'Abenteuer nicht gefunden']);
            exit;
        }

        // Wörter laden
        $wStmt = db()->prepare(
            "SELECT word FROM custom_adventure_words WHERE adventure_id=? ORDER BY order_index"
        );
        $wStmt->execute([$adventureId]);
        $words = array_column($wStmt->fetchAll(), 'word');

        if (empty($words)) {
            ob_end_clean();
            echo json_encode(['error' => 'Keine Wörter vorhanden']);
            exit;
        }

        try {
            // Kindprofil für Theme/Klasse
            $childStmt = db()->prepare(
                "SELECT grade_level, theme FROM users WHERE id=?"
            );
            $childStmt->execute([$adv['child_id']]);
            $childRow = $childStmt->fetch();

            $childProfile = [
                'grade_level' => (int)($childRow['grade_level'] ?? 4),
                'theme'       => $childRow['theme'] ?? 'minecraft',
            ];

            $ai        = new AIService($adminId);
            $sentences = $ai->generateDiktat($words, $childProfile);

            if (empty($sentences)) {
                throw new \RuntimeException('KI hat keine Sätze generiert.');
            }

            // Alte Sätze löschen, neue speichern
            $db = db();
            $db->prepare("DELETE FROM custom_adventure_sentences WHERE adventure_id=?")
               ->execute([$adventureId]);

            $sStmt = $db->prepare(
                "INSERT INTO custom_adventure_sentences (adventure_id, sentence, order_index) VALUES (?, ?, ?)"
            );
            foreach ($sentences as $i => $sentence) {
                $sStmt->execute([$adventureId, $sentence, $i]);
            }

            $db->prepare(
                "UPDATE custom_adventures SET diktat_generated=1 WHERE id=?"
            )->execute([$adventureId]);

            // TTS vorwärmen (alle Wörter + Sätze)
            $ttsErrors = 0;
            try {
                $tts = new TTSService($adminId);
                if (!$tts->isBrowserTTS()) {
                    $allTexts = array_merge($words, $sentences);
                    foreach ($allTexts as $text) {
                        foreach (['normal', 'slow'] as $speed) {
                            try {
                                $tts->synthesizeCached($text, $speed);
                            } catch (\Throwable) {
                                $ttsErrors++;
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // TTS-Fehler sind nicht kritisch
            }

            ob_end_clean();
            echo json_encode([
                'success'   => true,
                'sentences' => $sentences,
                'tts_errors'=> $ttsErrors,
            ]);

        } catch (\Throwable $e) {
            $spurious = ob_get_clean();
            error_log('AdventureController::generateDiktat — ' . $e->getMessage());
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    public static function delete(): void
    {
        Auth::requireRole('admin', 'superadmin');
        Auth::verifyCsrf();

        $adminId     = (int)$_SESSION['user_id'];
        $adventureId = (int)($_POST['adventure_id'] ?? 0);
        $childId     = (int)($_POST['child_id']     ?? 0);

        $adv = self::loadAdventureForAdmin($adventureId, $adminId);
        if ($adv && in_array($adv['status'], ['pending', 'cancelled'])) {
            db()->prepare("DELETE FROM custom_adventures WHERE id=?")->execute([$adventureId]);
        }

        redirect('/admin/adventures?child_id=' . $childId);
    }

    // ── Admin: Abenteuer-Gruppen ─────────────────────────────────────────

    /**
     * POST /admin/adventure-groups/save
     * Erstellt ein neues Abenteuer-Paket (Gruppe mehrerer Adventures).
     */
    public static function saveGroup(): void
    {
        Auth::requireRole('admin', 'superadmin');
        Auth::verifyCsrf();

        $adminId      = (int)$_SESSION['user_id'];
        $isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
        $childId      = (int)($_POST['child_id']     ?? 0);
        $title        = trim($_POST['group_title']   ?? 'Abenteuerpaket');
        $schedDate    = trim($_POST['group_sched']   ?? date('Y-m-d'));
        $repeatable   = isset($_POST['group_repeatable']) ? 1 : 0;
        $advIds       = array_map('intval', (array)($_POST['group_adventures'] ?? []));
        $advIds       = array_filter($advIds);

        $child = self::loadChild($childId, $adminId, $isSuperadmin);
        if (!$child || empty($advIds)) {
            redirect('/admin/adventures?child_id=' . $childId . '&error=group_invalid');
        }

        $db = db();
        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO adventure_groups (child_id, created_by, title, scheduled_date, repeatable)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$childId, $adminId, $title ?: 'Abenteuerpaket', $schedDate, $repeatable]);
            $groupId = (int)$db->lastInsertId();

            $iStmt = $db->prepare(
                "INSERT INTO adventure_group_items (group_id, adventure_id, order_index) VALUES (?, ?, ?)"
            );
            foreach (array_values($advIds) as $idx => $advId) {
                $iStmt->execute([$groupId, $advId, $idx]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('AdventureController::saveGroup — ' . $e->getMessage());
            redirect('/admin/adventures?child_id=' . $childId . '&error=group_save_failed');
        }

        redirect('/admin/adventures?child_id=' . $childId . '&group_saved=1');
    }

    /**
     * POST /admin/adventure-groups/delete
     */
    public static function deleteGroup(): void
    {
        Auth::requireRole('admin', 'superadmin');
        Auth::verifyCsrf();

        $adminId  = (int)$_SESSION['user_id'];
        $groupId  = (int)($_POST['group_id']  ?? 0);
        $childId  = (int)($_POST['child_id']  ?? 0);

        $isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
        if ($isSuperadmin) {
            $stmt = db()->prepare("SELECT id FROM adventure_groups WHERE id=?");
            $stmt->execute([$groupId]);
        } else {
            $stmt = db()->prepare(
                "SELECT ag.id FROM adventure_groups ag
                 JOIN child_admins ch ON ag.child_id = ch.child_id
                 WHERE ag.id=? AND ch.admin_id=?"
            );
            $stmt->execute([$groupId, $adminId]);
        }
        if ($stmt->fetch()) {
            db()->prepare("DELETE FROM adventure_groups WHERE id=?")->execute([$groupId]);
        }

        redirect('/admin/adventures?child_id=' . $childId);
    }

    // ── Kind: Session starten ────────────────────────────────────────────

    /**
     * POST /learn/adventure/start
     * Erstellt eine neue Session für ein Custom Adventure.
     */
    public static function startSession(): void
    {
        Auth::requireRole('child');
        Auth::verifyCsrf();

        $userId      = (int)$_SESSION['user_id'];
        $adventureId = (int)($_POST['adventure_id'] ?? 0);

        // Adventure validieren — muss diesem Kind gehören und pending sein
        $advStmt = db()->prepare(
            "SELECT * FROM custom_adventures
             WHERE id=? AND child_id=? AND status='pending'"
        );
        $advStmt->execute([$adventureId, $userId]);
        $adv = $advStmt->fetch();

        if (!$adv) {
            redirect('/learn/questlog');
        }

        // Laufende Adventure-Sessions abbrechen
        db()->prepare(
            "UPDATE sessions SET status='aborted'
             WHERE user_id=? AND custom_adventure_id=? AND status='active'"
        )->execute([$userId, $adventureId]);

        // Wörter + Sätze laden
        $wStmt = db()->prepare(
            "SELECT word FROM custom_adventure_words WHERE adventure_id=? ORDER BY order_index"
        );
        $wStmt->execute([$adventureId]);
        $words = array_column($wStmt->fetchAll(), 'word');

        $sStmt = db()->prepare(
            "SELECT sentence FROM custom_adventure_sentences WHERE adventure_id=? ORDER BY order_index"
        );
        $sStmt->execute([$adventureId]);
        $sentences = array_column($sStmt->fetchAll(), 'sentence');

        if (empty($words) && empty($sentences)) {
            redirect('/learn/questlog');
        }

        $db = db();
        $db->beginTransaction();
        try {
            // Session erstellen (plan_unit_id = NULL)
            $db->prepare(
                "INSERT INTO sessions (user_id, custom_adventure_id, plan_unit_id, status)
                 VALUES (?, ?, NULL, 'active')"
            )->execute([$userId, $adventureId]);
            $sessionId = (int)$db->lastInsertId();

            // Items: erst alle Wörter, dann alle Sätze
            $iStmt = $db->prepare(
                "INSERT INTO session_items
                 (session_id, format, order_index, custom_text, second_try_allowed)
                 VALUES (?, ?, ?, ?, 0)"
            );
            $idx = 0;
            foreach ($words as $word) {
                $iStmt->execute([$sessionId, 'word', $idx++, $word]);
            }
            foreach ($sentences as $sentence) {
                $iStmt->execute([$sessionId, 'sentence', $idx++, $sentence]);
            }

            $db->prepare(
                "UPDATE sessions SET total_items=? WHERE id=?"
            )->execute([$idx, $sessionId]);

            // Adventure auf 'active' setzen
            $db->prepare(
                "UPDATE custom_adventures SET status='active' WHERE id=?"
            )->execute([$adventureId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('AdventureController::startSession — ' . $e->getMessage());
            redirect('/learn/questlog?error=start_failed');
        }

        redirect('/learn/adventure?session_id=' . $sessionId);
    }

    /**
     * POST /learn/adventure-group/start
     * Startet eine Session für ein Abenteuer-Paket (Gruppe).
     */
    public static function startGroupSession(): void
    {
        Auth::requireRole('child');
        Auth::verifyCsrf();

        $userId  = (int)$_SESSION['user_id'];
        $groupId = (int)($_POST['group_id'] ?? 0);

        // Gruppe validieren — muss diesem Kind gehören und pending sein
        $grpStmt = db()->prepare(
            "SELECT * FROM adventure_groups WHERE id=? AND child_id=? AND status='pending'"
        );
        $grpStmt->execute([$groupId, $userId]);
        $group = $grpStmt->fetch();

        if (!$group) { redirect('/learn/questlog'); }

        // Alle Adventures der Gruppe laden (geordnet)
        $members = db()->prepare(
            "SELECT agi.adventure_id, agi.order_index
             FROM adventure_group_items agi
             WHERE agi.group_id=? ORDER BY agi.order_index"
        );
        $members->execute([$groupId]);
        $memberAdvIds = array_column($members->fetchAll(), 'adventure_id');

        if (empty($memberAdvIds)) { redirect('/learn/questlog'); }

        // Laufende Gruppen-Sessions abbrechen
        db()->prepare(
            "UPDATE sessions SET status='aborted' WHERE user_id=? AND adventure_group_id=? AND status='active'"
        )->execute([$userId, $groupId]);

        $db = db();
        $db->beginTransaction();
        try {
            // Session erstellen (plan_unit_id = NULL)
            $db->prepare(
                "INSERT INTO sessions (user_id, adventure_group_id, plan_unit_id, status) VALUES (?, ?, NULL, 'active')"
            )->execute([$userId, $groupId]);
            $sessionId = (int)$db->lastInsertId();

            $iStmt = $db->prepare(
                "INSERT INTO session_items (session_id, format, order_index, custom_text, second_try_allowed)
                 VALUES (?, ?, ?, ?, 0)"
            );
            $idx = 0;
            foreach ($memberAdvIds as $advId) {
                $wStmt = db()->prepare(
                    "SELECT word FROM custom_adventure_words WHERE adventure_id=? ORDER BY order_index"
                );
                $wStmt->execute([$advId]);
                foreach (array_column($wStmt->fetchAll(), 'word') as $word) {
                    $iStmt->execute([$sessionId, 'word', $idx++, $word]);
                }
                $sStmt = db()->prepare(
                    "SELECT sentence FROM custom_adventure_sentences WHERE adventure_id=? ORDER BY order_index"
                );
                $sStmt->execute([$advId]);
                foreach (array_column($sStmt->fetchAll(), 'sentence') as $sent) {
                    $iStmt->execute([$sessionId, 'sentence', $idx++, $sent]);
                }
            }

            if ($idx === 0) {
                $db->rollBack();
                redirect('/learn/questlog');
            }

            $db->prepare("UPDATE sessions SET total_items=? WHERE id=?")->execute([$idx, $sessionId]);
            $db->prepare("UPDATE adventure_groups SET status='active' WHERE id=?")->execute([$groupId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('AdventureController::startGroupSession — ' . $e->getMessage());
            redirect('/learn/questlog?error=start_failed');
        }

        redirect('/learn/adventure?session_id=' . $sessionId);
    }

    /**
     * POST /learn/adventure/complete  (AJAX)
     * Schließt die Adventure-Session ab.
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

        $sesStmt = db()->prepare(
            "SELECT s.*, ca.id AS adv_id, ca.repeatable AS adv_repeatable
             FROM sessions s
             JOIN custom_adventures ca ON s.custom_adventure_id = ca.id
             WHERE s.id=? AND s.user_id=? AND s.status='active'"
        );
        $sesStmt->execute([$sessionId, $userId]);
        $session = $sesStmt->fetch();

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'Session nicht gefunden']);
            exit;
        }

        // Alle Items beantwortet?
        $pendingStmt = db()->prepare(
            "SELECT COUNT(*) FROM session_items WHERE session_id=? AND final_correct IS NULL"
        );
        $pendingStmt->execute([$sessionId]);
        if ((int)$pendingStmt->fetchColumn() > 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Noch nicht alle Items beantwortet']);
            exit;
        }

        // Stats berechnen
        $statsStmt = db()->prepare(
            "SELECT
               SUM(CASE WHEN final_correct=1 THEN 1 ELSE 0 END) AS correct,
               SUM(CASE WHEN final_correct=0 THEN 1 ELSE 0 END) AS wrong,
               COUNT(*) AS total
             FROM session_items WHERE session_id=?"
        );
        $statsStmt->execute([$sessionId]);
        $stats = $statsStmt->fetch();

        $db = db();
        $db->beginTransaction();
        try {
            $db->prepare(
                "UPDATE sessions
                 SET status='completed', completed_at=CURRENT_TIMESTAMP,
                     duration_seconds = CAST((julianday('now') - julianday(started_at)) * 86400 AS INTEGER),
                     correct_first_try = ?, wrong_total = ?
                 WHERE id=?"
            )->execute([(int)$stats['correct'], (int)$stats['wrong'], $sessionId]);

            // Wiederholbar → 'pending' zurücksetzen, sonst 'completed'
            $newAdvStatus = $session['adv_repeatable'] ? 'pending' : 'completed';
            $db->prepare(
                "UPDATE custom_adventures SET status=?, completed_at=CURRENT_TIMESTAMP WHERE id=?"
            )->execute([$newAdvStatus, $session['adv_id']]);

            $db->commit();

            echo json_encode([
                'success'  => true,
                'correct'  => (int)$stats['correct'],
                'wrong'    => (int)$stats['wrong'],
                'total'    => (int)$stats['total'],
            ]);
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('AdventureController::completeSession — ' . $e->getMessage());
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * GET /learn/adventure?session_id=X
     * Zeigt die Adventure-Session-Seite.
     */
    public static function showSession(): void
    {
        Auth::requireRole('child');
        $userId    = (int)$_SESSION['user_id'];
        $sessionId = (int)($_GET['session_id'] ?? 0);

        if (!$sessionId) { redirect('/learn/questlog'); }

        $sesStmt = db()->prepare(
            "SELECT s.*, ca.title AS adventure_title,
                    ca.id AS adventure_id
             FROM sessions s
             JOIN custom_adventures ca ON s.custom_adventure_id = ca.id
             WHERE s.id=? AND s.user_id=?"
        );
        $sesStmt->execute([$sessionId, $userId]);
        $session = $sesStmt->fetch();

        if (!$session) { redirect('/learn/questlog'); }

        $items = db()->prepare(
            "SELECT si.*,
                    (SELECT sa.user_input FROM session_attempts sa WHERE sa.item_id=si.id ORDER BY sa.attempt_number DESC LIMIT 1) AS last_input
             FROM session_items si
             WHERE si.session_id=?
             ORDER BY si.order_index"
        );
        $items->execute([$sessionId]);
        $items = $items->fetchAll();

        $theme = self::loadTheme($_SESSION['theme'] ?? 'minecraft');

        require __DIR__ . '/../Views/learn/adventure_session.php';
    }

    // ── Private Hilfsmethoden ────────────────────────────────────────────

    private static function loadChild(int $childId, int $adminId, bool $isSuperadmin): ?array
    {
        if (!$childId) return null;
        if ($isSuperadmin) {
            $stmt = db()->prepare("SELECT id, display_name, grade_level, theme FROM users WHERE id=? AND role='child'");
            $stmt->execute([$childId]);
        } else {
            $stmt = db()->prepare(
                "SELECT u.id, u.display_name, u.grade_level, u.theme
                 FROM users u JOIN child_admins ca ON u.id=ca.child_id
                 WHERE u.id=? AND ca.admin_id=? AND u.role='child'"
            );
            $stmt->execute([$childId, $adminId]);
        }
        return $stmt->fetch() ?: null;
    }

    private static function loadAdventureForAdmin(int $adventureId, int $adminId): ?array
    {
        $isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';
        if ($isSuperadmin) {
            $stmt = db()->prepare("SELECT * FROM custom_adventures WHERE id=?");
            $stmt->execute([$adventureId]);
        } else {
            $stmt = db()->prepare(
                "SELECT ca.* FROM custom_adventures ca
                 JOIN child_admins ch ON ca.child_id = ch.child_id
                 WHERE ca.id=? AND ch.admin_id=?"
            );
            $stmt->execute([$adventureId, $adminId]);
        }
        return $stmt->fetch() ?: null;
    }

    private static function loadChildrenForAdmin(int $adminId, bool $isSuperadmin): array
    {
        if ($isSuperadmin) {
            $stmt = db()->query("SELECT id, display_name FROM users WHERE role='child' ORDER BY display_name");
        } else {
            $stmt = db()->prepare(
                "SELECT u.id, u.display_name FROM users u
                 JOIN child_admins ca ON u.id=ca.child_id
                 WHERE ca.admin_id=? AND u.role='child' ORDER BY u.display_name"
            );
            $stmt->execute([$adminId]);
        }
        return $stmt->fetchAll();
    }

    private static function loadTheme(string $name): array
    {
        $path = BASE_DIR . "/themes/{$name}/theme.json";
        if (file_exists($path)) {
            return json_decode(file_get_contents($path), true) ?? [];
        }
        return [];
    }
}
