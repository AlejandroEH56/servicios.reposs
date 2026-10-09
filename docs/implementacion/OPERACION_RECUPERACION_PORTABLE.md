# Operación y recuperación de la fundación

Responsable y custodio: desarrollador @AlejandroEH56. Alcance: desarrollo, staging y ensayo local de producción; migración funcional con datos nuevos. La promoción pública se adecúa al proyecto y exige el ensayo de su infraestructura destino.

## Secretos y perfiles

`.env` y perfiles `.tools/environments/` están excluidos de Git y sujetos a ACL del propietario/SYSTEM. Runtime no tiene DDL ni UPDATE/DELETE sobre auditoría. Migrator aplica DDL; operator tiene SELECT/UPDATE de identidades, SELECT de vínculos y SELECT/INSERT de auditoría, sin DDL. El servicio Compose operator es sólo de mantenimiento; backend y worker conservan operaciones administrativas deshabilitadas.

Entra fue rotado creando la sustituta desde el portal, sincronizando los perfiles, recreando backend/worker y probando un login real. La credencial anterior fue retirada por el responsable; OAuth la rechaza con HTTP 401/AADSTS7000215, la nueva obtiene token y la key anterior ya no figura en la App Registration. El script mantiene los valores en memoria o archivos privados cifrados, nunca en artifacts publicables. La aplicación no recibe permisos para administrar sus secretos en Entra.

```powershell
# Preparar copia cifrada antes de modificar MICROSOFT_CLIENT_SECRET
./scripts/rotate-entra-secret.ps1 -Action Prepare
# Actualizar únicamente esa variable en .env y conservar temporalmente la clave anterior
./scripts/rotate-entra-secret.ps1 -Action Activate
# Login/MFA nuevo y /api/v1/me ACTIVA; retirar la clave anterior en el portal
./scripts/rotate-entra-secret.ps1 -Action VerifyRevoked
```

Consultar `Get-Help ./scripts/rotate-entra-secret.ps1` y el parámetro del script antes de una rotación posterior: no sobrescribir el dossier de una rotación anterior sin archivarlo. APP_PREVIOUS_KEYS conserva recuperación de ciphertext anterior; retirarlas exige recifrado y ensayo antes de eliminar material todavía necesario.

## Respaldo portátil

```powershell
./scripts/install-recovery-tools.ps1
./scripts/portable-recovery.ps1 -Action InitKey
./scripts/prepare-compose.ps1
./scripts/portable-recovery.ps1 -Action Daily
./scripts/install-portable-backup-task.ps1
```

Age 1.3.2 Windows/Linux se descarga de su release oficial y verifica SHA-256 fijado. InitKey preserva una clave existente y comprueba su destinatario público. Backup sólo usa el destinatario público; la clave privada `.tools/recovery-keys/age-identity.txt` nunca entra en el archivo cifrado. Las copias están en `.tools/portable-backups/<ID>`: imágenes exactas de la release, SQL canónico, storage privado, firmas AV, datos TLS de Caddy, inventario y credenciales runtime/migrator/operator cifrados. Manifest incluye hashes, IDs de imágenes, commit y estado del árbol; no contiene valores secretos.

La copia portátil sustituye la dependencia de DPAPI para recuperación de la fundación; los snapshots DPAPI previos conservan sólo utilidad local. El responsable confirmó custodia externa del snapshot `20261009T070504Z` y la clave en almacenamiento privado en la nube. La confirmación es del custodio; no se auditó la configuración del proveedor. Mantener la clave separada de los conjuntos de respaldo y transferir los nuevos snapshots al destino de custodia. La tarea local no sincroniza con la nube ni dispone de credenciales del proveedor.

