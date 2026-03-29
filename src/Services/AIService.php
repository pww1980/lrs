<?php

namespace App\Services;

/**
 * KI-Backend-Abstraktion für Claude / OpenAI / Gemini.
 *
 * Verwendung:
 *   $ai = new AIService($userId);   // userId = Admin oder Kind (→ sucht Primary Admin)
 *   $feedback = $ai->generateFeedback(...);
 *
 * Alle API-Aufrufe werden automatisch in der ai_interactions Tabelle geloggt.
 * Provider und API-Key werden aus den verschlüsselten Admin-Settings geladen.
 */
class AIService
{
    // ── Standard-Modelle pro Provider ────────────────────────────────
    private const MODELS = [
        'claude' => 'claude-sonnet-4-6',
        'openai' => 'gpt-4o',
        'gemini' => 'gemini-1.5-pro',
    ];

    // ── Grobe Kostenabschätzung USD pro Token ─────────────────────────
    private const COST_PER_INPUT_TOKEN = [
        'claude' => 0.000003,
        'openai' => 0.0000025,
        'gemini' => 0.00000125,
    ];
    private const COST_PER_OUTPUT_TOKEN = [
        'claude' => 0.000015,
        'openai' => 0.000010,
        'gemini' => 0.000005,
    ];

    private string   $provider;
    private string   $apiKey;
    private string   $modelVersion;
    private int      $adminUserId;    // User-ID des Admins (besitzt API-Key)
    private ?int     $childUserId;    // User-ID des Kindes, falls Aufruf für ein Kind

    // ── Konstruktor ───────────────────────────────────────────────────

    /**
     * @param int $userId  Kann Admin- oder Kind-User sein.
     *                     Bei Kindern wird automatisch der Primary Admin gesucht.
     */
    public function __construct(int $userId)
    {
        $this->loadSettings($userId);
    }

    // ── Öffentliche Methoden ──────────────────────────────────────────

    /**
     * Feedback zu einer einzelnen Übungsaufgabe.
     *
     * @param  string      $correct    Das korrekte Wort / der korrekte Satz
     * @param  string      $userInput  Was das Kind geschrieben hat
     * @param  string      $category   Fehlerkategorie (z.B. 'B2')
     * @param  int         $gradeLevel Klassenstufe
     * @param  string      $format     'word' | 'gap' | 'sentence' | 'mini_diktat'
     * @param  string      $theme      Aktives Theme (z.B. 'minecraft')
     * @param  int|null    $sessionId  Für Logging
     * @return array{
     *   feedback: string,
     *   rule_explanation: string|null,
     *   error_type: string|null,
     *   second_try_allowed: bool,
     *   error_categories: string[]
     * }
     */
    public function generateFeedback(
        string  $correct,
        string  $userInput,
        string  $category,
        int     $gradeLevel,
        string  $format,
        string  $theme      = 'minecraft',
        ?int    $sessionId  = null
    ): array {
        $isCorrect   = mb_strtolower(trim($correct)) === mb_strtolower(trim($userInput));
        $categoryLabel = $this->getCategoryLabel($category);

        $prompt = <<<PROMPT
Du gibst einem Kind Feedback zu einer Rechtschreib-Aufgabe. Sei freundlich, kurz und altersgerecht.

Schüler: {$gradeLevel}. Klasse, Theme: {$theme}
Format: {$format} (word=Einzelwort, gap=Lückentext, sentence=Satz, mini_diktat=Diktat)
Fehlerkategorie: {$category} – {$categoryLabel}
Korrekte Schreibung: "{$correct}"
Schüler schrieb: "{$userInput}"
Korrekt: {$isCorrectStr}

Regeln für zweiten Versuch (second_try_allowed):
- Tippfehler (1 Zeichen falsch, rest nah dran): JA
- Großschreibung vergessen: JA
- Falsches Wortbild (komplett anders): NEIN
- Auslautverhärtung (Kategorie A1): NEIN

Antworte ausschließlich als gültiges JSON (kein Markdown, keine Erklärung drumherum):
{
  "feedback": "Max. 2 Sätze, motivierend und altersgerecht.",
  "rule_explanation": "Kurze Regelkarte nur bei Fehler, sonst null.",
  "error_type": "z.B. 'auslautverhaertung' oder null bei Erfolg",
  "second_try_allowed": false,
  "error_categories": ["B2"]
}
PROMPT;

        $isCorrectStr = $isCorrect ? 'Ja' : 'Nein';

        // Prompt neu aufbauen mit korrektem Wert
        $prompt = str_replace('{$isCorrectStr}', $isCorrectStr, $prompt);

        $raw = $this->sendPrompt($prompt, 'feedback', maxTokens: 512,
                                 sessionId: $sessionId);

        $data = $this->parseJson($raw);

        return [
            'feedback'           => (string)  ($data['feedback']           ?? ''),
            'rule_explanation'   => isset($data['rule_explanation']) && $data['rule_explanation'] !== null
                                        ? (string) $data['rule_explanation'] : null,
            'error_type'         => isset($data['error_type']) && $data['error_type'] !== null
                                        ? (string) $data['error_type'] : null,
            'second_try_allowed' => (bool)    ($data['second_try_allowed'] ?? false),
            'error_categories'   => (array)   ($data['error_categories']   ?? []),
        ];
    }

