<?php

namespace App\Services;

/**
 * ProgressTestService — prüft ob ein Fortschrittstest fällig ist.
 *
 * Standard-Intervall: 42 Tage (6 Wochen), konfigurierbar per Kind-Settings
 * (`progress_test_interval` in Tagen).
 */
class ProgressTestService
{
    public const DEFAULT_INTERVAL_DAYS = 42;

    /**
     * Ist ein Fortschrittstest fällig?
     *
     * @return array{due: bool, days_overdue: int, last_test_date: string|null, interval_days: int}
     */
    public static function isDue(int $userId): array
    {
        $intervalDays = self::getInterval($userId);

        // Letzten abgeschlossenen Test laden (initial oder progress)
        $stmt = db()->prepare(
            "SELECT completed_at FROM tests
             WHERE user_id = ? AND status = 'completed'
             ORDER BY completed_at DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $lastRow = $stmt->fetch();

        if (!$lastRow || !$lastRow['completed_at']) {
            // Kein Test bisher → kein Fortschrittstest fällig (erst Einstufungstest)
            return [
                'due'            => false,
                'days_overdue'   => 0,
                'last_test_date' => null,
                'interval_days'  => $intervalDays,
            ];
        }

        $lastDate  = new \DateTimeImmutable($lastRow['completed_at']);
        $now       = new \DateTimeImmutable();
        $daysSince = (int)$now->diff($lastDate)->days;
        $daysOverdue = $daysSince - $intervalDays;

        return [
            'due'            => $daysOverdue >= 0,
            'days_overdue'   => max(0, $daysOverdue),
            'last_test_date' => $lastDate->format('Y-m-d'),
            'interval_days'  => $intervalDays,
        ];
    }

    /**
     * Hat das Kind bereits mindestens einen abgeschlossenen Einstufungstest?
     */
    public static function hasInitialTest(int $userId): bool
    {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM tests WHERE user_id=? AND type='initial' AND status='completed'"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Fortschrittstest-Intervall des Kindes (Tage).
     */
    public static function getInterval(int $userId): int
    {
        $stmt = db()->prepare(
            "SELECT value_encrypted FROM settings WHERE user_id=? AND key='progress_test_interval'"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) return self::DEFAULT_INTERVAL_DAYS;

        // Wert ist als Klartext gespeichert wenn es eine Zahl ist (nicht-sensitiv)
        $val = $row['value_encrypted'];
        $num = (int)$val;
        return ($num >= 7 && $num <= 365) ? $num : self::DEFAULT_INTERVAL_DAYS;
    }

    /**
     * Letzten abgeschlossenen Test laden.
     */
    public static function getLastCompletedTest(int $userId, string $type = ''): ?array
    {
        $typeClause = $type ? "AND type='{$type}'" : '';
        $stmt = db()->prepare(
            "SELECT * FROM tests
             WHERE user_id=? AND status='completed' {$typeClause}
             ORDER BY completed_at DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Vergleich zweier Tests: Delta der Fehlerraten pro Kategorie.
     * Gibt pro Kategorie zurück: {category, old_rate, new_rate, delta, improved}
     */
    public static function compareTests(int $oldTestId, int $newTestId): array
    {
        $old = self::loadTestResults($oldTestId);
        $new = self::loadTestResults($newTestId);

        $all  = array_unique(array_merge(array_keys($old), array_keys($new)));
        $diff = [];

        foreach ($all as $cat) {
            $oldRate = $old[$cat]['error_rate'] ?? null;
            $newRate = $new[$cat]['error_rate'] ?? null;

            $delta    = ($oldRate !== null && $newRate !== null)
                        ? round($newRate - $oldRate, 3)
                        : null;

            $diff[$cat] = [
                'category'     => $cat,
                'old_rate'     => $oldRate,
                'new_rate'     => $newRate,
                'delta'        => $delta,
                'improved'     => $delta !== null && $delta < 0,
                'old_severity' => $old[$cat]['severity'] ?? null,
                'new_severity' => $new[$cat]['severity'] ?? null,
            ];
        }

        // Sortierung: nach Kategorie
        ksort($diff);
        return array_values($diff);
    }

    private static function loadTestResults(int $testId): array
    {
        $stmt = db()->prepare(
            "SELECT category, error_rate, severity, strategy_level
             FROM test_results WHERE test_id=?"
        );
        $stmt->execute([$testId]);
        $rows = $stmt->fetchAll();

        $map = [];
        foreach ($rows as $r) {
            $map[$r['category']] = $r;
        }
        return $map;
    }
}
