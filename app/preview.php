<?php
declare(strict_types=1);
const PREVIEW_PROFILES = [
    'member' => ['Membre', false, false],
    'chef' => ['Chef', true, false],
    'chaine' => ['Gestion de chaîne', false, true],
    'chef-chaine' => ['Chef et gestion de chaîne', true, true],
];
function previewControlRequest(string $path, string $action): bool {
    return $path === '/admin.php' && in_array($action, ['preview_start', 'preview_stop'], true);
}
function previewResolve(array $actor, ?array $state, callable $load): ?array {
    if (!$state || $actor['role'] !== 'admin' || $actor['must_change_password'] || ($state['actor_id'] ?? null) !== (int)$actor['id']) return null;
    if (($state['mode'] ?? '') === 'account') {
        $target = $load((int)($state['user_id'] ?? 0));
        if (!$target || $target['status'] !== 'active') return null;
        $label = 'Compte : '.$target['display_name'];
    } elseif (($state['mode'] ?? '') === 'profile' && isset(PREVIEW_PROFILES[$state['profile'] ?? ''])) {
        [$label, $chef, $chaine] = PREVIEW_PROFILES[$state['profile']];
        $target = $actor;
        $target['role'] = 'member'; $target['is_chef'] = $chef; $target['is_chaine'] = $chaine;
        $target['script_enabled'] = ($state['script_enabled'] ?? false) === true;
        $label = 'Profil : '.$label;
    } else return null;
    $target['_preview'] = ['label' => $label];
    return $target;
}
