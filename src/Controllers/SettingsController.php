<?php

namespace App\Controllers;

use App\Helpers\Auth;
use App\Services\EncryptionService;

/**
 * Admin-Einstellungen — KI-Backend + TTS pro Familie (Admin-User)
 *
 * GET  /admin/settings   → show()
 * POST /admin/settings   → save()
 */
class SettingsController
{
    public static function show(): void
    {
        Auth::requireRole('admin', 'superadmin');
        $adminId = (int)$_SESSION['user_id'];

        $settings = [];
        try {
            $enc      = EncryptionService::make();
            $settings = $enc->loadUserSettings($adminId);
        } catch (\Throwable) {}

        $flash = $_SESSION['settings_flash'] ?? null;
        unset($_SESSION['settings_flash']);

        require __DIR__ . '/../Views/admin/settings.php';
    }

    public static function save(): void
    {
        Auth::requireRole('admin', 'superadmin');
        Auth::verifyCsrf();
        $adminId = (int)$_SESSION['user_id'];

        $aiProvider  = trim($_POST['ai_provider']  ?? '');
        $aiApiKey    = trim($_POST['ai_api_key']   ?? '');
        $ttsProvider = trim($_POST['tts_provider'] ?? '');
        $ttsApiKey   = trim($_POST['tts_api_key']  ?? '');

        // Validate enums
        if (!in_array($aiProvider,  ['claude','openai','gemini']))            $aiProvider  = 'claude';
        if (!in_array($ttsProvider, ['openai_tts','google_tts','browser']))   $ttsProvider = 'browser';

        $enc = EncryptionService::make();

        $enc->saveSetting($adminId, 'ai_provider',  $aiProvider);
        $enc->saveSetting($adminId, 'tts_provider', $ttsProvider);

        // Keys nur überschreiben wenn neu eingegeben (leeres Feld = unveränderter Key)
        if ($aiApiKey !== '') {
            $enc->saveSetting($adminId, 'ai_api_key', $aiApiKey);
        }
        if ($ttsProvider !== 'browser' && $ttsApiKey !== '') {
            $enc->saveSetting($adminId, 'tts_api_key', $ttsApiKey);
        }
        // Browser-TTS braucht keinen Key — vorhandenen Key entfernen
        if ($ttsProvider === 'browser') {
            db()->prepare(
                "DELETE FROM settings WHERE user_id=? AND key='tts_api_key'"
            )->execute([$adminId]);
        }

        $_SESSION['settings_flash'] = [
            'type'    => 'success',
            'message' => '✅ Einstellungen gespeichert.',
        ];
        redirect('/admin/settings');
    }
}
