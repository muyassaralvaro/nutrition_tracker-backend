<?php

namespace App\Console\Commands;

use App\Models\MealAnalysis;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('app:purge-expired-analyses')]
#[Description('Remove expired meal photo drafts and private images')]
class PurgeExpiredAnalyses extends Command
{
    public function handle(): int
    {
        MealAnalysis::query()->where('expires_at', '<=', now())->chunkById(100, function ($analyses): void {
            foreach ($analyses as $analysis) {
                if ($analysis->image_path && ! Storage::disk('local')->delete($analysis->image_path)) {
                    continue;
                }

                $analysis->delete();
            }
        });

        foreach (Storage::disk('local')->files('meal-analyses') as $path) {
            if (Storage::disk('local')->lastModified($path) < now()->subDay()->timestamp && ! MealAnalysis::where('image_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }

        return self::SUCCESS;
    }
}
