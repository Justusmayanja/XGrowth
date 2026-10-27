<?php

namespace App\Services\YouTube;

/**
 * Three different problems, one of them named. Stored for the model.
 * Average view percent is not an input. The opening changes only when the
 * loss is in the first 30 seconds and that loss is at least 25%.
 */
class YouTubeRetentionReader
{
    /**
     * @param  list<array{0: float, 1: float, 2: string}>|null  $cues  start seconds, end seconds, text
     * @return array{case: string, change_opening: bool, hook_leak: float|null, cliff: array{at_seconds: float, lost: float, said: string|null}|null, slide: float|null, note: string}
     */
    public function read(string $csv, ?float $duration = null, ?array $cues = null): array
    {
        $rows = $this->rows($csv);
        if (count($rows) < 8) {
            return $this->pack('unreadable', false, null, null, null, 'The export did not have enough points to name a leak.');
        }

        $maxX = max(array_column($rows, 0));
        $percentAxis = $maxX <= 100.5;
        $start = $rows[0][1] ?: 100.0;
        $cutoff = $percentAxis
            ? ($duration && $duration > 0 ? 30.0 / $duration * 100 : 10.0)
            : 30.0;

        $hookEnd = $start;
        foreach ($rows as [$x, $y]) {
            if ($x <= $cutoff && $y < $hookEnd) {
                $hookEnd = $y;
            }
        }
        $hookLeak = $start - $hookEnd;

        $drops = [];
        for ($i = 1; $i < count($rows); $i++) {
            $lost = $rows[$i - 1][1] - $rows[$i][1];
            if ($lost <= 0) {
                continue;
            }
            $drops[] = [
                'lost' => $lost,
                'from' => $rows[$i - 1][0],
                'at' => $this->seconds($rows[$i - 1][0], $percentAxis, $duration),
            ];
        }
        usort($drops, fn (array $a, array $b) => $b['lost'] <=> $a['lost']);

        $cliff = null;
        foreach ($drops as $drop) {
            if ($drop['lost'] < 8) {
                break;
            }
            $cliff = $drop;
            break;
        }

        $middle = 0.0;
        foreach ($drops as $drop) {
            if ($drop['from'] > $cutoff) {
                $middle += $drop['lost'];
            }
        }

        if ($hookLeak >= 25) {
            return $this->pack(
                'hook_leak',
                true,
                round($hookLeak, 2),
                null,
                round($middle, 2),
                'Hook leak. Under 25% lost in the first 30 seconds is healthy. Fix the first fifteen seconds of the script.',
            );
        }

        if ($cliff !== null) {
            $said = $this->said($cliff['at'], $cues);

            return $this->pack(
                'cliff',
                false,
                round($hookLeak, 2),
                [
                    'at_seconds' => $cliff['at'],
                    'lost' => round($cliff['lost'], 2),
                    'said' => $said,
                ],
                round($middle, 2),
                $said
                    ? 'Cliff. People left at '.$cliff['at'].'s while this was being said: '.$said
                    : 'Cliff. People left at '.$cliff['at'].'s. Keep the opening.',
            );
        }

        if ($middle >= 15) {
            return $this->pack(
                'slide',
                false,
                round($hookLeak, 2),
                null,
                round($middle, 2),
                'Slide. The middle bleeds steadily. Cut it. Do not rewrite the opening.',
            );
        }

        return $this->pack(
            'packaging',
            false,
            round($hookLeak, 2),
            null,
            round($middle, 2),
            'The opening held and the middle is flat. The problem is the title and thumbnail.',
        );
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    public function rows(string $csv): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', $csv) ?: [] as $line) {
            $values = [];
            foreach (str_getcsv($line) as $cell) {
                $cell = str_replace([',', '%'], '', trim((string) $cell));
                if ($cell !== '' && is_numeric($cell)) {
                    $values[] = (float) $cell;
                }
            }
            if (count($values) >= 2) {
                $rows[] = [$values[0], $values[1]];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{0: float, 1: float, 2: string}>|null  $cues
     */
    private function said(?float $at, ?array $cues): ?string
    {
        if ($at === null || $cues === null) {
            return null;
        }
        $near = [];
        foreach ($cues as $cue) {
            if ($cue[0] <= $at + 4 && $cue[1] >= $at - 4) {
                $near[] = $cue[2];
            }
        }
        $text = trim(implode(' ', $near));

        return $text === '' ? null : mb_substr($text, 0, 140);
    }

    private function seconds(float $x, bool $percentAxis, ?float $duration): ?float
    {
        if (! $percentAxis) {
            return round($x, 1);
        }
        if ($duration && $duration > 0) {
            return round($x / 100 * $duration, 1);
        }

        return null;
    }

    /**
     * @param  array{at_seconds: float|null, lost: float, said: string|null}|null  $cliff
     * @return array{case: string, change_opening: bool, hook_leak: float|null, cliff: array{at_seconds: float|null, lost: float, said: string|null}|null, slide: float|null, note: string}
     */
    private function pack(string $case, bool $changeOpening, ?float $hookLeak, ?array $cliff, ?float $slide, string $note): array
    {
        return [
            'case' => $case,
            'change_opening' => $changeOpening,
            'hook_leak' => $hookLeak,
            'cliff' => $cliff,
            'slide' => $slide,
            'note' => $note,
        ];
    }
}
