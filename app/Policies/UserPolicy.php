<?php

namespace App\Policies;

use App\Models\User;

/**
 * Konten verwalten (Wireframe 14, A-05, A-07): nur Administratoren. Name und Adresse ändert die Verwaltung — auch
 * die eigenen (A-05: es gibt keine Profilseite). Rolle, Sperre und Entfernen nie am eigenen Konto und nie am letzten
 * aktiven Administratorkonto — so sperrt sich keine Installation selbst aus.
 */
class UserPolicy
{
    public function update(User $actor, User $account): bool
    {
        return $actor->isAdmin();
    }

    public function changeRole(User $actor, User $account): bool
    {
        return $this->administersOther($actor, $account);
    }

    public function disable(User $actor, User $account): bool
    {
        return $this->administersOther($actor, $account);
    }

    public function delete(User $actor, User $account): bool
    {
        return $this->administersOther($actor, $account);
    }

    private function administersOther(User $actor, User $account): bool
    {
        return $actor->isAdmin() && ! $actor->is($account) && ! $account->isLastActiveAdmin();
    }
}
