<?php

$personal = [
    'organization.empleados.consultar' => 'SELF',
    'organization.grados.gestionar' => 'SELF',
    'organization.niveles.consultar' => 'GLOBAL',
];

return [
    'matrix_version' => 'sprint-2-20261010-approved',
    'permissions' => [
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
        'DRPSS_REVISOR' => $personal + ['organization.asesores.consultar' => 'GLOBAL'],
        'DRPSS_ADMIN' => $personal + ['organization.asesores.consultar' => 'GLOBAL'],
        'RESIDENTE' => [],
    ],
];
