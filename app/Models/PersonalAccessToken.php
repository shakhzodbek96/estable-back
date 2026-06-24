<?php

namespace App\Models;

use Carbon\Carbon;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

class PersonalAccessToken extends SanctumToken
{
    public function save(array $options = []): bool
    {
        if (
            array_keys($this->getDirty()) === ['last_used_at'] &&
            ($original = $this->getOriginal('last_used_at')) !== null &&
            Carbon::parse($original)->gt(now()->subMinutes(10))
        ) {
            return true;
        }

        return parent::save($options);
    }
}
