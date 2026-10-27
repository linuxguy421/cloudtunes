<?php

namespace App\Policies;

use App\Models\Station;
use App\Models\User;

class StationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role === 'admin' || $user->approval_status === 'approved';
    }

    public function view(User $user, Station $station): bool
    {
        return $this->allowed($user, $station);
    }

    public function update(User $user, Station $station): bool
    {
        return $this->allowed($user, $station);
    }

    public function delete(User $user, Station $station): bool
    {
        return $this->allowed($user, $station);
    }

    public function start(User $user, Station $station): bool
    {
        return $this->allowed($user, $station);
    }

    public function stop(User $user, Station $station): bool
    {
        return $this->allowed($user, $station);
    }

    private function allowed(User $user, Station $station): bool
    {
        return $user->role === 'admin' || (string) $station->user_id === (string) $user->getKey();
    }
}
