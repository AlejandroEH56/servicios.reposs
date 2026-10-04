# ADR-011: telemetría estructurada y OpenTelemetry

Estado: Aceptada · Fecha: 2026-09-06

## Contexto

La convivencia, ETL, workers y reglas concurrentes requieren diagnosticar una operación de extremo a extremo.

## Problema

Obtener señales útiles sin atarse a un proveedor ni exponer datos personales.

## Opciones evaluadas

- Logs de texto únicamente: insuficientes para latencia y causalidad.
- Suite propietaria embebida: rápida, con lock-in.
- Logs JSON + métricas + trazas OpenTelemetry/OTLP: estándar y exportable.

## Decisión

Emitir logs JSON a stdout/archivo rotado, métricas y trazas OTLP. Propagar `X-Correlation-ID` validado o generado; registrar `trace_id`, módulo, operación y resultado, nunca tokens/PII/documentos. Health separado en liveness y readiness. SLO inicial: disponibilidad 99.5%, p95 lectura <500 ms, p95 comando <1 s sin trabajos asíncronos, error 5xx <1%, outbox pendiente p95 <60 s.

## Consecuencias

Se requiere collector/backend institucional y presupuesto de cardinalidad/retención. Los SLO se recalibran con carga real; cualquier degradación de cutover debe ser visible y reversible.
