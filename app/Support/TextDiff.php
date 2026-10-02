<?php

namespace App\Support;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

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
     *
     * @return array{rows: array<int, array{type: string, old: ?int, new: ?int, text: string}>, added: int, removed: int}|null
     */
    public static function compare(string $old, string $new, int $context = 3): ?array
    {
        if (substr_count($old, "\n") > 3000 || substr_count($new, "\n") > 3000) {
            return null;
        }

        $raw = (new Differ(new UnifiedDiffOutputBuilder('')))->diffToArray($old, $new);

        $rows = [];
        $oldNo = 0;
        $newNo = 0;
        $added = 0;
        $removed = 0;

        foreach ($raw as [$line, $status]) {
            if ($status > 2) {
                continue; // "no newline at end of file" markers
            }

            $text = rtrim($line, "\r\n");

            if ($status === 1) {
                $newNo++;
                $added++;
                $rows[] = ['type' => 'add', 'old' => null, 'new' => $newNo, 'text' => $text];
            } elseif ($status === 2) {
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

        foreach ($rows as $i => $row) {
            if ($row['type'] === 'ctx') {
                continue;
            }

            for ($j = max(0, $i - $context); $j <= min($total - 1, $i + $context); $j++) {
                $keep[$j] = true;
            }
        }

        $collapsed = [];
        $skipped = 0;

        foreach ($rows as $i => $row) {
            if ($keep[$i]) {
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

    private static function gap(int $count): array
    {
        return ['type' => 'gap', 'old' => null, 'new' => null, 'text' => $count . ' unchanged ' . ($count === 1 ? 'line' : 'lines')];
    }
}