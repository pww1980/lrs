<?php

namespace App\Services;

/**
 * Stellt sicher, dass pro Kategorie / Bundesland / Klassenstufe
 * mindestens MIN_WORDS aktive Wörter in der Datenbank vorhanden sind.
 *
 * Verwendung (nach Setup-Wizard Schritt 5):
 *   $gen    = new WordGeneratorService($childId);
 *   $report = $gen->ensureWords();
 *   // $report['new_words']  → Summe neu gespeicherter Wörter
 *   // $report['errors']     → nicht-fatale Fehler (z.B. KI-Timeout)
 *
 * Logik (exakt wie in CLAUDE.md):
 *   Für jede Kategorie im Lehrplan-JSON:
 *     COUNT(*) FROM words WHERE primary_category=? AND federal_state=? ...
 *     >= 15 → nichts tun
 *     <  15 → KI generiert fehlende Wörter (20 - vorhandene)
 *   Gespeichert mit source='ai_generated', federal_state, curriculum_ref.
 */
class WordGeneratorService
{
    // Schwellwert (CLAUDE.md: ">= 15 Wörter vorhanden? → nichts tun")
    private const MIN_WORDS      = 15;
    // Zielanzahl pro Generierungsaufruf
    private const GENERATE_COUNT = 20;

    private int    $childUserId;
    private int    $adminUserId;
    private string $federalState;
    private string $schoolType;
    private int    $gradeLevel;
    private string $language     = 'de';

    /** Geladenes Curriculum-JSON inkl. '_file'-Metaschlüssel */
    private ?array $curriculum   = null;

    // ── Konstruktor ───────────────────────────────────────────────────

    /**
     * @param int $childUserId  User-ID des Kindes (role='child').
     * @throws \RuntimeException falls Kind oder zugehöriger Admin nicht gefunden.
     */
    public function __construct(int $childUserId)
    {
        $this->childUserId = $childUserId;
        $this->loadUserProfile();
    }

    // ── Öffentliche API ───────────────────────────────────────────────

    /**
     * Prüft alle Kategorien des passenden Lehrplans und generiert
     * fehlende Wörter via AIService.
     *
     * @return array{
     *   total_categories: int,
     *   skipped:          int,
     *   generated:        int,
     *   new_words:        int,
     *   errors:           string[],
     *   curriculum_file:  string|null
     * }
     */
    /**
     * Gibt alle Kategorie-Codes des passenden Lehrplans zurück (für Batch-UI).
     * @return string[]  z.B. ['A1','A2','A3','B1',…]
     */
    public function getCategoryList(): array
    {
        if (!$this->loadCurriculum()) {
            return [];
        }
        return array_keys($this->curriculum['categories'] ?? []);
    }

    /**
     * Stellt sicher, dass für EINE Kategorie genug Wörter vorhanden sind.
     * Wird vom Batch-Endpoint (/setup/generate-words/batch) pro AJAX-Request aufgerufen.
     *
     * @return array{new_words: int, skipped: bool, error: string|null}
     */
    public function ensureCategory(string $code): array
    {
        if (!$this->loadCurriculum()) {
            return ['new_words' => 0, 'skipped' => false, 'error' => 'Kein Lehrplan-JSON gefunden'];
        }

        $categories = $this->curriculum['categories'] ?? [];
        if (!isset($categories[$code])) {
            return ['new_words' => 0, 'skipped' => false, 'error' => "Kategorie {$code} nicht im Lehrplan"];
        }

        $existing = $this->countExistingWords($code);
        if ($existing >= self::MIN_WORDS) {
            return ['new_words' => 0, 'skipped' => true, 'error' => null];
        }

        $catData  = $categories[$code];
        $needed   = max(1, self::GENERATE_COUNT - $existing);
        $curriculumRef = sprintf('%s, %s', $this->curriculum['source'] ?? 'Lehrplan', $this->curriculum['grades'] ?? (string)$this->gradeLevel);

        try {
            $ai = new AIService($this->adminUserId);
        } catch (\Throwable $e) {
            return ['new_words' => 0, 'skipped' => false, 'error' => 'AIService: ' . $e->getMessage()];
        }

        try {
            $words = $ai->generateWords(
                categoryCode:     $code,
                categoryLabel:    $catData['label']            ?? $code,
                gradeLevel:       $this->gradeLevel,
                federalState:     $this->federalState,
                schoolType:       $this->schoolType,
                curriculumText:   $catData['curriculum_text']  ?? '',
                officialExamples: $catData['examples_official'] ?? [],
                curriculumRef:    $curriculumRef,
                language:         $this->language,
                count:            $needed,
            );
        } catch (\Throwable $e) {
            return ['new_words' => 0, 'skipped' => false, 'error' => "KI-Fehler: " . $e->getMessage()];
        }

        if (empty($words)) {
            return ['new_words' => 0, 'skipped' => false, 'error' => 'KI lieferte keine Wörter zurück'];
        }

        $saved = $this->saveWords($code, $words, $curriculumRef);
        return ['new_words' => $saved, 'skipped' => false, 'error' => null];
    }

