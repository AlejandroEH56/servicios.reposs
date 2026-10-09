# Responsabilidad y autorización de los cierres

Actualizado: 2026-10-05, America/Mexico_City.

El solicitante designó al desarrollador actual como responsable del proyecto, confirmó que está en desarrollo/migración y autorizó generar los ambientes necesarios, recrear las bases de desarrollo y ejecutar pruebas e instalar herramientas. Esta decisión sustituye los pendientes institucionales de asignación de recursos y responsables del análisis de septiembre.

| Función | Responsable y autoridad |
|---|---|
| Arquitectura, implementación, DBA, IAM, seguridad, frontend y operación | José Alejandro Estudillo Herrera, usuario actual |
| Aceptación funcional, release y adecuación del ambiente productivo | El mismo desarrollador, conforme a la autorización del solicitante |
| Ownership del repositorio | @AlejandroEH56; CODEOWNERS cubre todo el repositorio |
| Evaluación técnica | Pruebas automatizadas y evidencias revisadas por el responsable |

La cuenta conectada fue comprobada con el conector GitHub: coincide con @AlejandroEH56 y tiene permisos admin/maintain/push sobre servicios.reposs. No se afirma revisión independiente por otra persona. La consulta de protección de main devolvió 403 Resource not accessible by integration; se necesita ampliar el alcance del conector o usar una sesión administrativa del propietario.

No se requieren nuevas aprobaciones para las decisiones IAM ya recibidas: grupo autorizado activa, sólo ACTIVA accede, tenant/objectId identifica y las colisiones de correo se rechazan. Las operaciones administrativas se ejecutan desde el perfil operator protegido por ACL; pertenecer al grupo de login no concede acceso al perfil operativo.

El responsable puede organizar la implementación por paquetes y fijar la infraestructura. La autorización no convierte una configuración, una prueba omitida o un artifact de otro SHA en VERIFIED. La promoción requiere evidencias del candidato final.