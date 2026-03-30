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

        $db = db();

        // Filter aus GET-Parametern
        $filterCat    = $_GET['cat']    ?? '';
        $filterGrade  = (int)($_GET['grade'] ?? 0);
        $filterSource = $_GET['source'] ?? '';
        $filterActive = $_GET['active'] ?? 'all';
        $search       = trim($_GET['q'] ?? '');

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

        $words = $db->prepare(
            "SELECT id, word, primary_category, grade_level, difficulty, source, active, created_at
             FROM words WHERE {$whereStr} ORDER BY primary_category, grade_level, word LIMIT 500"
        );
        $words->execute($params);
        $words = $words->fetchAll();

        // Zähler pro Kategorie (ungefiltert, nur aktive)
        $catCounts = [];
        $rows = $db->query(
            "SELECT primary_category, COUNT(*) AS cnt FROM words WHERE active=1 GROUP BY primary_category"
        )->fetchAll();
        foreach ($rows as $r) {
            $catCounts[$r['primary_category']] = (int)$r['cnt'];
        }

        $totalActive   = (int)$db->query("SELECT COUNT(*) FROM words WHERE active=1")->fetchColumn();
        $totalInactive = (int)$db->query("SELECT COUNT(*) FROM words WHERE active=0")->fetchColumn();

        $csrfToken = Auth::csrfToken();

        render('admin/words', compact(
            'words', 'catCounts', 'totalActive', 'totalInactive',
            'filterCat', 'filterGrade', 'filterSource', 'filterActive', 'search',
            'csrfToken'
        ));
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
}