    public function ensureWords(): array
    {
        $report = [
            'total_categories' => 0,
            'skipped'          => 0,
            'generated'        => 0,
            'new_words'        => 0,
            'errors'           => [],
            'curriculum_file'  => null,
        ];

        // Passendes Lehrplan-JSON laden
        if (!$this->loadCurriculum()) {
            $report['errors'][] = sprintf(
                'Kein Lehrplan-JSON für "%s" / "%s" / Klasse %d gefunden. '
                . 'Wortgenerierung übersprungen.',
                $this->federalState, $this->schoolType, $this->gradeLevel
            );
            return $report;
        }

        $report['curriculum_file'] = $this->curriculum['_file'] ?? null;
        $categories                = $this->curriculum['categories'] ?? [];
        $report['total_categories'] = count($categories);

        if ($report['total_categories'] === 0) {
            $report['errors'][] = 'Lehrplan-JSON enthält keine Kategorien.';
            return $report;
        }

        // AIService mit Admin-User (hat den API-Key)
        try {
            $ai = new AIService($this->adminUserId);
        } catch (\Throwable $e) {
            $report['errors'][] = 'AIService konnte nicht gestartet werden: ' . $e->getMessage();
            return $report;
        }

        $curriculumRef = sprintf(
            '%s, %s',
            $this->curriculum['source'] ?? 'Lehrplan',
            $this->curriculum['grades'] ?? (string) $this->gradeLevel
        );

        foreach ($categories as $code => $catData) {
            $existing = $this->countExistingWords($code);

            if ($existing >= self::MIN_WORDS) {
                $report['skipped']++;
                continue;
            }

            // Fehlende Wörter generieren (min. 1, max. GENERATE_COUNT)
            $needed = max(1, self::GENERATE_COUNT - $existing);

            try {
                $words = $ai->generateWords(
                    categoryCode:     $code,
                    categoryLabel:    $catData['label']            ?? $code,
                    gradeLevel:       $this->gradeLevel,
                    federalState:     $this->federalState,
                    schoolType:       $this->schoolType,
                    curriculumText:   $catData['curriculum_text']  ?? '',
                    officialExamples: $catData['examples_official'] ?? [],
                    curriculumRef:    $curriculumRef,
                    language:         $this->language,
                    count:            $needed,
                );
            } catch (\Throwable $e) {
                $report['errors'][] = "Kategorie {$code}: KI-Fehler — " . $e->getMessage();
                continue;
            }

            if (empty($words)) {
                $report['errors'][] = "Kategorie {$code}: KI lieferte keine Wörter zurück.";
                continue;
            }

            $saved = $this->saveWords($code, $words, $curriculumRef);
            $report['new_words'] += $saved;
            $report['generated']++;
        }

        return $report;
    }

    // ── Profil laden ──────────────────────────────────────────────────

    /**
     * Lädt grade_level + school_type aus der users-Tabelle,
     * federal_state aus dem verschlüsselten Kind-Setting.
     * Bestimmt außerdem den Primary-Admin (für AIService).
     */
    private function loadUserProfile(): void
    {
        $db = db();

        $stmt = $db->prepare(
            'SELECT grade_level, school_type, role FROM users WHERE id = ? AND active = 1'
        );
        $stmt->execute([$this->childUserId]);
        $user = $stmt->fetch();

        if (!$user || $user['role'] !== 'child') {
            throw new \RuntimeException(
                "WordGeneratorService: Kind {$this->childUserId} nicht gefunden oder kein child-User."
            );
        }

        $this->gradeLevel = (int)    $user['grade_level'];
        $this->schoolType = (string) $user['school_type'];

        // federal_state aus verschlüsseltem Setting (gespeichert in Wizard Schritt 5)
        $settings = EncryptionService::make()->loadUserSettings($this->childUserId);

        if (empty($settings['federal_state'])) {
            throw new \RuntimeException(
                "WordGeneratorService: Setting 'federal_state' für Kind {$this->childUserId} nicht gefunden."
            );
        }
        $this->federalState = $settings['federal_state'];

        // Primary-Admin des Kindes ermitteln
        $adminStmt = $db->prepare(
            "SELECT admin_id FROM child_admins
              WHERE child_id = ? AND role = 'primary'
              LIMIT 1"
        );
        $adminStmt->execute([$this->childUserId]);
        $adminRow = $adminStmt->fetch();

        if (!$adminRow) {
            throw new \RuntimeException(
                "WordGeneratorService: Kein Primary-Admin für Kind {$this->childUserId}."
            );
        }
        $this->adminUserId = (int) $adminRow['admin_id'];
    }

