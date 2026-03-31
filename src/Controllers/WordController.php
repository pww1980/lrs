<?php

namespace App\Controllers;

use App\Helpers\Auth;

/**
 * Wörter-Verwaltung für Admin / Superadmin.
 *
 * GET  /admin/words          → list()    — Übersicht mit Filtern
 * POST /admin/words/toggle   → toggle()  — Wort aktivieren/deaktivieren
 * POST /admin/words/delete   → delete()  — Wort dauerhaft löschen
 * POST /admin/words/add      → add()     — Wort manuell hinzufügen
 */
class WordController
{
    private static array $VALID_CATEGORIES = [
        'A1','A2','A3','B1','B2','B3','B4','B5','C1','C2','C3','D1','D2','D3','D4',
    ];

    public static function list(): void
    {
        Auth::requireRole('admin', 'superadmin');

        $db      = db();
        $adminId = (int)$_SESSION['user_id'];
        $isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';

        // Optionaler Kind-Filter
        $childId   = (int)($_GET['child_id'] ?? 0);
        $childInfo = null;
        if ($childId > 0) {
            $stmt = $isSuperadmin
                ? $db->prepare("SELECT id, display_name, grade_level FROM users WHERE id=? AND role='child'")
                : $db->prepare("SELECT u.id, u.display_name, u.grade_level FROM users u
                                JOIN child_admins ca ON u.id=ca.child_id
                                WHERE u.id=? AND ca.admin_id={$adminId} AND u.role='child'");
            $stmt->execute([$childId]);
            $childInfo = $stmt->fetch() ?: null;
        }

        // Kinder-Liste für Dropdown (eigene Kinder des Admins)
        if ($isSuperadmin) {
            $childrenStmt = $db->query("SELECT id, display_name, grade_level FROM users WHERE role='child' AND active=1 ORDER BY display_name");
        } else {
            $childrenStmt = $db->prepare("SELECT u.id, u.display_name, u.grade_level FROM users u
                JOIN child_admins ca ON u.id=ca.child_id
                WHERE ca.admin_id=? AND u.role='child' AND u.active=1 ORDER BY u.display_name");
            $childrenStmt->execute([$adminId]);
        }
        $children = $childrenStmt->fetchAll();

        // Filter aus GET-Parametern
        $filterCat    = $_GET['cat']    ?? '';
        $filterGrade  = (int)($_GET['grade'] ?? 0);
        $filterSource = $_GET['source'] ?? '';
        $filterActive = $_GET['active'] ?? 'active';
        $search       = trim($_GET['q'] ?? '');

        // Bei Kind-Filter: Klasse vorbelegen
        if ($childInfo && $filterGrade === 0) {
            $filterGrade = (int)($childInfo['grade_level'] ?? 0);
        }

        // Query aufbauen
        $where  = ['1=1'];
        $params = [];

        if ($filterCat && in_array($filterCat, self::$VALID_CATEGORIES)) {
            $where[]  = 'primary_category = ?';
            $params[] = $filterCat;
        }
        if ($filterGrade >= 1 && $filterGrade <= 10) {
            $where[]  = 'grade_level = ?';
            $params[] = $filterGrade;
        }
        if ($filterSource && in_array($filterSource, ['kmk','ai_generated','manual'])) {
            $where[]  = 'source = ?';
            $params[] = $filterSource;
        }
        if ($filterActive === 'active') {
            $where[] = 'active = 1';
        } elseif ($filterActive === 'inactive') {
            $where[] = 'active = 0';
        }
        if ($search !== '') {
            $where[]  = 'word LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $db->prepare(
            "SELECT id, word, primary_category, grade_level, difficulty, source, active
             FROM words WHERE {$whereStr} ORDER BY primary_category, grade_level, word LIMIT 500"
        );
        $stmt->execute($params);
        $words = $stmt->fetchAll();

        // Zähler pro Kategorie (nur aktive, ungefiltert)
        $catCounts = [];
        foreach ($db->query("SELECT primary_category, COUNT(*) AS cnt FROM words WHERE active=1 GROUP BY primary_category")->fetchAll() as $r) {
            $catCounts[$r['primary_category']] = (int)$r['cnt'];
        }

        $totalActive   = (int)$db->query("SELECT COUNT(*) FROM words WHERE active=1")->fetchColumn();
        $totalInactive = (int)$db->query("SELECT COUNT(*) FROM words WHERE active=0")->fetchColumn();
        $csrfToken     = Auth::csrfToken();

        require __DIR__ . '/../Views/admin/words.php';
    }

    public static function toggle(): void
    {
        Auth::requireRole('admin', 'superadmin');
        Auth::verifyCsrf();
        header('Content-Type: application/json');

        $wordId = (int)($_POST['word_id'] ?? 0);
        if ($wordId < 1) { echo json_encode(['error' => 'Ungültige ID']); exit; }

        db()->prepare("UPDATE words SET active = CASE WHEN active=1 THEN 0 ELSE 1 END WHERE id=?")
           ->execute([$wordId]);

        $stmt = db()->prepare("SELECT active FROM words WHERE id=?");
        $stmt->execute([$wordId]);
        $active = (int)$stmt->fetchColumn();

        echo json_encode(['success' => true, 'active' => $active]);
        exit;
    }

    public static function delete(): void
    {
        Auth::requireRole('admin', 'superadmin');
        Auth::verifyCsrf();
        header('Content-Type: application/json');

        $wordId = (int)($_POST['word_id'] ?? 0);
        if ($wordId < 1) { echo json_encode(['error' => 'Ungültige ID']); exit; }

        db()->prepare("DELETE FROM word_categories WHERE word_id=?")->execute([$wordId]);
        db()->prepare("DELETE FROM words WHERE id=?")->execute([$wordId]);

        echo json_encode(['success' => true]);
        exit;
    }

    public static function add(): void
    {
        Auth::requireRole('admin', 'superadmin');
        Auth::verifyCsrf();
        header('Content-Type: application/json');

        $word       = trim($_POST['word']     ?? '');
        $category   = trim($_POST['category'] ?? '');
        $grade      = (int)($_POST['grade']       ?? 4);
        $difficulty = (int)($_POST['difficulty']  ?? 1);

        if ($word === '' || !in_array($category, self::$VALID_CATEGORIES)) {
            echo json_encode(['error' => 'Wort und Kategorie sind erforderlich.']);
            exit;
        }
        $grade      = max(1, min(10, $grade));
        $difficulty = max(1, min(3, $difficulty));

        // Duplikat prüfen
        $exists = db()->prepare("SELECT id FROM words WHERE word=? AND primary_category=? AND grade_level=?");
        $exists->execute([$word, $category, $grade]);
        if ($exists->fetch()) {
            echo json_encode(['error' => 'Dieses Wort existiert bereits in dieser Kategorie und Klasse.']);
            exit;
        }

        $stmt = db()->prepare(
            "INSERT INTO words (word, primary_category, grade_level, difficulty, source, active)
             VALUES (?, ?, ?, ?, 'manual', 1)"
        );
        $stmt->execute([$word, $category, $grade, $difficulty]);
        $newId = (int)db()->lastInsertId();

        echo json_encode(['success' => true, 'id' => $newId, 'word' => $word]);
        exit;
    }

    // ── Wörter re-generieren ───────────────────────────────────────────

    /**
     * GET /admin/words/generate?child_id=X
     * Zeigt Übersicht aller Kategorien mit Wortanzahl + Regenerierungsmöglichkeit.
     */
    public static function generatePage(): void
    {
        Auth::requireRole('admin', 'superadmin');
        $adminId      = (int)$_SESSION['user_id'];
        $isSuperadmin = ($_SESSION['user_role'] ?? '') === 'superadmin';

        // Kind auswählen
        $childId  = (int)($_GET['child_id'] ?? 0);
        $children = [];

        if ($isSuperadmin) {
            $stmt = db()->query(
                "SELECT id, display_name, grade_level FROM users WHERE role='child' AND active=1 ORDER BY display_name"
            );
            $children = $stmt->fetchAll();
        } else {
            $stmt = db()->prepare(
                "SELECT u.id, u.display_name, u.grade_level
                 FROM users u JOIN child_admins ca ON u.id=ca.child_id
                 WHERE ca.admin_id=? AND u.role='child' AND u.active=1
                 ORDER BY u.display_name"
            );
            $stmt->execute([$adminId]);
            $children = $stmt->fetchAll();
        }

        // Erstes Kind vorauswählen wenn nicht angegeben
        if (!$childId && !empty($children)) {
            $childId = (int)$children[0]['id'];
        }

        $childInfo    = null;
        $categoryStatus = [];
        $curriculumMeta = [];
        $error        = null;

        if ($childId) {
            // Kind validieren
            foreach ($children as $ch) {
                if ((int)$ch['id'] === $childId) { $childInfo = $ch; break; }
            }
            if (!$childInfo) { redirect('/admin/words/generate'); }

            try {
                $gen            = new \App\Services\WordGeneratorService($childId);
                $categoryStatus = $gen->getCategoryStatus();
                $curriculumMeta = $gen->getCurriculumMeta();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        require __DIR__ . '/../Views/admin/generate_words.php';
    }

    /**
     * POST /admin/words/generate-batch  (AJAX, JSON)
     * Generiert Wörter für EINE Kategorie — mit optionalem force-Flag.
     */
    public static function generateBatch(): void
    {
        Auth::requireRole('admin', 'superadmin');
        ob_start();

        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
        $token    = $data['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            ob_end_clean();
            http_response_code(403);
            echo json_encode(['error' => 'CSRF']);
            exit;
        }

        $childId  = (int)($data['child_id']  ?? 0);
        $category = preg_replace('/[^A-D0-9]/', '', (string)($data['category'] ?? ''));
        $force    = !empty($data['force']);

        if (!$childId || $category === '') {
            ob_end_clean();
            echo json_encode(['error' => 'Ungültige Parameter']);
            exit;
        }

        try {
            $gen    = new \App\Services\WordGeneratorService($childId);
            $result = $gen->ensureCategory($category, $force);
        } catch (\Throwable $e) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'category' => $category, 'new_words' => 0,
                              'skipped' => false, 'error' => $e->getMessage()]);
            exit;
        }

        ob_end_clean();
        echo json_encode([
            'ok'        => true,
            'category'  => $category,
            'new_words' => $result['new_words'],
            'skipped'   => $result['skipped'],
            'error'     => $result['error'] ?? null,
        ]);
        exit;
    }
}
