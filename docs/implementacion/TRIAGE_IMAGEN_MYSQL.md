# Clasificación de avisos de la imagen MySQL

Evaluado 2026-10-08; reevaluar antes de 2026-11-08 o cualquier cambio de paquete/digest. Responsable técnico: desarrollador actual. Esto clasifica coincidencias del scanner; no certifica cumplimiento FIPS.

La imagen conserva MySQL Community Server 26.7.0. Se actualizaron paquetes de Oracle Linux, se retiró mysql-shell (sin uso por backend, migrador o backups) y se reconstruyó gosu 1.19 desde el commit 6456aaa0f3c854d199d0f037f068eb97515b7513 con Go 1.27.2. Desaparecieron los avisos de Go/Python; quedan tres coincidencias de canal FIPS con epoch 10.

| Aviso | Paquete exacto instalado | Clasificación y evidencia |
|---|---|---|
| ELSA-2026-50075 | openssl y openssl-libs 1:3.5.8-1.0.1.el9_8 | El aviso es del canal security_validation, OpenSSL 3.5.1-7_fips, epoch 10. El paquete general no usa ese epoch. OpenSSL upstream enumera las correcciones de enero en 3.5.5; 3.5.8 es posterior. Changelog instalado confirma rebase a 3.5.8. |
| ELSA-2026-50346 | gnutls 3.8.10-9.el9_8 | El aviso fija 3.8.10-4_fips, epoch 10. Changelog instalado enumera exactamente las correcciones del aviso en release 4; el runtime usa release 9 del canal general. |

Fuentes primarias: [Oracle OpenSSL/FIPS](https://linux.oracle.com/errata/ELSA-2026-50075.html), [Oracle GnuTLS/FIPS](https://linux.oracle.com/errata/ELSA-2026-50346.html), [OpenSSL enero 2026](https://openssl-library.org/news/secadv/20260127.txt), [filtros de Grype](https://oss.anchore.com/docs/reference/grype/configuration/).

`docker/grype-mysql.yaml` aplica únicamente a esos IDs, namespace Oracle Linux 9, tipo rpm, nombre y versión exacta. CI impone fecha de revisión; no excluye severidades ni avisos futuros. Los JSON conservan las tres coincidencias en ignoredMatches y su razón. El reporte original sin clasificación queda como FAIL, no se sobrescribe. Artifacts: mysql-patched-grype-20261008.json, mysql-classified-grype-20261008.json, mysql-package-changelogs-20261008.txt. Los demás avisos medios/bajos permanecen visibles y requieren mantenimiento.

## Revisión 2026-10-09

El build limpio f463ff0 actualizó OpenSSL/openssl-libs al paquete general `1:3.5.8-2.0.1.el9_8`. El filtro anterior sólo reconocía release 1 y CI falló conservadoramente; el reporte FAIL se conserva en artifacts/ci-f463ff0. Se revisó la versión exacta en SBOM/scan y el catálogo oficial de Oracle: [AppStream](https://free.linux.oracle.com/repo/OracleLinux/OL9/appstream/x86_64/index.html). ELSA-2026-50075 sigue siendo la errata de enero para epoch 10/canal security_validation; el paquete instalado lleva epoch 1 y carece del sufijo fips. Upstream documenta las correcciones en 3.5.5; 3.5.8 las incluye. La clasificación de esta coincidencia como cruce de canal es una inferencia de esas fuentes y metadatos, no un resultado de exploit ni una certificación FIPS.

Se añaden únicamente dos entradas exactas para release 2, con el mismo aviso/namespace/tipo/nombre; se conservan las entradas exactas de release 1 para la imagen local previa. No se amplía a otras versiones ni se cambia el umbral High. La expiración permanece 2026-11-08. El gate debe repetirse y cualquier aviso/version nuevo exige revisión independiente.

## Revisión 2026-10-22

El control del Sprint 4 detectó ELSA-2026-500448 sobre `openssl` y `openssl-libs` `1:3.5.8-2.0.1.el9_8`. La [errata oficial](https://linux.oracle.com/errata/ELSA-2026-500448.html) publicada el 20 de octubre identifica exclusivamente el canal `security_validation`, sufijo `_fips` y epoch 10. Su changelog atribuye la corrección de CVE-2026-84782 al release general `1:3.5.8-2`. El changelog del RPM exacto de la imagen instalada confirma esa corrección y se conserva en `artifacts/sprint4-mysql-openssl-changelog.txt`.

Se clasifica como cruce de canal para esos dos paquetes/versiones exactos, por inferencia conjunta del aviso oficial y el changelog instalado; no como aceptación de una vulnerabilidad pendiente ni certificación FIPS. Se añaden sólo dos entradas para ese ID/namespace/tipo/nombre/versión, manteniendo el umbral y la expiración del 8 de noviembre. El FAIL original permanece en el expediente. ELSA-2026-500450 de `ca-certificates` sí requiere actualización real y no se excluye. Los avisos nuevos de PHP/curl y Vite se resuelven con parches, sin exclusiones.

## Revisión 2026-11-13

Reevaluación de la imagen exacta sha256:70aa8729c4db5a3facd400cc9ff03673f1853ad051379208117596848f8165ab. Los RPM instalados son openssl/openssl-libs 1:3.5.8-2.0.1.el9_8 y gnutls 3.8.10-9.el9_8 del canal general. El changelog local de OpenSSL confirma el backport de CVE-2026-84782 en 1:3.5.8-2 y el de GnuTLS conserva las correcciones de 3.8.10-4. Las erratas Oracle ELSA-2026-500448, ELSA-2026-50075 y ELSA-2026-50346 siguen identificando el canal FIPS con epoch 10. La clasificación mantiene el razonamiento y enlaces primarios anteriores.

Grype, con base válida del 2026-11-13T15:40:34Z, produjo cinco coincidencias clasificadas y ninguna coincidencia High/Critical bloqueante. Se retiran las dos reglas del release 1, ausente de la imagen reevaluada. Las cinco reglas exactas se revalidan hasta 2026-12-13; cualquier nuevo aviso o versión queda sujeto al escaneo y al mismo umbral High.


## Revisión 2026-11-24

La imagen candidata sha256:9bd8175a7ef87fa7432a753372f4eba3bc63c2affb4f9bd49625d3a583df9315 actualiza OpenSSL/openssl-libs a 1:3.5.9-1.0.1.el9_9, epoch 1, sin sufijo FIPS. El changelog del RPM instalado confirma el rebase a 3.5.9 y las correcciones históricas de enero. Las [notas oficiales de OpenSSL 3.5.9](https://openssl-library.org/news/openssl-3.5-notes/) incluyen expresamente CVE-2026-84782. Las dos erratas Oracle ELSA-2026-50075 y ELSA-2026-500448 siguen dirigidas al canal security_validation, epoch 10 y sufijo FIPS, según sus enlaces primarios anteriores.

El gate con la base vigente 2026-11-24T06:22:05Z identificó cuatro coincidencias High: ambas erratas sobre ambos paquetes de esa revisión. Se incorporan únicamente cuatro reglas exactas ID/namespace/rpm/nombre/versión, por inferencia conjunta del proveedor y el RPM; no se declara una vulnerabilidad real aceptada ni cumplimiento FIPS. Las reglas de las imágenes anteriores se conservan para su recuperación y la expiración sigue en 2026-12-13. El FAIL previo se conserva sin sobrescribir; el escaneo clasificado debe volver a pasar antes del despliegue. El binario mysqld mantiene SHA-256 e239c96035fa94bf7cbf92c4b4744dcb1b3fa1bac03bd3e289507d3620d64a69, igual al del Sprint 9.
