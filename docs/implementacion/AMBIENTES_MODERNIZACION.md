# Estado de ambientes después del reinicio — 2026-10-08

WSL y Docker operativos; servicios de ensayo activos en https://localhost:8443. PHP 8.4.26 sobre Alpine 3.24 seleccionado después de scans y paridad. Las instrucciones antiguas de reiniciar/esperar implementación quedan superadas por [resultados de ejecución](RESULTADOS_EJECUCION_2026-10-08.md).

Backups: scripts/backup-compose.ps1 y -Action Restore -Snapshot <ID>. Cifrados locales en .tools/backups; restore sin red/puertos y sin sustituir el origen. Tarea diaria a las 02:30 bajo perfil Windows interactivo; Docker debe estar disponible. Restore por inventario de contenido y SQL equivalente. Credenciales nuevas: scripts/rotate-compose-secrets.ps1, ensayo staging con reversión y claves previas conservadas.

El perfil production existente es ensayo. Para publicar se adaptan dominio, instancia, cuentas, custodia/recuperación de secretos y disponibilidad al proyecto. No se condiciona el avance a una infraestructura institucional inexistente.

# Ambientes de modernización y operación local

Actualizado: 2026-10-05, America/Mexico_City. Responsable: @AlejandroEH56.

Se adopta same-origin: Angular en /portal/, Laravel en /api/v1, /auth y /health. Se evita CORS entre SPA/API. Cookies laravel_session host-only, path /, HttpOnly, SameSite=Lax; Secure en staging y producción. Cada ambiente tiene base, cache prefix, storage privado y credenciales propias.

| Ambiente | Aplicación / origen | Base / credenciales |
|---|---|---|
| Desarrollo | APP_ENV=local; http://localhost:8000 | Base existente; sr_dev_runtime y sr_dev_migrator |
| Pruebas destructivas | APP_ENV=testing; SQLite :memory: y MySQL aislado | servicios_moderno_test; administrador del harness únicamente |
| Staging | APP_ENV=staging; https://localhost:8443 | servicios_moderno_stage; sr_stage_runtime/migrator |
| Ensayo de producción | APP_ENV=production; https://localhost:9443 | servicios_moderno_prod; sr_prod_runtime/migrator |
| Publicación productiva futura | Perfil Docker con DNS/TLS público del host elegido | Instancia/volúmenes y secretos nuevos; no reutilizar el ensayo local |

El ensayo productivo ejecuta configuración production/debug=false y aislamiento; no es una publicación ni valida un DNS público. Los servidores Artisan son sólo el mecanismo de ensayo Windows. El perfil de publicación utiliza PHP-FPM/Caddy y worker supervisado por el runtime de contenedores.

MySQL 26.7.0 y PHP 8.4.26 son la matriz adoptada para esta fundación. Las imágenes base se fijaron mediante digests consultados en Docker Registry. Antes de publicar deben verificarse soporte, parches y los scans del candidato. El equipo dispone de Windows 11 Pro, 12 procesadores lógicos, 15.7 GB RAM y 264.2 GB libres al inspeccionarlo.

## Preparación y arranque

Usar el PHP portable, cargar scripts/use-local-tools.ps1 y no cambiar la política global de Windows.

1. modernization:prepare-environments --activate-runtime crea/adopta los ambientes mediante el administrador guardado en .tools/environments/admin.env. No ejecuta fresh ni borra datos. Crea migrator primero, migra y concede permisos runtime por tabla.
2. scripts/protect-local-secrets.ps1 restringe las ACL. Fuera del sandbox toma el SID del usuario real; dentro exige -DeveloperSid con el SID comprobado. Incluye SYSTEM, administradores y CodexSandboxOffline, cuenta autorizada para estas pruebas.
3. npm --prefix frontend run build -- --base-href=/portal/ prepara Angular.
4. scripts/install-operations-tools.ps1 instala Caddy 2.11.7 y Compose 5.6.0 desde sus releases oficiales, verificando SHA-512/SHA-256 según publica cada proveedor. Playwright 1.63.0 y Chromium se instalaron para navegador.
5. scripts/start-stage.ps1 inicia backend, proxy TLS y supervisor del worker. -Environment production inicia el ensayo productivo en sus propios puertos y procesos.
6. -Action Status muestra sólo los procesos registrados; -Action Stop detiene sus árboles y conserva datos; -Action RestartWorker recupera únicamente el worker sin reiniciar HTTP/proxy. Si el sandbox deniega terminar procesos, ejecutar fuera de él. No usar kill por nombre de proceso.
7. La CA de Caddy está en .tools/caddy-data/pki/authorities/local/root.crt; la de production, en .tools/caddy-production-data/pki/authorities/local/root.crt. Confiar sólo en el certificado público en CurrentUser/Root para el navegador; no exportar claves privadas. NODE_EXTRA_CA_CERTS permite verificar la misma CA en Node sin desactivar TLS.
8. npm run test:e2e verifica TLS, cookies, CSRF, API anónima, health y redirect OIDC. No acredita una autenticación real de Microsoft ni el registro de los callbacks HTTPS.