    /**
     * Analysiert einen abgeschlossenen Test und erstellt ein Fehlerprofil.
     *
     * @param  array  $testMeta   ['type' => 'initial', 'user_name' => '...', ...]
     * @param  array  $items      Array von test_items mit user_input, is_correct, error_categories
     * @param  array  $userProfile ['grade_level' => 4, 'school_type' => '...', 'federal_state' => '...']
     * @param  int    $testId     Für Logging
     * @return array{
     *   results: array<array{
     *     category: string, error_rate: float, severity: string,
     *     strategy_level: int, notes: string
     *   }>,
     *   overall_notes: string,
     *   fatigue_detected: bool,
     *   recommended_blocks: string[]
     * }
     */
    public function analyzeTest(
        array $testMeta,
        array $items,
        array $userProfile,
        int   $testId
    ): array {
        // Statistiken pro Kategorie aggregieren
        $stats = [];
        foreach ($items as $item) {
            $cats = is_array($item['error_categories'] ?? null)
                ? $item['error_categories']
                : json_decode($item['error_categories'] ?? '[]', true) ?? [];

            // Primärkategorie aus Wort / Satz holen falls vorhanden
            $primary = $item['primary_category'] ?? ($cats[0] ?? 'unknown');

            if (!isset($stats[$primary])) {
                $stats[$primary] = ['total' => 0, 'wrong' => 0, 'examples' => []];
            }
            $stats[$primary]['total']++;
            if (!($item['is_correct'] ?? true)) {
                $stats[$primary]['wrong']++;
                if (count($stats[$primary]['examples']) < 3) {
                    $stats[$primary]['examples'][] =
                        '"' . ($item['correct'] ?? '?') . '" → "' . ($item['user_input'] ?? '?') . '"';
                }
            }
        }

        $statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $profileJson = json_encode($userProfile, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Du bist ein LRS-Experte. Analysiere das Rechtschreib-Fehlerprofil eines Kindes.

Schülerprofil: {$profileJson}
Testtyp: {$testMeta['type']}

Testergebnisse pro Fehlerkategorie (total=Aufgaben, wrong=Fehler, examples=Fehlerbeispiele):
{$statsJson}

Aufgabe:
1. Berechne die Fehlerrate pro Kategorie (wrong/total).
2. Bestimme den Schweregrad: none (<10%), mild (10-30%), moderate (30-60%), severe (>60%)
3. Bestimme strategy_level: 1=Einzelwort, 2=Lückentext, 3=Satz, 4=Mini-Diktat
4. Erkenne KI-Ermüdungszeichen (steigende Fehlerrate im Testverlauf).
5. Empfehle die Bearbeitungsreihenfolge der Blöcke.

Antworte ausschließlich als gültiges JSON:
{
  "results": [
    {
      "category": "B2",
      "error_rate": 0.75,
      "severity": "severe",
      "strategy_level": 1,
      "notes": "Kurze Begründung"
    }
  ],
  "overall_notes": "Gesamtbewertung in 2-3 Sätzen.",
  "fatigue_detected": false,
  "recommended_blocks": ["B", "A", "D", "C"]
}
PROMPT;

        $raw  = $this->sendPrompt($prompt, 'test_analysis', maxTokens: 2048, testId: $testId);
        $data = $this->parseJson($raw);

        return [
            'results'             => (array) ($data['results']             ?? []),
            'overall_notes'       => (string)($data['overall_notes']       ?? ''),
            'fatigue_detected'    => (bool)  ($data['fatigue_detected']    ?? false),
            'recommended_blocks'  => (array) ($data['recommended_blocks']  ?? []),
        ];
    }

    /**
     * Erstellt einen initialen Lernplan aus dem Fehlerprofil.
     *
     * @param  array $errorProfile  Ausgabe von analyzeTest()
     * @param  array $userProfile   ['grade_level', 'school_type', 'federal_state', 'theme', 'display_name']
     * @param  int   $testId        Für Logging
     * @return array{
     *   biomes: array<array{
     *     block: string, name: string, theme_biome: string, order_index: int,
     *     quests: array<array{category: string, title: string, description: string,
     *                          order_index: int, difficulty: int, required_score: int, notes: string}>
     *   }>,
     *   overall_notes: string,
     *   estimated_weeks: int
     * }
     */
    public function generatePlan(
        array $errorProfile,
        array $userProfile,
        int   $testId
    ): array {
        $profileJson = json_encode($userProfile,   JSON_UNESCAPED_UNICODE);
        $resultsJson = json_encode($errorProfile['results'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $theme       = $userProfile['theme'] ?? 'minecraft';

        // Theme-Biome aus theme.json laden
        $themeFile = BASE_DIR . "/themes/{$theme}/theme.json";
        $themeData = file_exists($themeFile)
            ? json_decode(file_get_contents($themeFile), true)
            : [];
        $biomesJson = json_encode($themeData['biomes'] ?? [], JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Du bist ein LRS-Förderplan-Experte. Erstelle einen strukturierten Questlog-basierten Lernplan.

Schülerprofil: {$profileJson}
Theme: {$theme}
Verfügbare Theme-Biome (block_index → Lernblock-Reihenfolge): {$biomesJson}

Fehlerprofil (nach analyzeTest):
{$resultsJson}

Aufgabe:
- Erstelle einen Lernplan mit Biomen (= Fehlerblöcke A/B/C/D) und Quests (= Kategorien A1-D4).
- Priorisiere nach Schweregrad: severe → moderate → mild → none.
- Pro Quest: 3-5 Übungseinheiten bei severe, 2-3 bei moderate, 1-2 bei mild.
- Quests mit severity=none können übersprungen (skipped) werden.
- Titel und Beschreibungen sollen zum Theme passen.

Antworte ausschließlich als gültiges JSON:
{
  "biomes": [
    {
      "block": "B",
      "name": "Die Wüste",
      "theme_biome": "desert",
      "order_index": 1,
      "quests": [
        {
          "category": "B2",
          "title": "Die ck-Brücke",
          "description": "Lerne, wann ck und tz geschrieben wird.",
          "order_index": 1,
          "difficulty": 1,
          "required_score": 80,
          "notes": "Schwerpunkt: kurzer Vokal vor ck"
        }
      ]
    }
  ],
  "overall_notes": "Begründung des Plans in 2-3 Sätzen.",
  "estimated_weeks": 12
}
PROMPT;

        $raw  = $this->sendPrompt($prompt, 'plan_generation', maxTokens: 3000, testId: $testId);
        $data = $this->parseJson($raw);

        return [
            'biomes'          => (array)  ($data['biomes']          ?? []),
            'overall_notes'   => (string) ($data['overall_notes']   ?? ''),
            'estimated_weeks' => (int)    ($data['estimated_weeks'] ?? 12),
        ];
    }

    /**
     * Generiert Übungswörter für eine Fehlerkategorie basierend auf Lehrplandaten.
     *
     * @param  string   $categoryCode      z.B. 'B2'
     * @param  string   $categoryLabel     z.B. 'ck und tz'
     * @param  int      $gradeLevel        Klassenstufe
     * @param  string   $federalState      z.B. 'Bayern'
     * @param  string   $schoolType        z.B. 'Grundschule'
     * @param  string   $curriculumText    Lehrplan-Vorgabe aus curriculum JSON
     * @param  string[] $officialExamples  Offizielle Beispielwörter laut Lehrplan
     * @param  string   $curriculumRef     z.B. 'LehrplanPLUS Bayern 2014'
     * @param  string   $language          'de' | 'en'
     * @param  int      $count             Anzahl zu generierender Wörter (default 20)
     * @return array<array{word: string, difficulty: int, secondary_categories: string[]}>
     */
    public function generateWords(
        string $categoryCode,
        string $categoryLabel,
        int    $gradeLevel,
        string $federalState,
        string $schoolType,
        string $curriculumText,
        array  $officialExamples,
        string $curriculumRef   = '',
        string $language        = 'de',
        int    $count           = 20
    ): array {
        $langName      = $language === 'de' ? 'Deutsch' : 'Englisch';
        $examplesStr   = implode(', ', $officialExamples);
        $curriculumRef = $curriculumRef ?: "{$federalState} {$schoolType} Klasse {$gradeLevel}";

        $prompt = <<<PROMPT
Du bist ein Rechtschreib-Experte für {$langName}-Lernende.
Generiere {$count} altersgerechte Übungswörter passend zu folgendem Lehrplan:

Sprache:        {$langName}
Region:         {$federalState}
Schulform:      {$schoolType}
Jahrgangsstufe: {$gradeLevel}
Kategorie:      {$categoryCode}
Bezeichnung:    {$categoryLabel}
Lehrplan:       {$curriculumRef}

Lehrplan-Vorgabe:
"{$curriculumText}"

Offizielle Beispielwörter laut Lehrplan:
{$examplesStr}

Regeln:
- Wörter aus dem aktiven Wortschatz eines {$gradeLevel}-Klässlers
- Schwierigkeit aufsteigend (difficulty 1=leicht → 3=schwer)
- Keine Wiederholung der offiziellen Beispielwörter
- Nur Wörter bei denen {$categoryCode} der primäre Lernfokus ist
- secondary_categories: weitere Kategorien die das Wort berührt (kann leer sein)

Antworte ausschließlich als gültiges JSON-Array:
[
  {"word": "Brücke",   "difficulty": 1, "secondary_categories": ["D1"]},
  {"word": "Strecke",  "difficulty": 2, "secondary_categories": []},
  ...
]
PROMPT;

        $raw   = $this->sendPrompt($prompt, 'content_generation', maxTokens: 1500);
        $data  = $this->parseJson($raw);

        // Normalisierung: sicherstellen dass ein Array zurückkommt
        if (!is_array($data)) {
            return [];
        }

        return array_map(fn($w) => [
            'word'                => (string) ($w['word']                ?? ''),
            'difficulty'          => (int)    ($w['difficulty']          ?? 1),
            'secondary_categories'=> (array)  ($w['secondary_categories'] ?? []),
        ], array_filter($data, fn($w) => !empty($w['word'])));
    }

    // ── Getter ────────────────────────────────────────────────────────

    public function getProvider(): string     { return $this->provider; }
    public function getModelVersion(): string { return $this->modelVersion; }

    // ── Interne Methoden: API-Aufruf-Dispatcher ───────────────────────

    /**
     * Sendet einen Prompt an den konfigurierten Provider.
     * Loggt das Ergebnis in ai_interactions.
     *
     * @return string  Roh-Text der KI-Antwort (noch nicht geparst)
     */
    private function sendPrompt(
        string  $prompt,
        string  $type,
        int     $maxTokens = 2048,
        ?int    $sessionId = null,
        ?int    $testId    = null
    ): string {
        $startMs = (int) round(microtime(true) * 1000);

        ['text' => $text, 'promptTokens' => $pt, 'completionTokens' => $ct]
            = match ($this->provider) {
                'claude' => $this->callClaude($prompt, $maxTokens),
                'openai' => $this->callOpenAI($prompt, $maxTokens),
                'gemini' => $this->callGemini($prompt, $maxTokens),
                default  => throw new \RuntimeException(
                    "Unbekannter KI-Provider: {$this->provider}"
                ),
            };

        $durationMs   = (int) round(microtime(true) * 1000) - $startMs;
        $costEstimate = $this->estimateCost($pt, $ct);

        $this->logInteraction(
            type:             $type,
            prompt:           $prompt,
            responseJson:     json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
            promptTokens:     $pt,
            completionTokens: $ct,
            costEstimate:     $costEstimate,
            durationMs:       $durationMs,
            sessionId:        $sessionId,
            testId:           $testId
        );

        return $text;
    }

    // ── Provider-Implementierungen ────────────────────────────────────

    /**
     * @return array{text: string, promptTokens: int, completionTokens: int}
     */
    private function callClaude(string $prompt, int $maxTokens): array
    {
        $body = json_encode([
            'model'      => $this->modelVersion,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $result = $this->httpPost(
            'https://api.anthropic.com/v1/messages',
            [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            $body
        );

        $data = $this->decodeApiResponse($result, 'Claude');

        return [
            'text'             => $data['content'][0]['text'] ?? '',
            'promptTokens'     => $data['usage']['input_tokens']  ?? 0,
            'completionTokens' => $data['usage']['output_tokens'] ?? 0,
        ];
    }

    /**
     * @return array{text: string, promptTokens: int, completionTokens: int}
     */
    private function callOpenAI(string $prompt, int $maxTokens): array
    {
        $body = json_encode([
            'model'      => $this->modelVersion,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $result = $this->httpPost(
            'https://api.openai.com/v1/chat/completions',
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            $body
        );

        $data = $this->decodeApiResponse($result, 'OpenAI');

        return [
            'text'             => $data['choices'][0]['message']['content'] ?? '',
            'promptTokens'     => $data['usage']['prompt_tokens']     ?? 0,
            'completionTokens' => $data['usage']['completion_tokens'] ?? 0,
        ];
    }

    /**
     * @return array{text: string, promptTokens: int, completionTokens: int}
     */
    private function callGemini(string $prompt, int $maxTokens): array
    {
        $body = json_encode([
            'contents'          => [['parts' => [['text' => $prompt]]]],
            'generationConfig'  => ['maxOutputTokens' => $maxTokens],
        ]);

        $url    = 'https://generativelanguage.googleapis.com/v1beta/models/'
                . $this->modelVersion
                . ':generateContent?key='
                . urlencode($this->apiKey);

        $result = $this->httpPost(
            $url,
            ['Content-Type: application/json'],
            $body
        );

        $data = $this->decodeApiResponse($result, 'Gemini');

        return [
            'text'             => $data['candidates'][0]['content']['parts'][0]['text'] ?? '',
            'promptTokens'     => $data['usageMetadata']['promptTokenCount']     ?? 0,
            'completionTokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
        ];
    }

    // ── HTTP-Hilfsmethoden ────────────────────────────────────────────

    /**
     * Führt einen HTTP POST via curl aus.
     *
     * @return array{body: string, status: int}
     */
    private function httpPost(string $url, array $headers, string $body, int $timeout = 60): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl ist nicht verfügbar. Bitte php-curl installieren.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("HTTP-Fehler ({$this->provider}): {$error}");
        }

        return ['body' => $response, 'status' => $httpCode];
    }

    /**
     * Decodiert eine API-Antwort und wirft bei HTTP-Fehlern.
     */
    private function decodeApiResponse(array $result, string $providerName): array
    {
        $data = json_decode($result['body'], true);

        if ($result['status'] === 401) {
            throw new \RuntimeException(
                "{$providerName}: Ungültiger API-Key (401). Bitte in den Einstellungen prüfen."
            );
        }
        if ($result['status'] === 429) {
            throw new \RuntimeException(
                "{$providerName}: Rate-Limit erreicht (429). Bitte kurz warten."
            );
        }
        if ($result['status'] >= 500) {
            throw new \RuntimeException(
                "{$providerName}: Server-Fehler ({$result['status']}). Bitte später erneut versuchen."
            );
        }
        if ($result['status'] !== 200) {
            $msg = $data['error']['message'] ?? $data['message'] ?? $result['body'];
            throw new \RuntimeException(
                "{$providerName} API-Fehler ({$result['status']}): {$msg}"
            );
        }
        if (!is_array($data)) {
            throw new \RuntimeException("{$providerName}: Antwort ist kein gültiges JSON.");
        }

        return $data;
    }

    // ── JSON-Parsing ──────────────────────────────────────────────────

    /**
     * Parst JSON aus KI-Antwort — entfernt Markdown-Code-Fences.
     *
     * @throws \JsonException
     */
    private function parseJson(string $raw): array
    {
        // Markdown Code-Fence entfernen: ```json ... ``` oder ``` ... ```
        $clean = preg_replace('/^```(?:json)?\s*/im', '', trim($raw));
        $clean = preg_replace('/\s*```\s*$/m', '', $clean);
        $clean = trim($clean);

        // Falls noch kein JSON-Start, den ersten [ oder { suchen
        if (!str_starts_with($clean, '[') && !str_starts_with($clean, '{')) {
            if (preg_match('/(\[[\s\S]*\]|\{[\s\S]*\})/s', $clean, $m)) {
                $clean = $m[1];
            }
        }

        return json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
    }

    // ── Logging ───────────────────────────────────────────────────────

    private function logInteraction(
        string  $type,
        string  $prompt,
        string  $responseJson,
        int     $promptTokens,
        int     $completionTokens,
        float   $costEstimate,
        int     $durationMs,
        ?int    $sessionId,
        ?int    $testId
    ): void {
        try {
            db()->prepare(
                'INSERT INTO ai_interactions
                   (user_id, session_id, test_id, type, ai_provider, model_version,
                    prompt_tokens, completion_tokens, cost_estimate,
                    prompt_used, response_json, duration_ms)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $this->childUserId ?? $this->adminUserId,
                $sessionId,
                $testId,
                $type,
                $this->provider,
                $this->modelVersion,
                $promptTokens,
                $completionTokens,
                $costEstimate,
                $prompt,
                $responseJson,
                $durationMs,
            ]);
        } catch (\Throwable $e) {
            // Logging darf niemals die Hauptfunktion unterbrechen
            error_log('AIService logging error: ' . $e->getMessage());
        }
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────

    private function estimateCost(int $promptTokens, int $completionTokens): float
    {
        $inRate  = self::COST_PER_INPUT_TOKEN[$this->provider]  ?? 0.000003;
        $outRate = self::COST_PER_OUTPUT_TOKEN[$this->provider] ?? 0.000015;
        return round($promptTokens * $inRate + $completionTokens * $outRate, 8);
    }

    private function getCategoryLabel(string $code): string
    {
        static $cache = [];
        if (isset($cache[$code])) {
            return $cache[$code];
        }
        try {
            $row = db()->prepare(
                "SELECT label FROM categories WHERE code = ? LIMIT 1"
            );
            $row->execute([$code]);
            $cache[$code] = (string) ($row->fetchColumn() ?: $code);
        } catch (\Throwable) {
            $cache[$code] = $code;
        }
        return $cache[$code];
    }

    // ── Settings laden ────────────────────────────────────────────────

    private function loadSettings(int $userId): void
    {
        $db = db();

        // Rolle bestimmen
        $stmt = $db->prepare('SELECT role FROM users WHERE id = ? AND active = 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new \RuntimeException("User {$userId} nicht gefunden oder inaktiv.");
        }

        $adminId         = $userId;
        $this->childUserId = null;

        if ($user['role'] === 'child') {
            // Primary Admin des Kindes suchen
            $adminStmt = $db->prepare(
                "SELECT admin_id FROM child_admins WHERE child_id = ? AND role = 'primary' LIMIT 1"
            );
            $adminStmt->execute([$userId]);
            $adminRow = $adminStmt->fetch();

            if (!$adminRow) {
                throw new \RuntimeException(
                    "Kein Primary-Admin für Kind {$userId} konfiguriert."
                );
            }
            $adminId           = (int) $adminRow['admin_id'];
            $this->childUserId = $userId;
        }

        $this->adminUserId = $adminId;

        // Admin-Settings entschlüsseln
        $settings = EncryptionService::make()->loadUserSettings($adminId);

        $this->provider = $settings['ai_provider'] ?? 'claude';

        if (!array_key_exists($this->provider, self::MODELS)) {
            $this->provider = 'claude';
        }

        $this->apiKey = $settings['ai_api_key'] ?? '';

        if ($this->apiKey === '') {
            throw new \RuntimeException(
                "Kein AI-API-Key konfiguriert. Bitte in den Admin-Einstellungen hinterlegen."
            );
        }

        $this->modelVersion = self::MODELS[$this->provider];
    }
}
