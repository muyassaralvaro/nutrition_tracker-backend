<?php

namespace App;

use App\Models\User;
use GdImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AvatarImage
{
    private const MAX_BYTES = 3 * 1024 * 1024;

    public static function importGoogle(User $user, mixed $url): void
    {
        if ($user->avatar_path || ! is_string($url) || strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || ! str_ends_with(strtolower($parts['host'] ?? ''), '.googleusercontent.com')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return;
        }

        $response = Http::timeout(5)->connectTimeout(2)->withOptions(['allow_redirects' => false, 'stream' => true])->get($url);

        try {
            $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

            if (! $response->ok() || ! in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                return;
            }

            $body = $response->toPsrResponse()->getBody();
            $bytes = '';

            while (! $body->eof() && strlen($bytes) <= self::MAX_BYTES) {
                $chunk = $body->read(self::MAX_BYTES + 1 - strlen($bytes));

                if ($chunk === '') {
                    break;
                }

                $bytes .= $chunk;
            }

            if (strlen($bytes) <= self::MAX_BYTES) {
                self::store($user, $bytes);
            }
        } finally {
            $response->close();
        }
    }

    public static function store(User $user, string $bytes): bool
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return false;
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $size[0] < 64 || $size[1] < 64 || $size[0] > 4096 || $size[1] > 4096 || $size[0] * $size[1] > 12000000) {
            return false;
        }

        $source = @imagecreatefromstring($bytes);

        if (! $source instanceof GdImage) {
            return false;
        }

        $crop = min($size[0], $size[1]);
        $avatar = imagecreatetruecolor(256, 256);
        $copied = imagecopyresampled($avatar, $source, 0, 0, intdiv($size[0] - $crop, 2), intdiv($size[1] - $crop, 2), 256, 256, $crop, $crop);
        imagedestroy($source);

        if (! $copied) {
            imagedestroy($avatar);

            return false;
        }

        ob_start();
        $encoded = imagejpeg($avatar, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($avatar);

        if (! $encoded || ! is_string($jpeg) || $jpeg === '') {
            return false;
        }

        $path = 'avatars/'.$user->id.'/'.Str::uuid().'.jpg';

        if (! Storage::disk('local')->put($path, $jpeg)) {
            throw new RuntimeException('Could not store profile photo.');
        }

        $oldPath = $user->avatar_path;

        try {
            $user->update(['avatar_path' => $path]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return true;
    }
}
