<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone_e164' => $this->phone_e164,
            'email' => $this->email,
            'avatar_url' => $this->avatar_path ? route('me.avatar', [], false).'?v='.basename($this->avatar_path) : null,
            'has_password' => $this->password !== null,
        ];
    }
}
