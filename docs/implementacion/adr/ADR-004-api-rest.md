# ADR-004: REST API First, OpenAPI y Problem Details

Estado: Aceptada · Fecha: 2026-09-06

## Contexto

Angular y la convivencia con CodeIgniter requieren contratos estables e independientes de Eloquent.

## Problema

Definir estilo, versionado, errores y mecanismo de control de cambios.

## Opciones evaluadas

- Endpoints ad hoc: rápidos, sin gobernanza.
- GraphQL: flexible, pero añade autorización por campo, caching y tooling no requeridos.
- REST documentado con OpenAPI: encaja con recursos/comandos y generación de clientes.

## Decisión

REST JSON bajo `/api/v1`; OpenAPI 3.1 es fuente del contrato y se modifica antes del código. Commands no idempotentes aceptan `Idempotency-Key`. Errores usan `application/problem+json` conforme a RFC 9457, sucesor compatible de RFC 7807, con `errors`, `code`, `correlationId` y sin información sensible.

## Consecuencias

CI debe validar breaking changes y generar cliente Angular. Los cambios incompatibles requieren `/v2` y plan de deprecación; no se versiona por header oculto.

Fuentes: [OpenAPI 3.1](https://spec.openapis.org/oas/v3.1.1.html) y [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457.html).