RPO objetivo 24 horas; RTO 4 horas. Tarea `Servicios.Modernizacion.BackupPortable` diaria 02:45, StartWhenAvailable, usuario actual sin password almacenado y privilegio Limited. Requiere sesión interactiva y Docker Desktop activo; revisar LastTaskResult y snapshots después de apagados prolongados. Para servicio productivo desatendido, trasladar la programación a su host/servicio y alertar sobre antigüedad mayor a 24 h. La otra tarea DPAPI se conserva como respaldo local adicional.

Retención 14 días, máximo 30 snapshots completos y presupuesto 40 GiB; se conservan al menos dos. Prune valida rutas dentro de su raíz y excluye directorio de claves; incompletos requieren revisión manual. El presupuesto se comprueba antes/después de crear una copia, no sustituye cuotas de disco del host. Streams de datos no se cargan enteros en memoria; inventario/perfiles tienen límite 16 MiB y SQL línea máxima 16 MiB. Objects/filas mayores requieren exportación específica antes de expandir módulos. Una prueba real cifró y descifró 384 MiB entre Windows/Linux, con igualdad SHA-256 y rechazo de key incorrecta/archivo corrupto.

## Recuperación en frío

1. Recuperar un commit y los lockfiles de Git; instalar Docker/Compose, PowerShell Windows y las herramientas age verificadas.
2. Las copias nuevas incluyen `images.age` con las cuatro imágenes exactas backend/mysql/proxy/antivirus. Restore lo descifra con age Windows verificado y lo importa por streaming antes de iniciar el descifrado Linux de datos; luego valida sus IDs. Las copias anteriores sin images.age requieren recuperar las imágenes exactas externamente. Se rechaza un tag reconstruido con ID distinto. El import de release restaura esos tags locales sin reiniciar los contenedores origen; revisar el snapshot elegido antes de ejecutarlo.
3. Restaurar la carpeta cifrada en `.tools/portable-backups/<ID>` y la clave en su ubicación privada; ejecutar InitKey para verificar el destinatario, sin crear una key sustituta para esa copia.
4. Ejecutar `./scripts/portable-recovery.ps1 -Action Restore -Snapshot <ID>`.
5. Revisar `artifacts/portable-cold-restore-<ID>.json`: equivalencia SQL antes de iniciar worker, inventario de archivos, integridad de perfiles, grants negativos, HTTPS/TLS verificado usando CA recuperada, AV, worker activo con heartbeat nuevo, RPO/RTO y parada final.
6. El ensayo conserva volúmenes/red y detiene únicamente sus contenedores propios; no publica puertos ni reemplaza la base/volúmenes origen. El borrado posterior requiere revisar los nombres/labels de ese ensayo.

El descifrado lo realiza age Linux en contenedor sin red, con key read-only. MySQL y aplicación se crean en volúmenes/red internos nuevos, sin dependencia del perfil DPAPI. La prueba pasada restauró la fundación completa en aproximadamente 73 segundos. `physicalHostChanged=false`: el motor Linux está en el equipo actual; no se atribuye una restauración en otro equipo. Para promoción pública, repetir en el host destino con su DNS/TLS/origin/callback, secreto propio, backups desatendidos, custodia separada y monitorización de capacidad/AV. Esta condición futura no exige adquirir recursos adicionales para implementar Sprint 1.

## Controles posteriores por módulo

Ejecutar `php artisan modernization:verify-identifiers --enable-module=organization` (o residencies/planning/inventory) antes de habilitarlo, usando el perfil migrator que puede inspeccionar todo el schema. El operator tiene visibilidad restringida a IAM/auditoría y no acredita el inventario completo; runtime inspecciona la fundación actualmente habilitada. Convertir catálogo y FKs declaradas/lógicas; el control sólo puede inspeccionar FKs físicas y rutas reconocidas. Las APIs actuales sólo habilitan IAM/Shared. Ver [ADR-012](adr/ADR-012-identificadores-tecnicos-y-baseline.md), [spike OIDC aceptado](SPIKE_OIDC_CIERRE_B09.md) y [checklist vigente](PRE_SPRINT_1_CHECKLIST.md).