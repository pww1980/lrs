<?php

namespace App\Services;

/**
 * AES-256-GCM Verschlüsselung für API-Keys in der Datenbank.
 * Schlüssel kommt aus APP_ENCRYPTION_KEY in der .env.
 */
class EncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LEN = 16;

    private string $key;

    public function __construct()
    {
        $raw = APP_ENCRYPTION_KEY;
        if (strlen($raw) < 16) {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY fehlt oder zu kurz (min. 16 Zeichen). Bitte .env prüfen.'
            );
        }
        // Schlüssel auf genau 32 Byte bringen
        $this->key = substr(hash('sha256', $raw, true), 0, 32);
    }

    public function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(12); // 96-Bit IV für GCM
        $tag = '';
        $ct  = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($ct === false) {
            throw new \RuntimeException('Verschlüsselung fehlgeschlagen.');
        }
        // iv (12) + tag (16) + ciphertext → base64
        return base64_encode($iv . $tag . $ct);
    }

    public function decrypt(string $encoded): string
    {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < 28) {
            throw new \RuntimeException('Ungültige verschlüsselte Daten.');
        }
        $iv  = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $ct  = substr($data, 28);
        $pt  = openssl_decrypt($ct, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($pt === false) {
            throw new \RuntimeException('Entschlüsselung fehlgeschlagen (falscher Schlüssel?).');
        }
        return $pt;
    }
}
