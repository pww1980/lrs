<?php

namespace App\Controllers;

use App\Helpers\Auth;
use App\Services\EncryptionService;
use App\Services\WordGeneratorService;

/**
 * Setup-Wizard: 5 Schritte zur Ersteinrichtung eines Kind-Accounts.
 *
 * Schritt 1: Kind anlegen (Name, Schulform, Klasse, Bundesland, Theme)
 * Schritt 2: API-Keys (KI-Backend + TTS)
 * Schritt 3: Erklärung der Fehlerkategorien A1–D4
 * Schritt 4: Fortschrittstest-Intervall bestätigen
 * Schritt 5: Einstufungstest starten oder später
 *
 * Guard: läuft nur wenn noch kein child-User existiert.
 * Daten werden Schritt für Schritt in der Session gesammelt und
 * erst in Schritt 5 als Transaktion in die DB geschrieben.
 */
class WizardController
{
    private const TOTAL_STEPS = 5;

    private const STEP_TITLES = [
        1 => 'Kind anlegen',
        2 => 'API-Keys',
        3 => 'Fehlerkategorien',
        4 => 'Testintervall',
        5 => 'Fertig',
    ];

    private const BUNDESLAENDER = [
        'Baden-Württemberg', 'Bayern', 'Berlin', 'Brandenburg', 'Bremen',
        'Hamburg', 'Hessen', 'Mecklenburg-Vorpommern', 'Niedersachsen',
        'Nordrhein-Westfalen', 'Rheinland-Pfalz', 'Saarland', 'Sachsen',
        'Sachsen-Anhalt', 'Schleswig-Holstein', 'Thüringen',
    ];

    private const SCHULFORMEN = [
        'Grundschule', 'Mittelschule', 'Realschule',
        'Gymnasium', 'Gesamtschule', 'Förderschule',
    ];

    // ── Entry Point ───────────────────────────────────────────────────

