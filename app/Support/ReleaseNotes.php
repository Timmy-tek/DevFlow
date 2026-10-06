<?php

namespace App\Support;

/**
 * Turns merged change requests into Markdown release notes, grouped by label.
 * Works on plain arrays so it can be tested without a database.
 */
final class ReleaseNotes
{
    /**
     * @param  array<int, array{title: string, ref: string, task_ref: ?string, label: ?string}>  $entries
     * @param  array<int, array{name: string, versions: array<int, string>}>  $files
     * @param  array<int, string>  $contributors
     */
    public static function build(array $entries, array $files = [], array $contributors = []): string
    {
        if ($entries === []) {
            return '';
        }

        $groups = [];

        foreach ($entries as $entry) {
            $groups[$entry['label'] ?? ''][] = $entry;
        }

        // Named labels A to Z, unlabelled changes last.
        $named = array_values(array_filter(array_keys($groups), fn($key) => (string) $key !== ''));
        sort($named, SORT_NATURAL | SORT_FLAG_CASE);

        $order = $named;

        if (isset($groups[''])) {
            $order[] = '';
        }

        $sections = [];

        foreach ($order as $label) {
            $heading = (string) $label === '' ? ($named === [] ? 'Changes' : 'Other changes') : $label;
            $lines = ["### {$heading}", ''];

            foreach ($groups[$label] as $entry) {
                $refs = implode(', ', array_filter([$entry['ref'], $entry['task_ref'] ?? null]));
                $lines[] = "- {$entry['title']} ({$refs})";
            }

            $sections[] = implode("\n", $lines);
        }

        if ($files !== []) {
            $lines = array_map(fn($file) => "- {$file['name']} (" . implode(', ', $file['versions']) . ')', $files);
            $sections[] = "### Files updated\n\n" . implode("\n", $lines);
        }

        if ($contributors !== []) {
            $sections[] = '**Contributors:** ' . implode(', ', $contributors);
        }

        return implode("\n\n", $sections) . "\n";
    }
}