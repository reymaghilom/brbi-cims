<?php

namespace App\Services\Dashboard;

use App\Enums\ClientFolderStatus;
use App\Models\ClientFolder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class DashboardTrendData
{
    public const RANGES = ['7d' => '7 Days', '30d' => '30 Days', '12m' => '12 Months'];

    public const DEFAULT_RANGE = '7d';

    public function normalizeRange(?string $range): string
    {
        return array_key_exists((string) $range, self::RANGES) ? (string) $range : self::DEFAULT_RANGE;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(User $user, CarbonImmutable $now, string $timezone): array
    {
        return collect(self::RANGES)
            ->map(fn (string $label, string $range): array => $this->for($user, $range, $now, $timezone))
            ->all();
    }

    /**
     * Completed investigations over time, read from each folder's own `completed_at`. Grouping is
     * done after converting to the display timezone so a late-evening completion is never counted
     * on the following day.
     *
     * @return array{points: array<int, array{label: string, value: int}>, max: int, total: int, bars: array<int, array{label: string, short: string, tooltip: string, value: int}>, period_label: string}
     */
    public function for(User $user, string $range, CarbonImmutable $now, string $timezone): array
    {
        [$buckets, $format, $labelFormat] = match ($range) {
            '30d' => [$this->dayBuckets($now, 30), 'Y-m-d', 'M j'],
            '12m' => [$this->monthBuckets($now, 12), 'Y-m', 'M Y'],
            default => [$this->dayBuckets($now, 7), 'Y-m-d', 'M j'],
        };

        $completions = ClientFolder::query()
            ->accessibleTo($user)
            ->where('status', ClientFolderStatus::Completed)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $buckets->first()->utc())
            ->pluck('completed_at');

        $counts = $completions
            ->map(fn ($completedAt): string => $completedAt->timezone($timezone)->format($format))
            ->countBy();

        $points = $buckets->map(fn (CarbonImmutable $bucket): array => [
            'label' => $bucket->format($labelFormat),
            'value' => (int) ($counts[$bucket->format($format)] ?? 0),
        ])->values();

        // What the card draws: the same counts, grouped only for readability. 7 Days and 12 Months
        // keep one bar per day / month; 30 Days sums its daily counts into week-long bars (the last
        // one may be shorter) so thirty bars never crowd a narrow card. Every bar value is a sum of
        // the points above, so the bars always add up to the same authoritative total.
        $value = fn (CarbonImmutable $bucket): int => (int) ($counts[$bucket->format($format)] ?? 0);
        $bars = match ($range) {
            '30d' => $buckets->values()->chunk(7)->map(function (Collection $week) use ($value): array {
                $start = $week->first();
                $end = $week->last();
                $label = match (true) {
                    $start->equalTo($end) => $start->format('M j'),
                    $start->isSameMonth($end) => $start->format('M j').'–'.$end->format('j'),
                    default => $start->format('M j').'–'.$end->format('M j'),
                };

                return ['label' => $label, 'short' => $start->format('M j'), 'tooltip' => $label, 'value' => $week->sum($value)];
            }),
            '12m' => $buckets->map(fn (CarbonImmutable $bucket): array => ['label' => $bucket->format('M'), 'short' => $bucket->format('M'), 'tooltip' => $bucket->format('F Y'), 'value' => $value($bucket)]),
            default => $buckets->map(fn (CarbonImmutable $bucket): array => ['label' => $bucket->format('M j'), 'short' => $bucket->format('j'), 'tooltip' => $bucket->format('M j'), 'value' => $value($bucket)]),
        };

        return [
            'points' => $points->all(),
            'max' => max(1, (int) $points->max('value')),
            'total' => (int) $points->sum('value'),
            'bars' => $bars->values()->all(),
            'period_label' => match ($range) {
                '30d' => 'Last 30 days',
                '12m' => 'Last 12 months',
                default => 'Last 7 days',
            },
        ];
    }

    /** @return Collection<int, CarbonImmutable> */
    private function dayBuckets(CarbonImmutable $now, int $days): Collection
    {
        return collect(range($days - 1, 0))->map(fn (int $offset): CarbonImmutable => $now->startOfDay()->subDays($offset));
    }

    /** @return Collection<int, CarbonImmutable> */
    private function monthBuckets(CarbonImmutable $now, int $months): Collection
    {
        return collect(range($months - 1, 0))->map(fn (int $offset): CarbonImmutable => $now->startOfMonth()->subMonths($offset));
    }
}