    public function handle(): void
    {
        Auth::requireRole('admin', 'superadmin');

        // Guard: deaktivieren sobald ein Kind existiert
        $childCount = (int) db()->query(
            "SELECT COUNT(*) FROM users WHERE role = 'child'"
        )->fetchColumn();

        if ($childCount > 0) {
            header('Location: /admin/dashboard');
            exit;
        }

        // Session initialisieren
        if (!isset($_SESSION['wizard'])) {
            $_SESSION['wizard'] = ['current_step' => 1];
        }

        // CSRF-Token sicherstellen
        Auth::csrfToken();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePost();
        } else {
            $this->showCurrentStep();
        }
    }

    // ── POST Dispatcher ───────────────────────────────────────────────

    private function handlePost(): void
    {
        Auth::verifyCsrf();

        $action = $_POST['wizard_action'] ?? 'next';

        if ($action === 'back') {
            $step = (int) ($_SESSION['wizard']['current_step'] ?? 1);
            $_SESSION['wizard']['current_step'] = max(1, $step - 1);
            $_SESSION['wizard']['errors'] = [];
            header('Location: /setup/wizard');
            exit;
        }

        $step = (int) ($_SESSION['wizard']['current_step'] ?? 1);

        match ($step) {
            1       => $this->processStep1(),
            2       => $this->processStep2(),
            3       => $this->processStep3(),
            4       => $this->processStep4(),
            5       => $this->processStep5($action),
            default => null,
        };

        // processStep5 leitet selbst weiter; alle anderen → zurück zum Wizard
        if ($step < 5) {
            header('Location: /setup/wizard');
            exit;
        }
    }

    // ── Schritt 1: Kind anlegen ───────────────────────────────────────

    private function processStep1(): void
    {
        $errors = [];

        $displayName  = trim($_POST['display_name']   ?? '');
        $username     = trim($_POST['username']        ?? '');
        $password     = $_POST['password']             ?? '';
        $passwordConf = $_POST['password_confirm']     ?? '';
        $gradeLevel   = (int) ($_POST['grade_level']   ?? 0);
        $schoolType   = trim($_POST['school_type']     ?? '');
        $federalState = trim($_POST['federal_state']   ?? '');
        $theme        = trim($_POST['theme']           ?? 'minecraft');

        // Validierung
        if ($displayName === '') {
            $errors[] = 'Name des Kindes ist erforderlich.';
        } elseif (mb_strlen($displayName) > 100) {
            $errors[] = 'Name zu lang (max. 100 Zeichen).';
        }

        if ($username === '') {
            $errors[] = 'Benutzername ist erforderlich.';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-]{3,50}$/', $username)) {
            $errors[] = 'Benutzername: 3–50 Zeichen, nur Buchstaben, Ziffern, _ und -.';
        } else {
            $taken = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
            $taken->execute([$username]);
            if ((int) $taken->fetchColumn() > 0) {
                $errors[] = 'Benutzername "' . htmlspecialchars($username) . '" ist bereits vergeben.';
            }
        }

        if (strlen($password) < 6) {
            $errors[] = 'Passwort muss mindestens 6 Zeichen lang sein.';
        }
        if ($password !== $passwordConf) {
            $errors[] = 'Passwörter stimmen nicht überein.';
        }

        if ($gradeLevel < 1 || $gradeLevel > 13) {
            $errors[] = 'Bitte eine gültige Klasse wählen (1–13).';
        }
        if ($schoolType === '' || !in_array($schoolType, self::SCHULFORMEN, true)) {
            $errors[] = 'Bitte eine gültige Schulform wählen.';
        }
        if ($federalState === '' || !in_array($federalState, self::BUNDESLAENDER, true)) {
            $errors[] = 'Bitte ein gültiges Bundesland wählen.';
        }

        // Theme auf verfügbare nicht-gesperrte beschränken
        $availableIds = array_column($this->loadAvailableThemes(), 'id');
        if (!in_array($theme, $availableIds, true)) {
            $theme = 'minecraft';
        }

        if (!empty($errors)) {
            $_SESSION['wizard']['errors']      = $errors;
            $_SESSION['wizard']['step1_input'] = compact(
                'displayName', 'username', 'gradeLevel', 'schoolType', 'federalState', 'theme'
            );
            return;
        }

        $_SESSION['wizard']['errors']    = [];
        $_SESSION['wizard']['step1']     = [
            'display_name'  => $displayName,
            'username'      => $username,
            'password'      => $password,   // Hash erst beim Speichern
            'grade_level'   => $gradeLevel,
            'school_type'   => $schoolType,
            'federal_state' => $federalState,
            'theme'         => $theme,
        ];
        unset($_SESSION['wizard']['step1_input']);
        $_SESSION['wizard']['current_step'] = 2;
    }

    // ── Schritt 2: API-Keys ───────────────────────────────────────────

    private function processStep2(): void
    {
        $errors = [];

        $aiProvider  = trim($_POST['ai_provider']  ?? '');
        $aiApiKey    = trim($_POST['ai_api_key']   ?? '');
        $ttsProvider = trim($_POST['tts_provider'] ?? '');
        $ttsApiKey   = trim($_POST['tts_api_key']  ?? '');

        $validAi  = ['claude', 'openai', 'gemini'];
        $validTts = ['openai_tts', 'google_tts', 'browser'];

        if (!in_array($aiProvider, $validAi, true)) {
            $errors[] = 'Bitte einen gültigen KI-Anbieter wählen.';
        }
        if (!in_array($ttsProvider, $validTts, true)) {
            $errors[] = 'Bitte einen gültigen TTS-Anbieter wählen.';
        }

        // API-Key erforderlich wenn kein Browser-TTS und kein Skip
        $skipKeys = isset($_POST['skip_keys']);
        if (!$skipKeys) {
            if ($aiApiKey === '' && $aiProvider !== '') {
                $errors[] = 'KI-API-Key ist erforderlich (oder "Schlüssel später eingeben" wählen).';
            }
            if ($ttsProvider !== 'browser' && $ttsApiKey === '') {
                $errors[] = 'TTS-API-Key ist erforderlich (oder "Browser-TTS" wählen, die kein Key benötigt).';
            }
        }

        // Encryption-Key prüfen falls API-Keys eingegeben wurden
        if (($aiApiKey !== '' || ($ttsProvider !== 'browser' && $ttsApiKey !== ''))
            && strlen(APP_ENCRYPTION_KEY) < 16
        ) {
            $errors[] = 'APP_ENCRYPTION_KEY fehlt in der .env Datei. '
                      . 'API-Keys können nicht sicher gespeichert werden.';
        }

        if (!empty($errors)) {
            $_SESSION['wizard']['errors']      = $errors;
            $_SESSION['wizard']['step2_input'] = compact(
                'aiProvider', 'aiApiKey', 'ttsProvider', 'ttsApiKey'
            );
            return;
        }

        $_SESSION['wizard']['errors'] = [];
        $_SESSION['wizard']['step2']  = [
            'ai_provider'  => $aiProvider,
            'ai_api_key'   => $aiApiKey,
            'tts_provider' => $ttsProvider,
            'tts_api_key'  => $ttsApiKey,
        ];
        unset($_SESSION['wizard']['step2_input']);
        $_SESSION['wizard']['current_step'] = 3;
    }

    // ── Schritt 3: Fehlerkategorien (nur Info, kein Input) ────────────

    private function processStep3(): void
    {
        $_SESSION['wizard']['errors']       = [];
        $_SESSION['wizard']['current_step'] = 4;
    }

    // ── Schritt 4: Fortschrittstest-Intervall ─────────────────────────

    private function processStep4(): void
    {
        $errors = [];

        $interval = (int) ($_POST['progress_test_interval'] ?? 42);

        if ($interval < 14 || $interval > 365) {
            $errors[] = 'Intervall muss zwischen 14 und 365 Tagen liegen.';
        }

        if (!empty($errors)) {
            $_SESSION['wizard']['errors'] = $errors;
            return;
        }

        $_SESSION['wizard']['errors'] = [];
        $_SESSION['wizard']['step4']  = [
            'progress_test_interval' => $interval,
        ];
        $_SESSION['wizard']['current_step'] = 5;
    }

    // ── Schritt 5: Finalisieren ───────────────────────────────────────

    private function processStep5(string $action): void
    {
        // Pflichtdaten prüfen (sollten durch vorherige Steps gesetzt sein)
        if (empty($_SESSION['wizard']['step1']) || empty($_SESSION['wizard']['step2'])) {
            $_SESSION['wizard']['current_step'] = 1;
            header('Location: /setup/wizard');
            exit;
        }

        $startTest = ($action === 'start_test');

        $d1 = $_SESSION['wizard']['step1'];
        $d2 = $_SESSION['wizard']['step2'];
        $d4 = $_SESSION['wizard']['step4'] ?? ['progress_test_interval' => 42];

        $db = db();
        $db->beginTransaction();

        try {
            // 1. Kind-User anlegen
            $stmt = $db->prepare(
                'INSERT INTO users
                   (username, display_name, password_hash, role, grade_level, school_type, theme, active)
                 VALUES (?, ?, ?, \'child\', ?, ?, ?, 1)'
            );
            $stmt->execute([
                $d1['username'],
                $d1['display_name'],
                password_hash($d1['password'], PASSWORD_BCRYPT),
                $d1['grade_level'],
                $d1['school_type'],
                $d1['theme'],
            ]);
            $childId = (int) $db->lastInsertId();

            // 2. child_admins Eintrag (primary)
            $db->prepare(
                'INSERT INTO child_admins (child_id, admin_id, role) VALUES (?, ?, \'primary\')'
            )->execute([$childId, $_SESSION['user_id']]);

            // 3. Admin-Settings speichern (verschlüsselt)
            $enc = new EncryptionService();
            $adminId = (int) $_SESSION['user_id'];

            $adminSettings = [
                'ai_provider'  => $d2['ai_provider'],
                'tts_provider' => $d2['tts_provider'],
            ];
            // API-Keys nur wenn vorhanden
            if ($d2['ai_api_key'] !== '') {
                $adminSettings['ai_api_key'] = $d2['ai_api_key'];
            }
            if ($d2['tts_provider'] !== 'browser' && $d2['tts_api_key'] !== '') {
                $adminSettings['tts_api_key'] = $d2['tts_api_key'];
            }

            $settingStmt = $db->prepare(
                'INSERT OR REPLACE INTO settings (user_id, key, value_encrypted, updated_at)
                 VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
            );
            foreach ($adminSettings as $key => $value) {
                $settingStmt->execute([$adminId, $key, $enc->encrypt($value)]);
            }

            // 4. Kind-Settings speichern
            $childSettings = [
                'federal_state'           => $d1['federal_state'],
                'progress_test_interval'  => (string) $d4['progress_test_interval'],
            ];
            foreach ($childSettings as $key => $value) {
                $settingStmt->execute([$childId, $key, $enc->encrypt($value)]);
            }

            $db->commit();

        } catch (\Throwable $e) {
            $db->rollBack();
            $_SESSION['wizard']['errors'] = [
                'Fehler beim Speichern: ' . $e->getMessage()
                . ' — Bitte erneut versuchen oder APP_ENCRYPTION_KEY in .env prüfen.',
            ];
            $_SESSION['wizard']['current_step'] = 5;
            header('Location: /setup/wizard');
            exit;
        }

        // Wortgenerierung anstoßen (nach DB-Commit, nicht Teil der Transaktion)
        $wordReport = null;
        try {
            $gen        = new WordGeneratorService($childId);
            $wordReport = $gen->ensureWords();
        } catch (\Throwable $e) {
            error_log("WordGeneratorService Fehler nach Wizard: " . $e->getMessage());
        }

        // Wizard-Session aufräumen
        unset($_SESSION['wizard']);

        // Flash-Nachricht für Dashboard
        $name = htmlspecialchars($d1['display_name']);

        $wordInfo = '';
        if ($wordReport !== null) {
            if ($wordReport['new_words'] > 0) {
                $wordInfo = " {$wordReport['new_words']} Übungswörter wurden automatisch generiert.";
            } elseif (!empty($wordReport['errors'])) {
                $wordInfo = ' Wortgenerierung war nicht möglich (kein API-Key oder kein Lehrplan verfügbar).';
            }
        }

        if ($startTest) {
            $_SESSION['flash'] = [
                'type'    => 'info',
                'message' => "✅ $name wurde angelegt!{$wordInfo} Einstufungstest-Funktion folgt in einem späteren Schritt.",
            ];
        } else {
            $_SESSION['flash'] = [
                'type'    => 'success',
                'message' => "✅ Einrichtung abgeschlossen! $name kann sich jetzt anmelden.{$wordInfo}",
            ];
        }

        header('Location: /admin/dashboard');
        exit;
    }

    // ── View Rendering ────────────────────────────────────────────────

    private function showCurrentStep(): void
    {
        $this->showStep((int) ($_SESSION['wizard']['current_step'] ?? 1));
    }

    private function showStep(int $step): void
    {
        $step = max(1, min(self::TOTAL_STEPS, $step));

        // Variablen für alle Views
        $currentStep   = $step;
        $totalSteps    = self::TOTAL_STEPS;
        $stepTitles    = self::STEP_TITLES;
        $errors        = $_SESSION['wizard']['errors'] ?? [];
        $csrfToken     = Auth::csrfToken();

        // Formulardaten: bevorzuge validierten Schritt, sonst letzten Input-Versuch
        $d1 = $_SESSION['wizard']['step1']       ?? $_SESSION['wizard']['step1_input'] ?? [];
        $d2 = $_SESSION['wizard']['step2']       ?? $_SESSION['wizard']['step2_input'] ?? [];
        $d4 = $_SESSION['wizard']['step4']       ?? [];

        // Step-spezifische Daten
        $themes        = $this->loadAvailableThemes();
        $bundeslaender = self::BUNDESLAENDER;
        $schulformen   = self::SCHULFORMEN;
        $categories    = $this->loadCategories();

        // Errors nach dem Lesen löschen
        unset($_SESSION['wizard']['errors']);

        require BASE_DIR . '/src/Views/setup/wizard.php';
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Lädt alle Themes bei denen locked_by_default = false ist.
     */
    public function loadAvailableThemes(): array
    {
        $themes = [];
        $files  = glob(BASE_DIR . '/themes/*/theme.json') ?: [];
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data) && !($data['locked_by_default'] ?? true)) {
                $themes[] = $data;
            }
        }
        return $themes;
    }

    /**
     * Generiert einen Benutzernamen-Vorschlag aus dem Anzeigenamen.
     * Wird per AJAX vom Frontend abgerufen.
     */
    public static function suggestUsername(string $displayName): string
    {
        $name = mb_strtolower($displayName);

        // Umlaute ersetzen
        $map  = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
                 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue'];
        $name = str_replace(array_keys($map), array_values($map), $name);

        // Nur erlaubte Zeichen behalten
        $name = preg_replace('/[^a-z0-9_\-]/', '', $name);
        $name = trim($name, '_-');

        if (strlen($name) < 3) {
            $name = 'kind';
        }

        // Einzigartigkeit sicherstellen
        $base    = substr($name, 0, 45);
        $attempt = $base;
        $i       = 2;

        $check = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $check->execute([$attempt]);
        while ((int) $check->fetchColumn() > 0) {
            $attempt = $base . $i++;
            $check->execute([$attempt]);
        }

        return $attempt;
    }

    /**
     * Lädt alle Fehlerkategorien (de) aus der DB.
     */
    private function loadCategories(): array
    {
        return db()->query(
            "SELECT code, block, label, description
               FROM categories
              WHERE language = 'de'
              ORDER BY sort_order"
        )->fetchAll();
    }
}
