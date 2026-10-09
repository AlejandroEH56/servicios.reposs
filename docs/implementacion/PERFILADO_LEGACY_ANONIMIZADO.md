# Alcance aprobado — 2026-10-08

El responsable confirmó migrar funcionalidades con datos nuevos. [ADR-013](adr/ADR-013-migracion-funcional-con-datos-nuevos.md) elimina la búsqueda de dumps como pendiente. Se conserva abajo la evidencia de la inspección física realizada; no se inventan volúmenes de un histórico que no se transfiere.

# Resultado del perfilado legacy

Ejecutado: 2026-10-04 23:42 America/Mexico_City (2026-10-05T05:42:05Z). Responsable: @AlejandroEH56. Fuente: C:/GitHubRepositories/servicios.proyecto.

modernization:profile-legacy inspeccionó la configuración CodeIgniter sin ejecutarla ni publicar credenciales. Sólo realizó consultas de lectura en el MySQL local configurado para la migración.

Los grupos residentes, compartida, laboratorios e inventarios apuntan respectivamente a reposs, compartida, laboratorios e inventarios. No se encontraron tablas físicas en ninguno de esos cuatro esquemas del servidor local. Por ello no existen DDL/volúmenes/duplicados/huérfanos verificables de datos legacy en este ensayo. Un esquema reconstruido del código o con fixtures no sustituye la evidencia de un origen histórico real.

Artifact: artifacts/legacy-profile/manifest.json, con fecha, versión y estados por esquema. SHA-256 de app/Config/Database.php inspeccionado: bb8be5139102b4404385e6b545088632842c926fb612d4be69b0a9cc35b0a214. No se publican filas, nombres de personas, correos, contraseñas ni grants administrativos.

Siguiente secuencia: localizar un dump autorizado o restaurar una copia de las bases legacy en esos esquemas; repetir el comando desde el perfil administrativo local; conservar DDL/checksums y conteos anonimizados; añadir reconciliación/FK y perfilar cambios/reciclaje de correo. Si no existe origen histórico, formalizar como migración de funcionalidades desde código y datos nuevos, sin presentar perfilado de históricos inexistentes como PASS.

El provisioning moderno sigue separado del legacy y enlaza sólo tenant/objectId. Las colisiones de contacto se deniegan; un operador revisa titularidad mediante identidad externa, autoriza un contacto alternativo y audita la corrección. No se reasigna un vínculo por correo ni se fusionan cuentas. Falta la operación administrativa completa de corrección de contacto/reconciliación.