    // ── Lehrplan-JSON finden & laden ──────────────────────────────────

    /**
     * Durchsucht /database/curricula/ nach einer passenden JSON-Datei.
     * Kriterien: language, federal_state, school_type,
     *            grade_min <= grade_level <= grade_max.
     *
     * @return bool true wenn ein passendes Curriculum gefunden und geladen wurde.
     */
    private function loadCurriculum(): bool
    {
        $dir = BASE_DIR . '/database/curricula/';

        if (!is_dir($dir)) {
            return false;
        }

        foreach (glob($dir . '*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }

            if ($this->curriculumMatches($data)) {
                $data['_file']    = basename($file);
                $this->curriculum = $data;
                return true;
            }
        }

        return false;
    }

    /**
     * Prüft ob ein Curriculum-Array zu den Kind-Parametern passt.
     */
    private function curriculumMatches(array $data): bool
    {
        if (($data['language'] ?? '') !== $this->language) {
            return false;
        }

        if (strcasecmp($data['federal_state'] ?? '', $this->federalState) !== 0) {
            return false;
        }

        if (strcasecmp($data['school_type'] ?? '', $this->schoolType) !== 0) {
            return false;
        }

        $gradeMin = (int) ($data['grade_min'] ?? 0);
        $gradeMax = (int) ($data['grade_max'] ?? 0);

        if ($gradeMin === 0 || $gradeMax === 0) {
            return false;
        }

        return $this->gradeLevel >= $gradeMin && $this->gradeLevel <= $gradeMax;
    }

    // ── Wörter zählen ────────────────────────────────────────────────

    /**
     * Zählt vorhandene aktive Wörter für eine Kategorie
     * gefiltert nach Bundesland + Klassenstufe (exakt wie CLAUDE.md).
     */
    private function countExistingWords(string $categoryCode): int
    {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM words
              WHERE primary_category = ?
                AND federal_state    = ?
                AND grade_level      = ?
                AND active           = 1'
        );
        $stmt->execute([$categoryCode, $this->federalState, $this->gradeLevel]);
        return (int) $stmt->fetchColumn();
    }

    // ── Wörter speichern ─────────────────────────────────────────────

    /**
     * Speichert generierte Wörter mit source='ai_generated'.
     * Überspringt Duplikate (gleicher Worttext + Kategorie + Bundesland + Klasse).
     * Speichert Nebenkategorien in word_categories.
     *
     * @param  array  $words         Rückgabe von AIService::generateWords()
     * @param  string $curriculumRef z.B. 'LehrplanPLUS Bayern 2014, 3/4'
     * @return int    Anzahl tatsächlich gespeicherter Wörter
     */
    private function saveWords(string $categoryCode, array $words, string $curriculumRef): int
    {
        $db    = db();
        $saved = 0;

        $insertWord = $db->prepare(
            "INSERT OR IGNORE INTO words
               (word, primary_category, grade_level, difficulty,
                source, federal_state, curriculum_ref, active)
             VALUES (?, ?, ?, ?, 'ai_generated', ?, ?, 1)"
        );

        $insertCat = $db->prepare(
            'INSERT OR IGNORE INTO word_categories (word_id, category)
             VALUES (?, ?)'
        );

        foreach ($words as $w) {
            $word = trim((string) ($w['word'] ?? ''));
            if ($word === '') {
                continue;
            }

            // Duplikat-Check
            $dup = $db->prepare(
                'SELECT id FROM words
                  WHERE word             = ?
                    AND primary_category = ?
                    AND federal_state    = ?
                    AND grade_level      = ?
                  LIMIT 1'
            );
            $dup->execute([$word, $categoryCode, $this->federalState, $this->gradeLevel]);
            if ($dup->fetchColumn() !== false) {
                continue;
            }

            $difficulty = max(1, min(3, (int) ($w['difficulty'] ?? 1)));

            $insertWord->execute([
                $word,
                $categoryCode,
                $this->gradeLevel,
                $difficulty,
                $this->federalState,
                $curriculumRef,
            ]);

            $wordId = (int) $db->lastInsertId();
            if ($wordId === 0) {
                continue;
            }

            // Nebenkategorien speichern
            foreach ((array) ($w['secondary_categories'] ?? []) as $secCat) {
                $secCat = trim((string) $secCat);
                if ($secCat !== '' && $secCat !== $categoryCode) {
                    try {
                        $insertCat->execute([$wordId, $secCat]);
                    } catch (\Throwable) {
                        // Ungültige oder unbekannte Kategorie → überspringen
                    }
                }
            }

            $saved++;
        }

        return $saved;
    }
}
