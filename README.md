# CÓMO EJECUTAR SIPE V2

1. Descomprime `SIPE-V2-ENTREGA-FINAL.zip`.
2. Copia la carpeta **SIPE-V2** dentro de **htdocs** de tu XAMPP.
3. Abre el panel de XAMPP.
4. Inicia **Apache** y **MySQL**.
5. Abre **http://localhost/SIPE-V2/** en ese mismo computador.
6. Si aparece el asistente inicial, indica únicamente la conexión de TU MySQL y pulsa **Preparar y continuar**.
7. Inicia sesión con:

**Correo: `profesor@sipe.local`**  
**Contraseña: `Sipe2026!`**

La base nueva `sipe_v2`, sus tablas, migraciones, datos ficticios y administrador se preparan automáticamente. **No importes SQL, no ejecutes Composer ni crees un administrador manualmente. No necesitas instalar programas.**

La conexión habitual que se intenta es `127.0.0.1:3306`, usuario local `root` sin contraseña, como en un XAMPP nuevo. Si tu servidor tiene contraseña o utiliza otro puerto, el asistente solicita esos datos; no cambia la configuración de tu XAMPP. Si Apache utiliza otro puerto, inclúyelo en la URL, por ejemplo `http://localhost:8080/SIPE-V2/`.

Si la cuenta MySQL no puede crear bases, pide al administrador que autorice la creación de **una base nueva** y el esquema de SIPE V2. El asistente no puede eludir esos permisos ni instalar sobre una base existente desconocida. Una base llamada `sipe_v2` de otra instalación se rechaza: elige un nombre nuevo como `sipe_v2_evaluacion`.

Esta es una **edición DEMO local**, bloqueada para acceso remoto y proxies. No contiene información real. Las credenciales conocidas deben cambiarse o deshabilitarse antes de cualquier uso real; esta distribución no se habilita como aplicación pública cambiando una opción. Un uso real requiere revisión de despliegue y cuentas propias. Los demás usuarios siguen sujetos a contraseñas de al menos 15 caracteres y hasta 72 bytes.

## Objetivo y tecnologías

SIPE V2 — Sistema Independiente de Préstamos Estudiantiles permite evaluar el préstamo institucional de recursos. Conserva la interfaz aprobada y la arquitectura PHP del proyecto original: controladores, servicios, modelos, vistas, HTML5, CSS3, JavaScript, PDO e InnoDB. No añade frameworks ni servidores portables.

## Módulos y guía de utilización

- Panel principal con recursos y movimientos.
- Solicitud de Recursos: identifica `DEMO-001` mediante código o QR; selecciona INTERNO o EXTERNO, consulta recursos, revisa y reserva o entrega.
- INTERNO propone devolución a las 20:00 del mismo día, respetando el horario configurado. EXTERNO exige fecha/hora y permite generar un vale.
- En solicitudes, selecciona un movimiento para cancelarlo, entregar un recurso, generar su vale o registrar la devolución e inspección. Reservar no significa entregar físicamente.
- Hoja de Vida del Estudiante, QR, reportes y filtros, auditoría.
- Administración de estudiantes, programas, categorías, inventario, usuarios, autorizaciones, inspecciones y sanciones.
- Egresados y administrativos requieren aprobación por cada solicitud externa; el rol EMPLEADO conserva sus restricciones.

## Datos DEMO

Hay dos programas, dos categorías, dos estudiantes, un egresado y un administrativo ficticios. Todos los códigos comienzan por DEMO y los correos de personas ficticias usan example.invalid.

`DEMO-CAM-01` inicia prestado; `DEMO-TAB-01`, `DEMO-CAM-02` y `DEMO-MIC-01` están disponibles; `DEMO-TRI-01` tiene una reserva futura. Hay préstamo finalizado, devolución, inspecciones y una sanción ficticia resuelta sin cobro. Las fechas se generan al instalar para que los escenarios sigan siendo útiles. El profesor puede operar inmediatamente sin cargar catálogos.

## Requisitos y compatibilidad

