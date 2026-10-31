# Informe de SIPE V2

Proyecto independiente preparado en `Z:/SIPE-V2`. Repositorio solicitado: [OnlyWhoo/SIPE-V2](https://github.com/OnlyWhoo/SIPE-V2), privado. El repositorio original OnlyWhoo/SIPE no se modificó.

## Reutilización

Se reutilizaron los controladores Application/WorkflowController, modelos Catalog/Database, servicios de autenticación, auditoría, administración, catálogos, reglas, préstamos, flujos y reportes; middleware Security; todas las vistas aprobadas; estilos, JavaScript, logo FADP, QR, dependencias PHP y sus licencias. Las únicas modificaciones visuales son avisos discretos de DEMO local.

Se adaptaron Database para aceptar la versión MariaDB comprobada y Lending para LOCK IN SHARE MODE, conservando bloqueos. Bootstrap y la configuración usan rutas dinámicas y almacenamiento privado fuera de htdocs. La política de contraseñas general permanece igual.

## Desarrollo nuevo

- Installation/Installer: preparación automática, permisos efectivos, bloqueo, propiedad, registro de pasos, reanudación y configuración privada.
- Installation/Schema: comprobación de tablas, tipos, nulabilidad, valores predeterminados, generación, colaciones, índices, CHECK, claves foráneas, vista y conjunto de objetos.
- Installation/Local: localhost, rechazo de proxies y orígenes ajenos, comprobación HTTP de protección Apache y escritura privada atómica.
- Installation/Demo: catálogos, personas ficticias, escenarios y administrador con password_hash, en una transacción.
- setup y runtime: intento convencional automático, formulario mínimo solo cuando hace falta, sesiones privadas y verificación de instalación terminada.
- Esquema consolidado y manifiesto estructural, dos migraciones de origen conservadas, empaquetado por lista permitida y pruebas nuevas del instalador/ZIP.

El profesor copia SIPE-V2 a htdocs, inicia Apache/MySQL, abre localhost/SIPE-V2 y entra con `profesor@sipe.local` / `Sipe2026!`. Si su servidor exige otra conexión, solo completa esos datos en el asistente. No importa SQL, instala programas ni crea administradores manualmente.

El ZIP final está en `dist/SIPE-V2-ENTREGA-FINAL.zip`. Su tamaño y SHA256 exactos se registran en el informe externo de entrega y en el archivo `.sha256` contiguo, evitando incluir dentro del propio ZIP un hash autorreferente. El manifiesto de sus archivos está en docs/SHA256SUMS.txt.

Consulta PRUEBAS.md para resultados ejecutados, límites y el cambio observado en el archivo del MariaDB dañado que estaba activo. No se afirma recuperación ni integridad de esa instalación. La información original de SIPE, incluidos sus archivos de datos y repositorio, permanece idéntica a la copia de control.
