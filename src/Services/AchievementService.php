<?php

namespace App\Services;

/**
 * AchievementService — prüft Trigger-Bedingungen und verleiht Achievements.
 *
 * Wird nach jeder abgeschlossenen Session aufgerufen.
 * Gibt neu verliehene Achievements zurück (für UI-Anzeige).
 */
class AchievementService
{
    /**
     * Prüft alle Trigger nach Abschluss einer Session.
     *
     * @param  int  $userId
     * @param  int  $sessionId
     * @return array  Neu verliehene Achievement-Definitionen [{code, title, icon, description}]
     */
    public static function checkAfterSession(int $userId, int $sessionId): array
    {
        self::ensureDefinitionsSeeded();

        $newlyUnlocked = [];

        // ── Daten sammeln ─────────────────────────────────────────────────
        $totSess  = self::countCompletedSessions($userId);
        $totWords = self::countCorrectWords($userId);
        $streak   = self::calcStreak($userId);
        $masteredBlocks = self::getMasteredBlocks($userId);

        // ── Trigger-Checks ────────────────────────────────────────────────

        // sessions_completed
        $newlyUnlocked = array_merge($newlyUnlocked,
            self::checkThreshold($userId, 'sessions_completed', $totSess));

        // words_correct
        $newlyUnlocked = array_merge($newlyUnlocked,
            self::checkThreshold($userId, 'words_correct', $totWords));

        // streak_days
        $newlyUnlocked = array_merge($newlyUnlocked,
            self::checkThreshold($userId, 'streak_days', $streak));

        // block_mastered — je Block prüfen
        foreach ($masteredBlocks as $block) {
            $newlyUnlocked = array_merge($newlyUnlocked,
                self::checkBlockMastered($userId, $block));
        }

        // ALL blocks mastered?
        if (count($masteredBlocks) >= 4) {
            $newlyUnlocked = array_merge($newlyUnlocked,
                self::checkBlockMastered($userId, 'ALL'));
        }

        // Secret: same_word_wrong (Creeper-Freund)
        $newlyUnlocked = array_merge($newlyUnlocked,
            self::checkSameWordWrong($userId));

        // Secret: correct_streak_first_try (Adlerauge) — 20 in a row
        $newlyUnlocked = array_merge($newlyUnlocked,
            self::checkFirstTryStreak($userId));

        // Secret: tts_slow_count (Langsam aber sicher)
        $ttsSlowTotal = self::countTtsSlow($userId);
        $newlyUnlocked = array_merge($newlyUnlocked,
            self::checkThreshold($userId, 'tts_slow_count', $ttsSlowTotal));

        // Freischaltungen für neue Achievements verarbeiten
        foreach ($newlyUnlocked as $ach) {
            self::processUnlocks($userId, $ach);
        }

        return $newlyUnlocked;
    }

    // ── Interne Prüfmethoden ──────────────────────────────────────────────

    /**
     * Prüft Achievements mit einfachem Schwellwert-Trigger.
     */
    private static function checkThreshold(int $userId, string $triggerType, int $value): array
    {
        $defs = self::getDefinitions($triggerType);
        $unlocked = [];
        foreach ($defs as $def) {
            if ((int)$def['trigger_value'] <= $value) {
                $result = self::tryAward($userId, (int)$def['id'], $def);
                if ($result) $unlocked[] = $result;
            }
        }
        return $unlocked;
    }

    /**
     * Prüft block_mastered Achievements.
     */
    private static function checkBlockMastered(int $userId, string $block): array
    {
        $defs = self::getDefinitions('block_mastered');
        $unlocked = [];
        foreach ($defs as $def) {
            if ($def['trigger_value'] === $block || (int)$def['trigger_value'] === 0) {
                // trigger_value für block_mastered ist der Block-Buchstabe im code (A/B/C/D/ALL)
                // Wir matchen über achievement code
                if ($def['code'] === 'block_' . strtolower($block) . '_done'
                    || $def['code'] === 'all_blocks') {
                    $result = self::tryAward($userId, (int)$def['id'], $def);
                    if ($result) $unlocked[] = $result;
                }
            }
        }
        return $unlocked;
    }