- PHP 8.2+ con pdo_mysql, mbstring, XMLWriter, DOM, session y hash.
- Apache con mod_rewrite, AllowOverride All y posibilidad de consultar por HTTP local su propio puerto. allow_url_fopen debe estar habilitado para esa comprobación de protección.
- MariaDB 10.4+ o MySQL 8.0.16+ como versiones admitidas por el comprobador. **La compatibilidad realmente ejecutada se detalla en docs/PRUEBAS.md; no se certifican versiones sin prueba.**
- Cuenta SQL autorizada para CREATE DATABASE, CREATE TABLE, CREATE VIEW, SELECT, INSERT y UPDATE sobre la base nueva. Para los módulos también se necesita DELETE en autorizaciones; no se requieren privilegios globales sobre otras bases. No se crean ni modifican cuentas del servidor SQL.
- Apache necesita escribir en **sipe-v2-private**, situada junto a htdocs, fuera del directorio público. En Windows restringe sus permisos al usuario de Apache y administradores; no concedas acceso global al disco.

## Errores comunes y recuperación segura

- **MySQL no conecta:** inicia el servicio habitual e indica puerto y credenciales correctos en el asistente. No se presupone acceso si tu servidor está protegido.
- **Sin permiso CREATE:** debe autorizarlo el administrador de ese servidor. No se reemplazan tablas ni se deshabilitan permisos.
- **Base existente desconocida:** usa un nombre nuevo `sipe_v2_*`. No importes ni borres esa base.
- **Instalación interrumpida:** vuelve a abrir la misma URL y, si se solicita, introduce la misma conexión. Se valida el identificador privado, las tablas y los pasos completados; solo se reanudan operaciones identificables y seguras. Los datos DEMO se insertan en una única transacción, sin duplicarlos.
- **Interrupción entre crear la base y su marcador:** la propiedad no se puede demostrar de forma segura; se detiene. Usa otro nombre nuevo o solicita revisión técnica; no se toma posesión de una base por estar vacía.
- **Esquema divergente o archivos de otra versión:** se detiene sin reconstruir tablas. Conserva la base y la carpeta privada; requiere revisión técnica.
- **Configuración privada perdida:** si se conserva el registro privado de propiedad, puede retomarse la finalización. Si se perdió toda la carpeta privada, una base existente se rechaza; restaura una copia privada acreditada con ayuda técnica. No se reabre una creación pública de administradores.
- **Cambio de carpeta:** la configuración privada depende de la ruta absoluta de la aplicación. En una instalación nueva se crea otra identidad. Para conservar una instalación existente debe trasladarse su configuración privada con asistencia técnica.
- **Bloqueo de protección Apache:** el propio programa comprueba respuestas 403 para los directorios internos antes de guardar secretos o preparar datos. Si AllowOverride o mod_rewrite están deshabilitados, detiene la aplicación. No se cambia la configuración global automáticamente.
- **403 remoto:** es el comportamiento previsto de esta edición local. Abre la aplicación directamente desde localhost; no utiliza proxies ni túneles.

## Estructura y documentación técnica

`index.php` es la entrada; `assets/` conserva estilos, JavaScript y logo FADP. `public/index.php` se incluye internamente. `app/` contiene los módulos originales y `Installation/` implementa la instalación. `config/example.php` solo contiene ejemplos públicos. `database/install-plan.json` contiene el esquema consolidado con su firma estructural; `database/schema.sql` es referencia y `database/source/` conserva el esquema y las dos migraciones de origen. No deben ejecutarse aparte. `vendor/` incluye dependencias y licencias. `.htaccess` bloquea todos los directorios internos.

La conexión, el identificador de propietario, las sesiones y los logs se guardan fuera de htdocs, en una subcarpeta de `sipe-v2-private`. El ZIP no transporta ese estado. Nunca copies esa carpeta privada para distribuir el proyecto.

La instalación combina bloqueo de archivo, GET_LOCK del servidor, marcador de propietario, hash del plan, registro de pasos y verificación de columnas, índices, CHECK, claves foráneas, vista y objetos esperados. El DDL de MariaDB 10.4 no es transaccional: no se promete rollback de crear tablas. Al reanudar, una tabla ya creada se valida en lugar de ejecutar otra vez su DDL. No se ejecutan DROP, TRUNCATE, reemplazos de tablas ni migraciones destructivas. Las migraciones 001/002 ya están consolidadas y no se aplican dos veces.

La política de contraseñas general no cambia. La excepción corta existe únicamente al sembrar la cuenta DEMO, guardando `password_hash()`, nunca texto plano en la base. Toda esta edición exige origen y cliente local, CSRF para formularios, cookies HttpOnly/SameSite y consultas parametrizadas.

Consulta `docs/PRUEBAS.md` y `docs/INFORME.md` para resultados, procedencia y límites. Las pruebas de desarrollo no son necesarias para el profesor y no se incluyen en el ZIP.