Las cuentas runtime sólo tienen DML sobre las tablas actuales; auditoría permite SELECT/INSERT y rechaza UPDATE/DELETE. Migrator puede DDL/DML sólo en su esquema y no administra usuarios globales. Nuevos módulos necesitan grants revisados, no un GRANT global al runtime. El perfil .env.operator habilita CLI iam:state/outbox:replay; los perfiles runtime y migrator no habilitan administración IAM.

## Despliegue en contenedores

compose.yaml incluye MySQL, PHP-FPM, proxy/Angular, worker, migrator de mantenimiento, concesión de permisos, ClamAV y collector OTLP. docker/ contiene imágenes/configuración. WSL 3.0.1 y Docker Desktop por usuario están instalados; docker.exe 29.8.1 está en %LOCALAPPDATA%/Programs/DockerDesktop/resources/bin. Build/arranque siguen sin acreditar: el motor no está inicializado y los componentes Windows requieren reinicio. artifacts/environment/windows-container-features.json registra restartNeeded=true.

1. Guardar el trabajo y reiniciar Windows; enable-container-host.ps1 ya habilitó los componentes con elevación y NoRestart. Comprobar wsl --status, abrir Docker Desktop y completar su primera configuración. Verificar docker version con Client y Server; resolver virtualización de firmware si WSL aún la requiere. No se reinició este equipo automáticamente.
2. Ejecutar prepare-compose.ps1: genera secretos exclusivos del ensayo en .tools/environments. El SQL de creación de usuarios entra como secret; el grant-runtime no contiene contraseñas.
3. Detener staging nativo con start-stage.ps1 -Action Stop para liberar 8443. Validar docker compose config --quiet, construir backend/proxy y ejecutar scans de las imágenes construidas.
4. docker compose up -d mysql inicia la base aislada y sus principals. El root sólo existe en la red privada; MySQL no publica un puerto al host.
5. docker compose --profile maintenance run --rm migrator aplica migraciones.
6. docker compose --profile maintenance run --rm grant-runtime concede DML y auditoría append-only al runtime.
7. docker compose up -d backend worker proxy antivirus collector inicia los procesos restantes.
8. Repetir suites MySQL, navegador, EICAR/fallos AV, señales OTLP, fallos DB/storage/worker y backup/restore sobre esta topología.
9. Para publicación, adaptar production.environment.example y generar secretos/datos/volúmenes independientes. El schema interno del perfil de referencia se llama servicios_moderno_stage; la separación está dada por instancia y proyecto Compose. Renombrarlo exige cambiar de forma coherente scripts/grants/configuración.

Docker Compose entrega secretos desde archivos del host; no proporciona por sí solo un vault cifrado. Las ACL y archivos ignorados resuelven el desarrollo local, no cierran B11. El secret store definitivo, rotación de Entra y recuperación de APP_KEY se implementan/ensayan antes de promoción.

Storage elegido: volumen/ filesystem privado fuera del webroot, sin exposición directa, cuarentena antes de promoción y ClamAV en red interna. Falta implementar el port y demostrar EICAR, fallo cerrado del scanner y restore. No se anuncian uploads de negocio todavía. Objetivos iniciales: backup diario, RPO 24 h y RTO 4 h; validar mediante restauración aislada antes de aceptar el cierre.

Observabilidad elegida: OTLP HTTP interno, collector con redacción y archivos rotados de ensayo; fail-open para telemetry, fuera de readiness. Retención de ensayo siete días. La configuración de collector no acredita instrumentación ni recepción de señales. Falta SDK/export, redacción extremo a extremo, fallos y métricas outbox.

Fallos comprobados en staging nativo: tests/Integration/ReadinessFaultTest.php se habilita exclusivamente con RUN_NATIVE_FAULT_TESTS=1 y produce dos pruebas/35 aserciones PASS para DB/storage, 503/200 y recuperación sin pérdida. El worker detenido superó el TTL de 120 s, readiness devolvió 503 y recuperó 200 al reiniciarlo. artifacts/worker-recovery.xml registra dos pruebas TLS PASS después de la recuperación. No ejecutar estos fallos contra un despliegue público; repetirlos en la instancia aislada Docker.

Entra conserva las credenciales configuradas como recursos de ensayo; no se copian al frontend ni al repositorio. Registrar y acreditar https://localhost:8443/auth/microsoft/callback y https://localhost:9443/auth/microsoft/callback antes de login real. La App Registration productiva y sus credenciales serán independientes al publicar.