    /**
     * Secret: ein Wort 10x falsch gemacht.
     */
    private static function checkSameWordWrong(int $userId): array
    {
        $stmt = db()->prepare("
            SELECT si.word_id, COUNT(*) AS c
            FROM session_attempts sa
            JOIN session_items si  ON sa.item_id   = si.id
            JOIN sessions sess     ON si.session_id = sess.id
            WHERE sess.user_id = ? AND sa.is_correct = 0 AND si.word_id IS NOT NULL
            GROUP BY si.word_id
            HAVING c >= 10
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) return [];

        $def = self::getDefinitionByCode('creeper_friend');
        if (!$def) return [];
        $result = self::tryAward($userId, (int)$def['id'], $def);
        return $result ? [$result] : [];
    }

    /**
     * Secret: 20 korrekte Antworten beim ersten Versuch in Folge.
     */
    private static function checkFirstTryStreak(int $userId): array
    {
        // Letzte N session_items (nur abgeschlossen)
        $stmt = db()->prepare("
            SELECT si.final_correct, si.second_try_allowed
            FROM session_items si
            JOIN sessions sess ON si.session_id = sess.id
            WHERE sess.user_id = ? AND si.final_correct IS NOT NULL
            ORDER BY sess.completed_at DESC, si.order_index DESC
            LIMIT 30
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        $streak = 0;
        foreach ($rows as $row) {
            // Korrekt beim ersten Versuch = final_correct=1 UND second_try_allowed=0
            if ((int)$row['final_correct'] === 1 && (int)$row['second_try_allowed'] === 0) {
                $streak++;
            } else {
                break;
            }
        }

        if ($streak < 20) return [];
        $def = self::getDefinitionByCode('eagle_eye');
        if (!$def) return [];
        $result = self::tryAward($userId, (int)$def['id'], $def);
        return $result ? [$result] : [];
    }

    /**
     * Versucht, ein Achievement zu verleihen (idempotent).
     * Gibt die Definition zurück wenn neu verliehen, null wenn schon vorhanden.
     */
    private static function tryAward(int $userId, int $achId, array $def): ?array
    {
        // Schon vorhanden?
        $stmt = db()->prepare(
            "SELECT id FROM user_achievements WHERE user_id=? AND achievement_id=?"
        );
        $stmt->execute([$userId, $achId]);
        if ($stmt->fetch()) return null;

        // Verleihen
        db()->prepare(
            "INSERT INTO user_achievements (user_id, achievement_id, seen_by_user)
             VALUES (?, ?, 0)"
        )->execute([$userId, $achId]);

        return [
            'id'          => $achId,
            'code'        => $def['code'],
            'title'       => $def['title'],
            'icon'        => $def['icon'],
            'description' => $def['description'],
            'is_secret'   => (bool)$def['is_secret'],
        ];
    }

    /**
     * Verarbeitet Freischaltungen die ein Achievement auslöst (Theme, Feature).
     */
    private static function processUnlocks(int $userId, array $ach): void
    {
        // Achievement-Definition nochmal laden für unlocks_theme / unlocks_feature
        $stmt = db()->prepare(
            "SELECT unlocks_theme, unlocks_feature FROM achievement_definitions WHERE id=?"
        );
        $stmt->execute([$ach['id']]);
        $def = $stmt->fetch();
        if (!$def) return;

        if ($def['unlocks_theme']) {
            db()->prepare(
                "INSERT OR IGNORE INTO unlocked_content
                 (user_id, content_type, content_key, unlocked_by)
                 VALUES (?, 'theme', ?, ?)"
            )->execute([$userId, $def['unlocks_theme'], $ach['id']]);
        }
        if ($def['unlocks_feature']) {
            db()->prepare(
                "INSERT OR IGNORE INTO unlocked_content
                 (user_id, content_type, content_key, unlocked_by)
                 VALUES (?, 'feature', ?, ?)"
            )->execute([$userId, $def['unlocks_feature'], $ach['id']]);
        }
    }

    // ── Statistik-Hilfsmethoden ───────────────────────────────────────────

    private static function countCompletedSessions(int $userId): int
    {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM sessions WHERE user_id=? AND status='completed'"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    private static function countCorrectWords(int $userId): int
    {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM session_items si
            JOIN sessions sess ON si.session_id = sess.id
            WHERE sess.user_id = ? AND si.final_correct = 1
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    private static function calcStreak(int $userId): int
    {
        $stmt = db()->prepare("
            SELECT DISTINCT date(completed_at) AS d
            FROM sessions WHERE user_id=? AND status='completed'
            ORDER BY d DESC LIMIT 60
        ");
        $stmt->execute([$userId]);
        $dates = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($dates)) return 0;

        $streak = 0;
        $today  = new \DateTimeImmutable('today');
        foreach ($dates as $i => $d) {
            $expected = $today->modify("-{$i} days")->format('Y-m-d');
            if ($d === $expected) $streak++;
            else break;
        }
        return $streak;
    }

    /**
     * Gibt Blöcke zurück die gemeistert sind (alle Quests completed).
     */
    private static function getMasteredBlocks(int $userId): array
    {
        $stmt = db()->prepare("
            SELECT DISTINCT pb.block
            FROM plan_biomes pb
            JOIN learning_plans lp ON pb.plan_id = lp.id
            WHERE lp.user_id = ? AND pb.status = 'completed'
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private static function countTtsSlow(int $userId): int
    {
        $stmt = db()->prepare("
            SELECT COALESCE(SUM(si.tts_slow_replays), 0)
            FROM session_items si
            JOIN sessions sess ON si.session_id = sess.id
            WHERE sess.user_id = ?
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    // ── Definition-Helpers ────────────────────────────────────────────────

    private static function getDefinitions(string $triggerType): array
    {
        $stmt = db()->prepare(
            "SELECT * FROM achievement_definitions WHERE trigger_type=? AND active=1"
        );
        $stmt->execute([$triggerType]);
        return $stmt->fetchAll();
    }

    private static function getDefinitionByCode(string $code): ?array
    {
        $stmt = db()->prepare(
            "SELECT * FROM achievement_definitions WHERE code=? AND active=1"
        );
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    // ── Seed ─────────────────────────────────────────────────────────────

    /**
     * Stellt sicher dass Achievement-Definitionen in der DB vorhanden sind.
     * Idempotent — läuft nur wenn Tabelle leer ist.
     */
    public static function ensureDefinitionsSeeded(): void
    {
        $count = (int)db()->query(
            "SELECT COUNT(*) FROM achievement_definitions"
        )->fetchColumn();

        if ($count > 0) return;

        $seeds = self::getSeedData();
        $stmt  = db()->prepare("
            INSERT INTO achievement_definitions
              (code, title, description, icon, category, trigger_type,
               trigger_value, unlocks_theme, unlocks_feature, is_secret, active)
            VALUES (?,?,?,?,?,?,?,?,?,?,1)
        ");

        db()->beginTransaction();
        foreach ($seeds as $s) {
            $stmt->execute([
                $s['code'],
                $s['title'],
                $s['description'],
                $s['icon'],
                $s['category'],
                $s['trigger_type'],
                $s['trigger_value'],
                $s['unlocks_theme']   ?? null,
                $s['unlocks_feature'] ?? null,
                $s['is_secret']       ?? 0,
            ]);
        }
        db()->commit();
    }

    private static function getSeedData(): array
    {
        return [
            // ── Lern-Achievements ─────────────────────────────────────────
            [
                'code'         => 'first_session',
                'title'        => 'Holzaxt',
                'description'  => 'Du hast dein erstes Abenteuer begonnen!',
                'icon'         => '🪓',
                'category'     => 'learning',
                'trigger_type' => 'sessions_completed',
                'trigger_value'=> 1,
            ],
            [
                'code'         => 'words_10',
                'title'        => 'Holzschwert',
                'description'  => '10 Wörter gemeistert — du wirst stärker!',
                'icon'         => '⚔️',
                'category'     => 'learning',
                'trigger_type' => 'words_correct',
                'trigger_value'=> 10,
            ],
            [
                'code'         => 'words_50',
                'title'        => 'Steinschwert',
                'description'  => '50 Wörter richtig — ein echter Kämpfer!',
                'icon'         => '⚔️',
                'category'     => 'learning',
                'trigger_type' => 'words_correct',
                'trigger_value'=> 50,
            ],
            [
                'code'         => 'quests_3',
                'title'        => 'Zaubertrank',
                'description'  => '3 Quests erledigt — die Magie wirkt!',
                'icon'         => '🔮',
                'category'     => 'learning',
                'trigger_type' => 'quests_completed',
                'trigger_value'=> 3,
            ],
            [
                'code'          => 'block_a_done',
                'title'         => 'Lederrüstung',
                'description'   => 'Block A abgeschlossen — der Wald gehört dir!',
                'icon'          => '🛡️',
                'category'      => 'learning',
                'trigger_type'  => 'block_mastered',
                'trigger_value' => 'A',
            ],
            [
                'code'           => 'block_b_done',
                'title'          => 'Eisenschwert',
                'description'    => 'Block B gemeistert — die Wüste wartet!',
                'icon'           => '⚔️',
                'category'       => 'learning',
                'trigger_type'   => 'block_mastered',
                'trigger_value'  => 'B',
                'unlocks_theme'  => 'space',
            ],
            [
                'code'           => 'block_c_done',
                'title'          => 'Diamantschwert',
                'description'    => 'Block C gemeistert — du bist fast unbesiegbar!',
                'icon'           => '💎',
                'category'       => 'learning',
                'trigger_type'   => 'block_mastered',
                'trigger_value'  => 'C',
                'unlocks_theme'  => 'ocean',
            ],
            [
                'code'           => 'all_blocks',
                'title'          => 'Nether-Stern',
                'description'    => 'Alle Blöcke gemeistert — Legende!',
                'icon'           => '🌟',
                'category'       => 'learning',
                'trigger_type'   => 'block_mastered',
                'trigger_value'  => 'ALL',
                'unlocks_theme'  => 'dark',
            ],

            // ── Streak-Achievements ───────────────────────────────────────
            [
                'code'         => 'streak_3',
                'title'        => 'Funken',
                'description'  => '3 Tage in Folge geübt — das Feuer brennt!',
                'icon'         => '🔥',
                'category'     => 'streak',
                'trigger_type' => 'streak_days',
                'trigger_value'=> 3,
            ],
            [
                'code'         => 'streak_7',
                'title'        => 'Fackel',
                'description'  => '7 Tage am Stück — nichts kann dich aufhalten!',
                'icon'         => '🔥',
                'category'     => 'streak',
                'trigger_type' => 'streak_days',
                'trigger_value'=> 7,
            ],
            [
                'code'            => 'streak_14',
                'title'           => 'Lagerfeuer',
                'description'     => '14 Tage — du bist ein echter Überlebenskünstler!',
                'icon'            => '🔥',
                'category'        => 'streak',
                'trigger_type'    => 'streak_days',
                'trigger_value'   => 14,
                'unlocks_feature' => 'mini_diktat_mode',
            ],
            [
                'code'            => 'streak_30',
                'title'           => 'Netherportal',
                'description'     => '30 Tage Streak — du hast das Netherportal geöffnet!',
                'icon'            => '🔥',
                'category'        => 'streak',
                'trigger_type'    => 'streak_days',
                'trigger_value'   => 30,
                'unlocks_theme'   => 'nether',
            ],

            // ── Secret Achievements ───────────────────────────────────────
            [
                'code'         => 'creeper_friend',
                'title'        => 'Creeper-Freund',
                'description'  => 'Ein Wort macht dir Probleme — aber du gibst nicht auf!',
                'icon'         => '🐛',
                'category'     => 'special',
                'trigger_type' => 'same_word_wrong',
                'trigger_value'=> 10,
                'is_secret'    => 1,
            ],
            [
                'code'         => 'eagle_eye',
                'title'        => 'Adlerauge',
                'description'  => '20 Wörter in Folge beim ersten Versuch — unglaublich!',
                'icon'         => '🦅',
                'category'     => 'special',
                'trigger_type' => 'correct_streak_first_try',
                'trigger_value'=> 20,
                'is_secret'    => 1,
            ],
            [
                'code'         => 'slow_learner',
                'title'        => 'Langsam aber sicher',
                'description'  => 'Du hörst genau hin — das ist deine Stärke!',
                'icon'         => '🐢',
                'category'     => 'special',
                'trigger_type' => 'tts_slow_count',
                'trigger_value'=> 50,
                'is_secret'    => 1,
            ],
        ];
    }
}
