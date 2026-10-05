<?php

namespace HiEvents\Services\Domain\Registration;

use Illuminate\Support\Facades\DB;

final class RespondentConfirmationRetention
{
    /** Only aggregate counts escape; assignments and canonical waiver evidence are never touched. */
    public function purge(int $batch = 500): array
    {
        $batch = max(1, min(5000, $batch));
        // At least one hour after expiry preserves every live hourly request budget.
        $cutoff = now()->subHours(max(1, (int) config('respondent-confirmation.retention_hours', 24)));
        $lock = DB::getDriverName() === 'pgsql' ? 'FOR UPDATE SKIP LOCKED' : true;
        $challenges = DB::transaction(function () use ($batch, $cutoff, $lock) {
            $ids = DB::table('respondent_confirmation_challenges')->where('expires_at', '<', $cutoff)
                ->orderBy('id')->limit($batch)->lock($lock)->pluck('id');

            return DB::table('respondent_confirmation_challenges')->whereIn('id', $ids)->delete();
        });
        // Separate transaction avoids reversing issue()'s destination -> challenge lock ordering.
        $destinations = DB::transaction(function () use ($batch, $cutoff, $lock) {
            $eligible = DB::table('respondent_confirmation_destinations')
                ->where(fn ($q) => $q->whereNull('last_requested_at')->orWhere('last_requested_at', '<', $cutoff))
                ->where(fn ($q) => $q->whereNull('window_started_at')->orWhere('window_started_at', '<', now()->subHour()))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('respondent_confirmation_challenges')
                    ->whereColumn('respondent_confirmation_challenges.destination_digest', 'respondent_confirmation_destinations.destination_digest'));
            $ids = (clone $eligible)->orderBy('id')->limit($batch)->lock($lock)->pluck('id');

            return $eligible->whereIn('id', $ids)->delete();
        });

        return ['challenges_deleted' => $challenges, 'destinations_deleted' => $destinations];
    }
}
