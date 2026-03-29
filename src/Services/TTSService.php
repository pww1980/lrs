<?php

namespace App\Services;

/**
 * Text-to-Speech-Abstraktion für OpenAI TTS / Google Cloud TTS / Browser.
 *
 * Verwendung:
 *   $tts = new TTSService($userId);
 *
 *   // API-Provider: gibt Binärdaten zurück
 *   $result = $tts->synthesize('Fahrrad', speed: 'slow');
 *   // $result = ['audio' => '...binary...', 'mime' => 'audio/mpeg']
 *
 *   // Browser TTS: gibt null zurück, JS übernimmt
 *   if ($tts->isBrowserTTS()) {
 *       $config = $tts->getBrowserConfig(); // für JS
 *   }
 *
 * Provider-Settings (Admin):  tts_provider, tts_api_key
 * Kind-Settings:              tts_voice, tts_speed
 */
class TTSService
{
    // ── Stimmen-Defaults ──────────────────────────────────────────────
    private const DEFAULT_VOICE_OPENAI  = 'nova';
    private const DEFAULT_VOICE_GOOGLE  = 'de-DE-Wavenet-F';
    private const DEFAULT_LANG_GOOGLE   = 'de-DE';

    // ── Geschwindigkeit ───────────────────────────────────────────────
    private const SPEED_RATES = [
        'normal' => 1.0,
        'slow'   => 0.6,
    ];

    private string  $provider;    // 'openai_tts' | 'google_tts' | 'browser'
    private string  $apiKey;
    private string  $voice;       // Stimmen-ID des Anbieters
    private string  $speedSetting; // 'normal' | 'slow'

    // ── Konstruktor ───────────────────────────────────────────────────

    /**
     * @param int $userId  Admin- oder Kind-User-ID.
     *                     Provider/Key kommt vom Admin, Voice/Speed vom Kind.
     */
    public function __construct(int $userId)
    {
        $this->loadSettings($userId);
    }

    // ── Öffentliche API ───────────────────────────────────────────────

    /**
     * Synthetisiert Text zu Audio.
     *
     * @param  string       $text    Der vorzulesende Text (max. 4096 Zeichen für OpenAI)
     * @param  string|null  $voice   Überschreibt die User-Einstellung (optional)
     * @param  string|null  $speed   'normal' | 'slow' — überschreibt User-Einstellung (optional)
     * @return array{audio: string, mime: string}|null
     *         null wenn Browser-TTS aktiv (kein Server-seitiges Audio)
     */
    public function synthesize(string $text, ?string $voice = null, ?string $speed = null): ?array
    {
        if ($this->provider === 'browser') {
            return null;
        }

        $text  = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('TTS: Text darf nicht leer sein.');
        }

        $voice     = $voice ?? $this->voice;
        $speedRate = self::SPEED_RATES[$speed ?? $this->speedSetting] ?? 1.0;

