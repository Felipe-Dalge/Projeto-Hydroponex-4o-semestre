<?php
declare(strict_types=1);

const HORTA_ROLES = ['proprietario', 'gestor', 'agricultor', 'leitor'];
const HORTA_MANAGED_ROLES = ['gestor', 'agricultor', 'leitor'];
const HORTA_ADMIN_ROLES = ['proprietario', 'gestor'];
const HORTA_OPERATIONAL_ROLES = ['proprietario', 'gestor', 'agricultor'];

const HORTA_PERMISSIONS = [
    'horta.consultar' => HORTA_ROLES,
    'horta.editar' => HORTA_ADMIN_ROLES,
    'horta.excluir' => ['proprietario'],
    'membros.gerenciar' => ['proprietario'],
    'plantas.gerenciar' => HORTA_ADMIN_ROLES,
    'culturas.vincular' => HORTA_ADMIN_ROLES,
    'infraestrutura.gerenciar' => HORTA_ADMIN_ROLES,
    'sensores.gerenciar' => HORTA_ADMIN_ROLES,
    'monitoramento.registrar' => HORTA_ADMIN_ROLES,
    'nutrientes.gerenciar' => HORTA_ADMIN_ROLES,
    'tarefas.gerenciar' => HORTA_ADMIN_ROLES,
    'tarefas.proprias' => HORTA_OPERATIONAL_ROLES,
    'registros.consultar' => HORTA_ROLES,
    'registros.criar' => HORTA_OPERATIONAL_ROLES,
];

function horta_allows(string $role, string $action): bool
{
    return in_array($role, HORTA_PERMISSIONS[$action] ?? [], true);
}

function account_can_create_garden(array $user): bool
{
    return $user['perfil_entrada'] === 'administrador';
}

function account_areas(array $user, array $gardens): array
{
    $areas = [];
    if (account_can_create_garden($user) || array_intersect(HORTA_ADMIN_ROLES, array_column($gardens, 'papel'))) {
        $areas[] = 'administrativa';
    }
    if ($user['perfil_entrada'] === 'agricultor' || $gardens) {
        $areas[] = 'agricultor';
    }
    return $areas;
}
