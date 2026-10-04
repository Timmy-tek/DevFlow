<?php

namespace App\Support;

class TextDiff
{
    private const EXTENSIONS = [
        'txt',
        'md',
        'markdown',
        'json',
        'yml',
        'yaml',
        'xml',
        'csv',
        'tsv',
        'html',
        'htm',
        'css',
        'scss',
        'js',
        'mjs',
        'ts',
        'tsx',
        'jsx',
        'vue',
        'php',
        'blade',
        'py',
        'rb',
        'go',
        'rs',
        'java',
        'kt',
        'swift',
        'c',
        'h',
        'cpp',
        'cs',
        'sh',
        'sql',
        'env',
        'ini',
        'toml',
        'log',
        'svg',
    ];

    public static function looksLikeText(string $name, ?string $mime): bool
    {
        if ($mime !== null && str_starts_with($mime, 'text/')) {
            return true;
        }

        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * Line diff with unchanged stretches collapsed to "gap" rows.
     * Self-contained (no library): trims the shared start and end, then runs an
     * LCS on whatever is left. Returns null when the changed region is too big.
     *
     * @return array{rows: array<int, array{type: string, old: ?int, new: ?int, text: string}>, added: int, removed: int}|null
     */
    public static function compare(string $old, string $new, int $context = 3): ?array
    {
        $a = self::lines($old);
        $b = self::lines($new);

        if (count($a) > 5000 || count($b) > 5000) {
            return null;
        }

        // Trim the shared head and tail.
        $start = 0;
        $endA = count($a);
        $endB = count($b);

        while ($start < $endA && $start < $endB && $a[$start] === $b[$start]) {
            $start++;
        }

        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            $endA--;
            $endB--;
        }

        $midA = array_slice($a, $start, $endA - $start);
        $midB = array_slice($b, $start, $endB - $start);
        $n = count($midA);
        $m = count($midB);

        if ($n * $m > 1_000_000) {
            return null;
        }

        // LCS lengths, filled from the bottom right.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $midA[$i] === $midB[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $ops = [];

        for ($k = 0; $k < $start; $k++) {
            $ops[] = ['ctx', $a[$k]];
        }

        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($midA[$i] === $midB[$j]) {
                $ops[] = ['ctx', $midA[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $ops[] = ['del', $midA[$i]];
                $i++;
            } else {
                $ops[] = ['add', $midB[$j]];
                $j++;
            }
        }

        for (; $i < $n; $i++) {
            $ops[] = ['del', $midA[$i]];
        }

        for (; $j < $m; $j++) {
            $ops[] = ['add', $midB[$j]];
        }

        for ($k = $endA; $k < count($a); $k++) {
            $ops[] = ['ctx', $a[$k]];
        }

        // Number the lines.
        $rows = [];
        $oldNo = 0;
        $newNo = 0;
        $added = 0;
        $removed = 0;

        foreach ($ops as [$type, $text]) {
            if ($type === 'add') {
                $newNo++;
                $added++;
                $rows[] = ['type' => 'add', 'old' => null, 'new' => $newNo, 'text' => $text];
            } elseif ($type === 'del') {
                $oldNo++;
                $removed++;
                $rows[] = ['type' => 'del', 'old' => $oldNo, 'new' => null, 'text' => $text];
            } else {
                $oldNo++;
                $newNo++;
                $rows[] = ['type' => 'ctx', 'old' => $oldNo, 'new' => $newNo, 'text' => $text];
            }
        }

        // Keep changed rows plus $context lines around them.
        $total = count($rows);
        $keep = array_fill(0, $total, false);

        foreach ($rows as $idx => $row) {
            if ($row['type'] === 'ctx') {
                continue;
            }

            for ($x = max(0, $idx - $context); $x <= min($total - 1, $idx + $context); $x++) {
                $keep[$x] = true;
            }
        }

        $collapsed = [];
        $skipped = 0;

        foreach ($rows as $idx => $row) {
            if ($keep[$idx]) {
                if ($skipped > 0) {
                    $collapsed[] = self::gap($skipped);
                    $skipped = 0;
                }

                $collapsed[] = $row;
            } else {
                $skipped++;
            }
        }

        if ($skipped > 0 && $collapsed !== []) {
            $collapsed[] = self::gap($skipped);
        }

        return ['rows' => $collapsed, 'added' => $added, 'removed' => $removed];
    }

    /** @return array<int, string> */
    private static function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\n|\r/', $text);

        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    private static function gap(int $count): array
    {
        return ['type' => 'gap', 'old' => null, 'new' => null, 'text' => $count . ' unchanged ' . ($count === 1 ? 'line' : 'lines')];
    }
}