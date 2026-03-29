<?php

namespace App\Services;

/**
 * AES-256-GCM Verschlüsselung für API-Keys und Einstellungen in der DB.
 *
 * Schlüssel: APP_ENCRYPTION_KEY aus .env (min. 16 Zeichen, wird intern
 * via SHA-256 auf exakt 32 Byte gebracht).
 *
 * Format gespeicherter Werte: base64( iv[12] + tag[16] + ciphertext )
 *
 * Bereits vollständig implementiert — diese Revision ergänzt nur
 * die Hilfsmethoden make() und decryptOrNull().
 */
class EncryptionService
{
    private const CIPHER  = 'aes-256-gcm';
    private const TAG_LEN = 16;

    private string $key;

    public function __construct()
    {
        $raw = APP_ENCRYPTION_KEY;
        if (strlen($raw) < 16) {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY fehlt oder zu kurz (min. 16 Zeichen). '
                . 'Bitte .env nach .env.example einrichten.'
            );
        }
        $this->key = substr(hash('sha256', $raw, true), 0, 32);
    }

    /**
     * Factory — verhindert wiederholtes `new EncryptionService()` im Code.
     */
    public static function make(): self
    {
        return new self();
    }

    // ── Kernmethoden ──────────────────────────────────────────────────

    /**
     * Verschlüsselt einen Plaintext-String.
     * Gibt base64-kodierten Blob zurück (iv + tag + ciphertext).
     */
    public function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(12);   // 96-Bit IV für GCM
        $tag = '';
        $ct  = openssl_encrypt(
            $plaintext, self::CIPHER, $this->key,
            OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN
        );

        if ($ct === false) {
            throw new \RuntimeException('Verschlüsselung fehlgeschlagen (openssl).');
        }

        return base64_encode($iv . $tag . $ct);
    }

    /**
     * Entschlüsselt einen verschlüsselten Blob.
     *
     * @throws \RuntimeException bei beschädigten Daten oder falschem Schlüssel.
     */
    public function decrypt(string $encoded): string
    {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < 28) {
            throw new \RuntimeException('Ungültige verschlüsselte Daten (zu kurz oder kein Base64).');
        }

        $iv  = substr($data, 0,  12);
        $tag = substr($data, 12, 16);
        $ct  = substr($data, 28);

        $pt = openssl_decrypt(
            $ct, self::CIPHER, $this->key,
            OPENSSL_RAW_DATA, $iv, $tag
        );

        if ($pt === false) {
            throw new \RuntimeException(
                'Entschlüsselung fehlgeschlagen — falscher Schlüssel oder Daten beschädigt.'
            );
        }

        return $pt;
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Entschlüsselt oder gibt null zurück wenn der Wert leer ist.
     * Praktisch für optionale Felder (z.B. tts_api_key).
     */
    public function decryptOrNull(string $encoded): ?string
    {
        if ($encoded === '') {
            return null;
        }
        return $this->decrypt($encoded);
    }

    /**
     * Lädt alle Settings eines Users als entschlüsseltes Key-Value-Array.
     *
     * @return array<string, string>
     */
    public function loadUserSettings(int $userId): array
    {
        $stmt = db()->prepare(
            'SELECT key, value_encrypted FROM settings WHERE user_id = ?'
        );
        $stmt->execute([$userId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            try {
                $result[$row['key']] = $this->decrypt($row['value_encrypted']);
            } catch (\Throwable) {
                // Korrumpiertes Setting überspringen — verhindert kompletten Absturz
            }
        }
        return $result;
    }

    /**
     * Speichert oder aktualisiert ein verschlüsseltes Setting.
     */
    public function saveSetting(int $userId, string $key, string $value): void
    {
        db()->prepare(
            'INSERT OR REPLACE INTO settings (user_id, key, value_encrypted, updated_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
        )->execute([$userId, $key, $this->encrypt($value)]);
    }
}
