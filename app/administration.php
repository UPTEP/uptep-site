<?php
declare(strict_types=1);

/** Changes one account, with fresh authorization and serialized last-admin checks. */
function saveUserPermissions(array $actor, int $targetId, int $version, bool $admin, bool $chef, bool $chaine, bool $approved): string {
    $p = db();
    $p->beginTransaction();
    try {
        // Serializes permission changes, including concurrent admin removals.
        $p->exec('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
        $q = $p->prepare('SELECT role, status, session_version, must_change_password FROM users WHERE id = ?');
        $q->execute([$actor['id']]);
        $currentActor = $q->fetch(PDO::FETCH_ASSOC);
        if (!$currentActor || $currentActor['role'] !== 'admin' || $currentActor['status'] !== 'active' || $currentActor['must_change_password'] || (int)$currentActor['session_version'] !== (int)$actor['session_version']) {
            throw new DomainException('Tu n’as plus accès à l’administration. Recharge la page.', 403);
        }
        $q = $p->prepare('SELECT * FROM users WHERE id = ?');
        $q->execute([$targetId]);
        $target = $q->fetch(PDO::FETCH_ASSOC);
        if (!$target) throw new DomainException('Cet utilisateur n’existe plus.', 404);
        if ((int)$target['permissions_version'] !== $version) throw new DomainException('Ce compte a été modifié entre-temps. Vérifie les valeurs actualisées avant de réessayer.', 409);
        if ($target['role'] === 'admin' && $target['status'] === 'active' && (!$admin || !$approved)) {
            $q = $p->prepare("SELECT count(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?");
            $q->execute([$targetId]);
            if ((int)$q->fetchColumn() === 0) throw new DomainException('Il faut conserver au moins un administrateur avec une inscription validée.', 422);
        }
        $role = $admin ? 'admin' : 'member';
        // An unchecked disabled account stays disabled, not silently pending.
        $status = $approved ? 'active' : ($target['status'] === 'disabled' ? 'disabled' : 'pending');
        if ($target['role'] === $role && $target['is_chef'] === $chef && $target['is_chaine'] === $chaine && $target['status'] === $status) {
            $p->commit(); return $target['display_name'];
        }
        $q = $p->prepare("UPDATE users SET role = ?, is_chef = ?, is_chaine = ?,
            approved_at = CASE WHEN ? = 'active' THEN COALESCE(approved_at, now()) ELSE NULL END,
            approved_by = CASE WHEN ? = 'active' THEN COALESCE(approved_by, CAST(? AS bigint)) ELSE NULL END,
            session_version = session_version + CASE WHEN status <> ? THEN 1 ELSE 0 END,
            status = ?, permissions_version = permissions_version + 1 WHERE id = ?");
        $q->execute([$role, $chef ? 'true' : 'false', $chaine ? 'true' : 'false', $status, $status, $actor['id'], $status, $status, $targetId]);
        $p->commit();
        return $target['display_name'];
    } catch (Throwable $error) {
        if ($p->inTransaction()) $p->rollBack();
        throw $error;
    }
}
