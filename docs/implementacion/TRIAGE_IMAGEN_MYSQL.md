# Clasificación de avisos de la imagen MySQL

Evaluado 2026-10-08; reevaluar antes de 2026-11-08 o cualquier cambio de paquete/digest. Responsable técnico: desarrollador actual. Esto clasifica coincidencias del scanner; no certifica cumplimiento FIPS.

La imagen conserva MySQL Community Server 26.7.0. Se actualizaron paquetes de Oracle Linux, se retiró mysql-shell (sin uso por backend, migrador o backups) y se reconstruyó gosu 1.19 desde el commit 6456aaa0f3c854d199d0f037f068eb97515b7513 con Go 1.27.2. Desaparecieron los avisos de Go/Python; quedan tres coincidencias de canal FIPS con epoch 10.

| Aviso | Paquete exacto instalado | Clasificación y evidencia |
|---|---|---|
| ELSA-2026-50075 | openssl y openssl-libs 1:3.5.8-1.0.1.el9_8 | El aviso es del canal security_validation, OpenSSL 3.5.1-7_fips, epoch 10. El paquete general no usa ese epoch. OpenSSL upstream enumera las correcciones de enero en 3.5.5; 3.5.8 es posterior. Changelog instalado confirma rebase a 3.5.8. |
| ELSA-2026-50346 | gnutls 3.8.10-9.el9_8 | El aviso fija 3.8.10-4_fips, epoch 10. Changelog instalado enumera exactamente las correcciones del aviso en release 4; el runtime usa release 9 del canal general. |

Fuentes primarias: [Oracle OpenSSL/FIPS](https://linux.oracle.com/errata/ELSA-2026-50075.html), [Oracle GnuTLS/FIPS](https://linux.oracle.com/errata/ELSA-2026-50346.html), [OpenSSL enero 2026](https://openssl-library.org/news/secadv/20260127.txt), [filtros de Grype](https://oss.anchore.com/docs/reference/grype/configuration/).

`docker/grype-mysql.yaml` aplica únicamente a esos IDs, namespace Oracle Linux 9, tipo rpm, nombre y versión exacta. CI impone fecha de revisión; no excluye severidades ni avisos futuros. Los JSON conservan las tres coincidencias en ignoredMatches y su razón. El reporte original sin clasificación queda como FAIL, no se sobrescribe. Artifacts: mysql-patched-grype-20261008.json, mysql-classified-grype-20261008.json, mysql-package-changelogs-20261008.txt. Los demás avisos medios/bajos permanecen visibles y requieren mantenimiento.
