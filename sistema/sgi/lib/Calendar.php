<?php
declare(strict_types=1);

class Calendar
{
    /** Expande eventos recorrentes num intervalo. @return array<int,array<string,mixed>> */
    public static function expand(array $events, string $from, string $to): array
    {
        $out = [];
        $steps = ['mensal' => 1, 'trimestral' => 3, 'semestral' => 6, 'anual' => 12];
        foreach ($events as $e) {
            $start = new DateTime((string)$e['start_date']);
            $len = $e['end_date'] ? (int)$start->diff(new DateTime((string)$e['end_date']))->days : 0;
            $step = $steps[$e['recurrence']] ?? 0;
            $guard = 0;
            $d = clone $start;
            while ($d->format('Y-m-d') <= $to && $guard++ < 400) {
                $end = (clone $d)->modify("+$len day");
                if ($end->format('Y-m-d') >= $from) {
                    $out[] = $e + ['occurrence_date' => $d->format('Y-m-d'), 'occurrence_end' => $end->format('Y-m-d')];
                }
                if (!$step) {
                    break;
                }
                $d = (clone $start)->modify('+' . ($step * $guard) . ' month');
            }
        }
        usort($out, function ($a, $b) {
            return strcmp($a['occurrence_date'], $b['occurrence_date']);
        });
        return $out;
    }
}
