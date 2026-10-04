# ADR-001: monolito modular con fronteras verificadas

Estado: Aceptada · Fecha: 2026-09-06

## Contexto

El sistema comparte operación LAMP y necesita migración incremental, transacciones locales y límites de módulo ya definidos.

## Problema

Elegir una unidad de despliegue que reduzca acoplamiento sin introducir coordinación distribuida antes de estabilizar reglas y datos.

## Opciones evaluadas

- MVC Laravel único: simple, pero reproduce dependencias cruzadas.
- Microservicios por contexto: aislamiento fuerte, con alto coste de red, consistencia, observabilidad y operación.
- Monolito modular: un despliegue y una base, con namespaces, ownership y contratos internos obligatorios.

## Decisión

Usar un monolito modular. Cada módulo posee sus tablas, migraciones, rutas y providers. El dominio no depende de Laravel; módulos consumidores usan puertos o mensajes, nunca modelos Eloquent ajenos. Tests de arquitectura fallan ante una dependencia prohibida.

## Consecuencias

Transacciones y despliegue siguen siendo simples. Se exige disciplina automatizada y puede haber duplicación deliberada de read models. Un módulo sólo podrá extraerse tras demostrar necesidad operativa y contrato estable.
