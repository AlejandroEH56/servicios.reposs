# ADR-003: Angular standalone, Signals y RxJS

Estado: Aceptada · Fecha: 2026-09-06

## Contexto

La interfaz se dividirá por feature y consumirá exclusivamente contratos API versionados.

## Problema

Elegir versión, composición y estado sin crear un store global difícil de migrar y probar.

## Opciones evaluadas

- NgModules + servicios mutables: familiar, pero con mayor acoplamiento.
- NgRx global: potente para eventos complejos, excesivo como baseline.
- Standalone + Signals por feature + RxJS para I/O: estado explícito y menor ceremonia.

## Decisión

Usar Angular 22.x —cumple 20+ y evita iniciar cerca del fin de LTS de v20—, componentes standalone, lazy routes, Signals para estado derivado/local, RxJS para HTTP, streams y cancelación. No usar NgRx inicialmente; una feature podrá adoptarlo mediante ADR y evidencia de múltiples productores, efectos y trazabilidad que el store por Signals no resuelva.

## Consecuencias

Bundles y ownership por feature son claros. Debe establecerse una plantilla de facade/store para evitar estilos divergentes. RxJS no desaparece; se limita a fronteras asíncronas.

Fuentes: [soporte Angular](https://angular.dev/reference/releases), [Signals](https://v20.angular.dev/guide/signals) e [interoperabilidad RxJS](https://angular.dev/ecosystem/rxjs-interop).
