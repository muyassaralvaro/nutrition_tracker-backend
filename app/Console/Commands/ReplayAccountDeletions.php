<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use SplFileObject;

#[Signature('app:replay-account-deletions')]
#[Description('Reapply account deletions after restoring a database backup')]
class ReplayAccountDeletions extends Command
{
    public function handle(): int
    {
        $path = config('app.account_deletion_ledger_path');

        if (! is_file($path)) {
            return self::SUCCESS;
        }

        try {
            foreach (new SplFileObject($path) as $line) {
                if (trim($line) === '') {
                    continue;
                }

                $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($entry) || ! is_int($entry['user_id'] ?? null) || ! is_string($entry['created_at'] ?? null)) {
                    throw new JsonException('Invalid account deletion ledger entry.');
                }

                $user = User::find($entry['user_id']);

                if ($user === null || $user->created_at->toIso8601String() !== $entry['created_at']) {
                    continue;
                }

                $paths = [
                    ...$user->analyses()->whereNotNull('image_path')->pluck('image_path')->all(),
                    ...$user->meals()->whereNotNull('thumbnail_path')->pluck('thumbnail_path')->all(),
                    ...($user->avatar_path ? [$user->avatar_path] : []),
                ];
                $user->delete();
                Storage::disk('local')->delete($paths);
            }
        } catch (\Throwable $exception) {
            $this->error('Could not replay account deletions: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
