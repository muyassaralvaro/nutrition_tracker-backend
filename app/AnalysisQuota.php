<?php

namespace App;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class AnalysisQuota
{
    public const DAILY_LIMIT = 7;

    public const REGENERATION_LIMIT = 2;

    private const TIMEZONE = 'Asia/Jakarta';

    /** @return array<string, bool|int|string|null> */
    public function status(User $user, ?string $analysisId = null): array
    {
        $unlimited = $user->isDeveloperAccount();
        $now = now(self::TIMEZONE);
        $today = $now->toDateString();
        $used = DB::table('meal_analysis_usage')->where('user_id', $user->id)
            ->where('usage_date', $today)->where('is_regeneration', false)->count();
        $regenerationsUsed = null;

        if ($analysisId !== null) {
            $usage = DB::table('meal_analysis_usage')->where('user_id', $user->id)->where('id', $analysisId)->first();
            abort_if($usage === null, 404);
            $regenerationsUsed = DB::table('meal_analysis_usage')->where('user_id', $user->id)
                ->where('root_id', $usage->root_id)->where('is_regeneration', true)->count();
        }

        return [
            'date' => $today,
            'unlimited' => $unlimited,
            'daily_limit' => $unlimited ? null : self::DAILY_LIMIT,
            'daily_used' => $used,
            'daily_remaining' => $unlimited ? null : max(0, self::DAILY_LIMIT - $used),
            'resets_at' => $unlimited ? null : $now->copy()->startOfDay()->addDay()->toIso8601String(),
            'regeneration_limit' => $unlimited ? null : self::REGENERATION_LIMIT,
            'regenerations_used' => $regenerationsUsed,
            'regenerations_remaining' => $unlimited || $regenerationsUsed === null ? null : max(0, self::REGENERATION_LIMIT - $regenerationsUsed),
        ];
    }

    public function reserve(User $user, string $id, ?string $retryOf, string $fingerprint): void
    {
        DB::transaction(function () use ($user, $id, $retryOf, $fingerprint): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $today = now(self::TIMEZONE)->toDateString();
            $usage = $retryOf === null ? null : DB::table('meal_analysis_usage')
                ->where('user_id', $user->id)->where('id', $retryOf)->first();
            abort_if($retryOf !== null && $usage === null, 404);

            if ($usage !== null) {
                if ($usage->fingerprint !== $fingerprint) {
                    $this->reject('different_meal', 'Retry must use the same photo or food name.', 422);
                }
                if (! $user->isDeveloperAccount()) {
                    $used = DB::table('meal_analysis_usage')->where('user_id', $user->id)
                        ->where('root_id', $usage->root_id)->where('is_regeneration', true)->count();
                    if ($used >= self::REGENERATION_LIMIT) {
                        $this->reject('regeneration_limit', 'This meal has used both regeneration attempts.', 429);
                    }
                }
            } else {
                if (! $user->isDeveloperAccount()) {
                    $used = DB::table('meal_analysis_usage')->where('user_id', $user->id)
                        ->where('usage_date', $today)->where('is_regeneration', false)->count();
                    if ($used >= self::DAILY_LIMIT) {
                        $this->reject('daily_analysis_limit', 'Daily meal analysis limit reached. Enter nutrition manually or try after the next midnight WIB.', 429);
                    }
                }
            }

            DB::table('meal_analysis_usage')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'root_id' => $usage->root_id ?? $id,
                'fingerprint' => $fingerprint,
                'is_regeneration' => $usage !== null,
                'usage_date' => $today,
                'created_at' => now('UTC'),
            ]);
        });
    }

    private function reject(string $code, string $message, int $status): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
