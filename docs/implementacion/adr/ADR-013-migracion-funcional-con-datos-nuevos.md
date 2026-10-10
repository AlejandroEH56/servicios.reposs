# ADR-013: migración funcional con datos nuevos

Fecha: 2026-10-08. Estado: ACEPTADO por el responsable @AlejandroEH56.

El responsable confirmó: «Migrar funcionalidades con datos nuevos».

El origen funcional es el código y la documentación de `C:\GitHubRepositories\servicios.proyecto`. No se transfieren registros históricos ni se requiere localizar/restaurar un dump como condición del pre-Sprint 1. Las cuatro bases vacías/ausentes inspeccionadas y su manifest se conservan como evidencia de aquella inspección; no se presentan como perfilado de una producción histórica.

Cada módulo conserva sus criterios funcionales y contratos. Los catálogos iniciales serán explícitos y revisables, sin inventar usuarios, inventarios o volúmenes reales. Se aplica ADR-012 a IDs/FK y MIGRATION_OWNERSHIP a las migraciones. Las excepciones técnicas de Laravel no se convierten en excepciones de identidad de dominio.

Se conservan la base de desarrollo actual, la unicidad global de correo y el vínculo exclusivo tenant/objectId. No se fusionan cuentas por correo. Los fixtures operativos son sintéticos; una identidad de recuperación sin cuenta externa queda archivada y no puede iniciar sesión.

BN-04 queda resuelto en alcance local. El estado VERIFIED del gate requiere adjuntar esta decisión y los checks pertinentes al mismo SHA candidato.