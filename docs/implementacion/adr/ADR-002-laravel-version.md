# ADR-002: Laravel y política de versión

Estado: Aceptada con gate · Fecha: 2026-09-06

## Contexto

El objetivo recibido menciona “Laravel 12 LTS” y PHP 8.3+. A la fecha, Laravel 12 ya terminó su ventana de bug fixes y termina seguridad el 2027-02-24; Laravel no lo clasifica como LTS. Laravel 13 requiere PHP 8.3.

## Problema

Evitar iniciar una reescritura sobre una versión próxima al fin de soporte sin perder compatibilidad con la restricción planteada.

## Opciones evaluadas

- Laravel 12: menor desviación nominal, pero ventana de soporte insuficiente.
- Laravel 13: soportado, compatible con PHP 8.3 y menor deuda inmediata.
- Framework propio/Symfony directo: no aporta valor frente al objetivo aprobado.

## Decisión

Crear el backend en Laravel 13.x con PHP 8.3/8.4. Laravel 12 sólo se permite por excepción institucional escrita, con upgrade ensayado y completado antes del go-live y siempre antes de 2027-02-24. Composer usa restricciones de major y Renovate/Dependabot propone parches.

## Consecuencias

Se corrige una premisa obsoleta y se amplía soporte. El equipo debe validar paquetes sobre 13.x. Si se impone 12, se añade un sprint técnico y no se acepta deuda más allá de su soporte oficial.

Fuente: [política oficial de soporte Laravel](https://laravel.com/framework/docs/releases).