        return match ($this->provider) {
            'openai_tts' => $this->callOpenAITTS($text, $voice, $speedRate),
            'google_tts' => $this->callGoogleTTS($text, $voice, $speedRate),
            default      => throw new \RuntimeException(
                "Unbekannter TTS-Provider: {$this->provider}"
            ),
        };
    }

    /**
     * Gibt true zurück wenn Browser Web Speech API verwendet wird.
     */
    public function isBrowserTTS(): bool
    {
        return $this->provider === 'browser';
    }

    /**
     * Konfiguration für den Browser-TTS-JS-Handler.
     * Wird als JSON an das Frontend übergeben.
     *
     * @return array{provider: 'browser', lang: string, rate_normal: float, rate_slow: float}
     */
    public function getBrowserConfig(): array
    {
        return [
            'provider'    => 'browser',
            'lang'        => 'de-DE',
            'rate_normal' => self::SPEED_RATES['normal'],
            'rate_slow'   => self::SPEED_RATES['slow'],
            'voice_hint'  => $this->voice,   // Bevorzugte Stimme (Browser wählt passende)
        ];
    }

    /**
     * Gibt die aktiven Provider-Einstellungen zurück (ohne API-Key).
     */
    public function getInfo(): array
    {
        return [
            'provider' => $this->provider,
            'voice'    => $this->voice,
            'speed'    => $this->speedSetting,
        ];
    }

    // ── Provider-Implementierungen ────────────────────────────────────

    /**
     * OpenAI TTS API.
     * Docs: https://platform.openai.com/docs/api-reference/audio/createSpeech
     *
     * @return array{audio: string, mime: string}
     */
    private function callOpenAITTS(string $text, string $voice, float $speedRate): array
    {
        // OpenAI: max. 4096 Zeichen; speed 0.25–4.0
        if (mb_strlen($text) > 4096) {
            $text = mb_substr($text, 0, 4096);
        }

        $speedRate = max(0.25, min(4.0, $speedRate));

        $body = json_encode([
            'model'  => 'tts-1',
            'input'  => $text,
            'voice'  => $voice ?: self::DEFAULT_VOICE_OPENAI,
            'speed'  => $speedRate,
            'response_format' => 'mp3',
        ]);

        $result = $this->httpPost(
            'https://api.openai.com/v1/audio/speech',
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            $body,
            binary: true
        );

        if ($result['status'] === 401) {
            throw new \RuntimeException('OpenAI TTS: Ungültiger API-Key (401).');
        }
        if ($result['status'] !== 200) {
            // Fehlerantwort könnte JSON sein
            $err = json_decode($result['body'], true);
            $msg = $err['error']['message'] ?? "HTTP {$result['status']}";
            throw new \RuntimeException("OpenAI TTS Fehler: {$msg}");
        }

        return [
            'audio' => $result['body'],
            'mime'  => 'audio/mpeg',
        ];
    }

    /**
     * Google Cloud Text-to-Speech API.
     * Docs: https://cloud.google.com/text-to-speech/docs/reference/rest
     *
     * @return array{audio: string, mime: string}
     */
    private function callGoogleTTS(string $text, string $voice, float $speedRate): array
    {
        // Google: speed 0.25–4.0 (speakingRate)
        $speedRate = max(0.25, min(4.0, $speedRate));

        // Stimmenname in languageCode + name aufteilen (z.B. "de-DE-Wavenet-F")
        $langCode  = $this->extractGoogleLangCode($voice);
        $voiceName = $voice ?: self::DEFAULT_VOICE_GOOGLE;

        $body = json_encode([
            'input'       => ['text' => $text],
            'voice'       => [
                'languageCode' => $langCode,
                'name'         => $voiceName,
            ],
            'audioConfig' => [
                'audioEncoding' => 'MP3',
                'speakingRate'  => $speedRate,
            ],
        ]);

        $url    = 'https://texttospeech.googleapis.com/v1/text:synthesize?key='
                . urlencode($this->apiKey);

        $result = $this->httpPost(
            $url,
            ['Content-Type: application/json'],
            $body
        );

        if ($result['status'] === 400) {
            $err = json_decode($result['body'], true);
            $msg = $err['error']['message'] ?? 'Bad Request';
            throw new \RuntimeException("Google TTS Fehler: {$msg}");
        }
        if ($result['status'] === 403) {
            throw new \RuntimeException('Google TTS: API-Key ungültig oder TTS-API nicht aktiviert.');
        }
        if ($result['status'] !== 200) {
            throw new \RuntimeException("Google TTS Fehler: HTTP {$result['status']}");
        }

        $data = json_decode($result['body'], true);
        if (!isset($data['audioContent'])) {
            throw new \RuntimeException('Google TTS: Keine audioContent in Antwort.');
        }

        return [
            'audio' => base64_decode($data['audioContent']),
            'mime'  => 'audio/mpeg',
        ];
    }

    // ── HTTP-Hilfsmethode ─────────────────────────────────────────────

    /**
     * @return array{body: string, status: int}
     */
    private function httpPost(
        string $url,
        array  $headers,
        string $body,
        bool   $binary  = false,
        int    $timeout = 30
    ): array {
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
            CURLOPT_BINARYTRANSFER => $binary,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("TTS HTTP-Fehler: {$error}");
        }

        return ['body' => $response, 'status' => $httpCode];
    }

    // ── Settings laden ────────────────────────────────────────────────

    private function loadSettings(int $userId): void
    {
        $db  = db();
        $enc = EncryptionService::make();

        // Rolle bestimmen
        $stmt = $db->prepare('SELECT role FROM users WHERE id = ? AND active = 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new \RuntimeException("User {$userId} nicht gefunden oder inaktiv.");
        }

        $adminId = $userId;
        $childId = null;

        if ($user['role'] === 'child') {
            $adminStmt = $db->prepare(
                "SELECT admin_id FROM child_admins WHERE child_id = ? AND role = 'primary' LIMIT 1"
            );
            $adminStmt->execute([$userId]);
            $adminRow = $adminStmt->fetch();

            if (!$adminRow) {
                throw new \RuntimeException(
                    "Kein Primary-Admin für Kind {$userId} gefunden."
                );
            }
            $adminId = (int) $adminRow['admin_id'];
            $childId = $userId;
        }

        // Admin-Settings: provider + api_key
        $adminSettings = $enc->loadUserSettings($adminId);
        $this->provider = $adminSettings['tts_provider'] ?? 'browser';
        $this->apiKey   = $adminSettings['tts_api_key']  ?? '';

        // Key prüfen (außer bei browser)
        if ($this->provider !== 'browser' && $this->apiKey === '') {
            // Graceful Fallback auf Browser-TTS statt Fehler
            error_log("TTSService: Kein TTS-Key für Provider '{$this->provider}' — Fallback auf Browser-TTS.");
            $this->provider = 'browser';
        }

        // Kind-Settings: voice + speed (falls Kind)
        $userSettings = $childId !== null
            ? $enc->loadUserSettings($childId)
            : $adminSettings;

        $this->speedSetting = $userSettings['tts_speed'] ?? 'normal';

        // Stimme je nach Provider-Default wählen
        $this->voice = $userSettings['tts_voice'] ?? match ($this->provider) {
            'openai_tts' => self::DEFAULT_VOICE_OPENAI,
            'google_tts' => self::DEFAULT_VOICE_GOOGLE,
            default      => 'de',
        };
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Extrahiert den BCP-47-Sprachcode aus einem Google-Stimmennamen.
     * z.B. "de-DE-Wavenet-F" → "de-DE"
     */
    private function extractGoogleLangCode(string $voiceName): string
    {
        if (preg_match('/^([a-z]{2}-[A-Z]{2})/', $voiceName, $m)) {
            return $m[1];
        }
        return self::DEFAULT_LANG_GOOGLE;
    }
}
