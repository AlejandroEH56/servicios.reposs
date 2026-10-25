<?php

$personal = [
    'organization.empleados.consultar' => 'SELF',
    'organization.grados.gestionar' => 'SELF',
    'organization.niveles.consultar' => 'GLOBAL',
];

$resident = [
    'residencies.files.read' => 'SELF',
    'residencies.profile.manage' => 'SELF',
    'residencies.projects.manage' => 'SELF',
    'residencies.advisers.assign' => 'SELF',
    'residencies.companies.create' => 'SELF',
    'residencies.companies.manage' => 'SELF',
    'residencies.evidence.submit' => 'SELF',
];
$residenciesAdministration = array_fill_keys([...array_keys($resident), 'residencies.catalogs.manage', 'residencies.residents.enroll', 'residencies.requirements.manage', 'residencies.evidence.review'], 'GLOBAL');

return [
    'matrix_version' => 'sprint-7-20261024-approved',
    'permissions' => [
        'residencies.requirements.manage' => ['GLOBAL'],
        'residencies.evidence.submit' => ['SELF', 'GLOBAL'],
        'residencies.evidence.review' => ['GLOBAL'],
        'residencies.files.read' => ['SELF', 'GLOBAL'],
        'residencies.profile.manage' => ['SELF', 'GLOBAL'],
        'residencies.projects.manage' => ['SELF', 'GLOBAL'],
        'residencies.advisers.assign' => ['SELF', 'GLOBAL'],
        'residencies.companies.create' => ['SELF', 'GLOBAL'],
        'residencies.companies.manage' => ['SELF', 'GLOBAL'],
        'residencies.catalogs.manage' => ['GLOBAL'],
        'residencies.residents.enroll' => ['GLOBAL'],
        'planning.manage' => ['GLOBAL'],
        'planning.catalogs.manage' => ['GLOBAL'],
        'publicaciones.administrar' => ['GLOBAL'],
        'iam.roles.consultar' => ['GLOBAL'],
        'iam.roles.gestionar' => ['GLOBAL'],
        'organization.empleados.consultar' => ['SELF', 'GLOBAL'],
        'organization.empleados.gestionar' => ['GLOBAL'],
        'organization.grados.gestionar' => ['SELF', 'GLOBAL'],
        'organization.unidades.consultar' => ['GLOBAL'],
        'organization.unidades.gestionar' => ['GLOBAL'],
        'organization.niveles.consultar' => ['GLOBAL'],
        'organization.niveles.gestionar' => ['GLOBAL'],
        'organization.asesores.consultar' => ['GLOBAL'],
    ],
    'roles' => [
        'ADMIN_RESIDENCIAS' => $residenciesAdministration,
        'ADMIN_PLANIFICACION' => ['planning.manage' => 'GLOBAL', 'planning.catalogs.manage' => 'GLOBAL'],
        'ADMIN_PUBLICACIONES' => ['publicaciones.administrar' => 'GLOBAL'],
        'ADMIN_IAM' => ['iam.roles.consultar' => 'GLOBAL', 'iam.roles.gestionar' => 'GLOBAL'],
        'ADMIN_ORGANIZACION' => [
            'organization.empleados.consultar' => 'GLOBAL',
            'organization.empleados.gestionar' => 'GLOBAL',
            'organization.grados.gestionar' => 'GLOBAL',
            'organization.unidades.consultar' => 'GLOBAL',
            'organization.unidades.gestionar' => 'GLOBAL',
            'organization.niveles.consultar' => 'GLOBAL',
            'organization.niveles.gestionar' => 'GLOBAL',
            'organization.asesores.consultar' => 'GLOBAL',
        ],
        'EMPLEADO' => $personal,
        'DOCENTE_SOLICITANTE' => $personal,
        'LABORATORISTA' => $personal,
        'INVENTARIO_RESPONSABLE' => $personal,
        'DRPSS_REVISOR' => $personal + ['organization.asesores.consultar' => 'GLOBAL', 'residencies.files.read' => 'GLOBAL', 'residencies.evidence.review' => 'GLOBAL'],
        'DRPSS_ADMIN' => $personal + ['organization.asesores.consultar' => 'GLOBAL', 'publicaciones.administrar' => 'GLOBAL'] + $residenciesAdministration,
        'RESIDENTE' => $resident,
    ],
];
