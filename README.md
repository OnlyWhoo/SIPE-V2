# SIPE V2

Sistema Independiente de Préstamos Estudiantiles de la Fundación Academia de Dibujo Profesional. La entrega académica se ejecuta en localhost con Apache, PHP 8.2 o superior y MariaDB 10.4 o superior de XAMPP. Se verificó con PHP 8.3.36 y MariaDB 10.11.16 en el XAMPP existente.

## Inicio para el profesor

1. Extrae el ZIP privado en `htdocs` de XAMPP. Debe quedar `htdocs/SIPE-V2/index.php`.
2. Inicia Apache y MySQL en el panel de XAMPP.
3. Abre [SIPE V2 en localhost](http://localhost/SIPE-V2/). La primera visita crea el esquema, carga los datos de la entrega y abre el inicio de sesión.
4. Ingresa con las credenciales de evaluación entregadas por separado.

MariaDB usa `127.0.0.1:3306` de forma predeterminada. Si requiere credenciales o si `sipe_v2` ya existe en ese equipo, el asistente local permite indicar una cuenta MySQL autorizada y el nombre de una base nueva, como `sipe_v2_evaluacion`. La cuenta SQL necesita permisos para crear el esquema y operar SIPE V2. Los reinicios conservan la base y los datos.

PHP debe tener habilitadas `pdo_mysql`, `mbstring`, `xmlwriter`, `dom`, `session` y `hash`. Apache debe respetar `.htaccess` en la carpeta de SIPE V2 y tener `mod_rewrite` habilitado. Si Apache usa un puerto distinto de 80, indícalo en la URL. El ZIP ya contiene las dependencias PHP y el esquema. La instalación se completa desde el navegador.

## Uso de la entrega

- En **Solicitud de Recursos** identifica al prestatario por el código institucional o mediante el lector QR. Desde la ficha, **+ Solicitar Recurso** lleva al mismo flujo con la persona seleccionada.
- En **Hoja de Vida del Usuario** consulta datos del prestatario, vigencia, QR, autorizaciones y movimientos. Puedes abrir la imagen del QR en otra pestaña e imprimirla desde el navegador.
- En **Usuarios de préstamos**, un administrador puede editar la cédula, la fecha de vigencia del carné o reemplazar la fotografía en el formulario existente. El campo de programa permite actualizar una asignación docente.
- En **Autorización académica**, el administrador configura las categorías habilitadas para cada programa. La entrega conserva la regla previa de autorización estudiantil. El Excel aporta carreras e inventario. Las autorizaciones nuevas se registran en ese panel conforme a la información institucional.
- **Estudiantes.** Solo pueden reservar o recibir recursos de categorías autorizadas para su programa. SIPE comprueba esa regla al revisar y al registrar el préstamo. Las autorizaciones se configuran en **Autorización académica** y se muestran en la hoja de vida.
- **Docentes y administrativos.** Pueden reservar o recibir recursos de cualquier categoría activa. La carrera del docente es informativa. Para los tres grupos, la reserva o la entrega se registra desde la pantalla de confirmación si el recurso y el prestatario están activos y se cumplen los controles de estado, disponibilidad, horarios y fechas.

### Solicitudes pendientes históricas

El estado **Solicitudes pendientes históricas** del panel permite localizar trámites anteriores que no ocupan horario. Revisa cada solicitud con su responsable. Si se descarta o se sustituye, selecciónala y usa **Cancelar Solicitud**. La cancelación conserva el registro y se audita. Cuando el recurso todavía se requiera, ingresa una reserva nueva con sus datos y valida la disponibilidad actual. Las aprobaciones registradas previamente siguen en el historial.

### Sanciones

1. Abre **Sanciones** con una cuenta administradora. En **Buscar usuario por código institucional**, escribe el código completo y pulsa **Buscar**. La ficha muestra el nombre, el código, el grupo y el estado en SIPE.
2. En **Seleccionar préstamo relacionado**, elige el movimiento por recurso, referencia, fechas y estado. La lista solo contiene préstamos del usuario encontrado e indica si hubo retraso.
3. Selecciona el tipo, escribe el fundamento y la evidencia, y registra la sanción. Para **Mora** el préstamo es obligatorio, el retraso debe superar 24 horas y el fundamento debe citar el reglamento o la política aplicable. Para **DAÑO** y **Otra**, el préstamo es opcional.
4. Usa **Resolver o anular** para documentar la decisión y consulta el historial en la misma página.

Una sanción **ABIERTA** bloquea nuevas solicitudes, reservas y entregas, incluida la entrega de una reserva anterior. La identificación muestra **BLOQUEADO POR SISTEMAS** y deshabilita **INGRESAR**. El prestatario conserva el acceso a su historial y puede devolver recursos. Cuando deja de tener sanciones abiertas, recupera el acceso al flujo de préstamos con su mismo estado de cuenta.

## Procedencia de los datos

La entrega contiene 123 prestatarios, siete programas y 150 recursos con referencias utilizables. Otras 11 referencias quedan pendientes de confirmación en el inventario de origen incluido como parte de los datos protegidos de instalación.

Los correos se derivaron del código institucional en `fadp.edu.co`. Su existencia como buzones no se ha verificado. Las 123 fotografías son ilustrativas y están asociadas de forma estable. La fecha inicial del carné, 31/12/2026, fue asignada para la entrega. Las 21 clasificaciones 50/50 conservan una marca interna y las 21 asignaciones de programa docente figuran como provisionales.

El ZIP privado contiene el padrón autorizado y se comparte únicamente por el canal académico restringido. El código del repositorio puede instalar un esquema vacío con una cuenta de administración elegida mediante el asistente local. El padrón, inventario completo, fotos y credenciales de evaluación se distribuyen en la entrega privada.

## Pruebas

Las comprobaciones y el alcance del ensayo se describen en [Pruebas de la entrega](docs/PRUEBAS.md).
