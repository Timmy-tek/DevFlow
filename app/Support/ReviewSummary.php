<?php

namespace App\Support;

/**
 * The review state of a change request, computed from plain arrays so the rules
 * live in one place (and can be tested without a database).
 */
final class ReviewSummary
{
    public const REQUIRED_APPROVALS = 1;

    /**
     * @param  array<int, array{user_id: int, name: string, state: string, requested: bool}>  $reviewers
     * @param  array<int, array{name: string, base: int, current: ?int}>  $outOfDate
     */
    public function __construct(
        public readonly array $reviewers,
        public readonly int $approvals,
        public readonly array $outOfDate,
        public readonly bool $hasFiles,
        public readonly bool $isOpen,
    ) {
    }

    /**
     * Each person's current verdict: their latest approve / request-changes.
     * An approval given before the newest revision is "stale" and no longer counts.
     * The author's own reviews never count.
     *
     * @param  array<int, array{id: int, user_id: int, state: string, cr_revision_id: int}>  $reviews
     * @return array<int, string> user id => approved | stale | changes_requested
     */
    public static function verdicts(array $reviews, int $authorId, ?int $latestRevisionId): array
    {
        usort($reviews, fn($a, $b) => $a['id'] <=> $b['id']);

        $latest = [];

        foreach ($reviews as $review) {
            if ($review['user_id'] === $authorId || !in_array($review['state'], ['approved', 'changes_requested'], true)) {
                continue;
            }

            $latest[$review['user_id']] = $review;
        }

        return array_map(function (array $review) use ($latestRevisionId) {
            if ($review['state'] === 'changes_requested') {
                return 'changes_requested';
            }

            return $review['cr_revision_id'] === $latestRevisionId ? 'approved' : 'stale';
        }, $latest);
    }

    /**
     * @param  array<int, array{id: int, user_id: int, state: string, cr_revision_id: int}>  $reviews
     * @param  array<int, int>  $requestedIds
     * @param  array<int, string>  $names  user id => name
     * @param  array<int, array{name: string, base: int, current: ?int}>  $outOfDate
     */
    public static function build(
        array $reviews,
        array $requestedIds,
        array $names,
        int $authorId,
        ?int $latestRevisionId,
        array $outOfDate,
        bool $hasFiles,
        bool $isOpen,
    ): self {
        $verdicts = self::verdicts($reviews, $authorId, $latestRevisionId);
        $requestedIds = array_values(array_diff($requestedIds, [$authorId]));

        $reviewers = [];

        foreach ($requestedIds as $id) {
            $reviewers[] = ['user_id' => $id, 'name' => $names[$id] ?? 'Unknown', 'state' => $verdicts[$id] ?? 'pending', 'requested' => true];
        }

        foreach ($verdicts as $id => $state) {
            if (!in_array($id, $requestedIds, true)) {
                $reviewers[] = ['user_id' => $id, 'name' => $names[$id] ?? 'Unknown', 'state' => $state, 'requested' => false];
            }
        }

        $approvals = count(array_filter($verdicts, fn($state) => $state === 'approved'));

        return new self($reviewers, $approvals, $outOfDate, $hasFiles, $isOpen);
    }

    public function required(): int
    {
        return self::REQUIRED_APPROVALS;
    }

    public function changesRequestedBy(): array
    {
        return array_values(array_filter($this->reviewers, fn($r) => $r['state'] === 'changes_requested'));
    }

    public function hasStale(): bool
    {
        return count(array_filter($this->reviewers, fn($r) => $r['state'] === 'stale')) > 0;
    }

    /** @return array<int, string> human-readable reasons the merge is blocked */
    public function blockers(): array
    {
        if (!$this->isOpen) {
            return [];
        }

        $out = [];

        if (!$this->hasFiles) {
            $out[] = 'No files in this change request';
        }

        if ($this->approvals < self::REQUIRED_APPROVALS) {
            $out[] = 'Needs ' . self::REQUIRED_APPROVALS . ' approval' . (self::REQUIRED_APPROVALS === 1 ? '' : 's')
                . ($this->hasStale() ? ' (earlier approvals are stale after the latest revision)' : '');
        }

        foreach ($this->changesRequestedBy() as $reviewer) {
            $out[] = $reviewer['name'] . ' requested changes';
        }

        foreach ($this->outOfDate as $file) {
            $out[] = $file['current'] === null
                ? "{$file['name']} was deleted"
                : "{$file['name']} is now v{$file['current']} (this was based on v{$file['base']})";
        }

        return $out;
    }

    public function ready(): bool
    {
        return $this->isOpen && $this->blockers() === [];
    }
}