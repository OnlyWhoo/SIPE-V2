# Pruebas de SIPE V2 — 30 de octubre de 2026

Entorno ejecutado: Windows, PHP 8.2.12, Apache 2.4.58 y MariaDB 10.4.32, utilizando exclusivamente binarios ya existentes de XAMPP. Los procesos de laboratorio utilizaron configuración y datos nuevos dentro de var/runtime, con Apache en 127.0.0.1:8085 y MariaDB en 127.0.0.1:3312. No se registraron servicios ni se cambió configuración global de XAMPP.

## Resultados

- 117 archivos PHP de aplicación, entrada interna y dependencias: sin errores de sintaxis.
- 17 pruebas unitarias: 0 fallos. Mantienen la política general de contraseñas, CSRF, roles y validaciones.
- 37 pruebas de integración: 0 fallos. Autenticación, sesiones, permisos SQL limitados, administrador concurrente, catálogos, autorizaciones, reservas, préstamos, devoluciones, inspecciones, sanciones, auditoría y QR.
- 37 pruebas de reglas y flujos: 0 fallos. Incluyen horario interno a las 20:00, externos con fecha/hora, disponibilidad y aprobaciones por solicitud para egresados y administrativos.
- Dos pruebas de concurrencia: exactamente una aceptación y un rechazo por reserva y préstamo, sin filas duplicadas.
- 12 comprobaciones del instalador: 0 fallos. Guard local, rechazo de parámetros peligrosos, base ajena intacta, cinco interrupciones deliberadas, propietario incorrecto, esquema divergente, conservación de identidades al cambiar el nombre pendiente y cuenta sin CREATE.
- Las cinco interrupciones se provocaron tras crear el marcador, tras crear una tabla y antes de registrar su paso, con esquema completo, dentro de la transacción de DEMO y antes de guardar la configuración. Reanudar creó una sola cuenta, una reserva y dos préstamos; volver a instalar sobre la configuración completada fue rechazado.
- Cuatro restricciones SQL comprobadas mediante escrituras inválidas reales y rollback: CHECK de activo, FK inexistente, CHECK de duración y préstamo activo duplicado.

## Prueba real del ZIP

Se construyó el ZIP y se extrajo en carpetas nuevas de htdocs del Apache aislado. La aplicación extraída usó únicamente su propio código y dependencias; no carga archivos del proyecto original ni del árbol de desarrollo. Playwright y Edge ya disponibles se utilizaron solamente para automatizar las pruebas, no forman parte de la aplicación ni se requieren en destino.

Se completaron 18 comprobaciones de navegador: asistente ante servidor protegido, rechazo de CSRF, origen ajeno y Host falso, rechazo de base ajena, explicación de permisos CREATE, instalación automática sin importar SQL, login DEMO exacto, todos los módulos, QR, protección real de archivos, bloqueo con AllowOverride None, reserva, cancelación, entrega externa, vale, devolución, reporte y logout sin reinstalación.

También se verificó la ruta totalmente automática: el primer GET de otra copia limpia creó esquema, datos y administrador, redirigió al login y permitió entrar y salir sin mostrar formulario. Para mantener el laboratorio aislado, sus valores iniciales fueron proporcionados mediante variables del Apache de pruebas y apuntaron a 3312. En una instalación normal sin esas variables, los valores incluidos siguen siendo 127.0.0.1:3306, root sin contraseña y sipe_v2. No se ejecutó una instalación nueva sobre el servidor original de 3306.

Se reiniciaron únicamente los procesos aislados de Apache y MariaDB. Las 16 tablas de la instalación conservaron exactamente sus cantidades y hashes; persistieron la cuenta DEMO hasheada, el marcador y las operaciones web. Después del reinicio se verificaron login, movimientos, logout y ausencia del asistente. Las tablas internas global_priv, db y transaction_registry del laboratorio pasaron CHECK TABLE.

Se detectó durante el desarrollo que Referrer-Policy no-referrer hacía que Edge enviara Origin null en formularios. Se cambió a same-origin, conservando el rechazo de orígenes ajenos y los tokens CSRF. Las pruebas posteriores pasaron.

## Compatibilidad y límites

La edición se comprobó en MariaDB 10.4.32, PHP 8.2.12 y Apache 2.4.58. MySQL 8.0.16+ se admite en el comprobador, pero **esta edición no se ejecutó sobre MySQL 8 y no se certifica su compatibilidad**. Tampoco se certifican otras versiones sin prueba.

No se probaron cámara ni impresora físicas. Se verificaron QR SVG, identificación por token y vale imprimible. No se certifica despliegue remoto, HTTPS con certificados locales no confiables, proxies o servidores que impidan la comprobación HTTP de protección: esta edición falla de forma cerrada en esos casos.

## Separación del original

El código, configuración, repositorio y archivos de datos de Z:/SIPE se compararon con una copia de control: 1714 archivos idénticos mediante SHA256. MySQL original de 3307 no se inició ni se modificó. Los 191 archivos de los respaldos de recuperación examinados permanecen idénticos.

En el MariaDB dañado de XAMPP no se ejecutaron restauraciones, reinicializaciones, reparaciones, DDL ni modificaciones de cuentas. Durante la comprobación final se observó que ya estaba activo un proceso ajeno al laboratorio en 3306, iniciado a las 22:49:11, antes de este trabajo. Su archivo mysql/db.MAD cambió de hash durante la sesión. No se atribuyó la causa, no se revirtió el archivo y no se detuvo ese proceso; **no se certifica inmutabilidad física de ese directorio activo**. Las primeras aperturas del asistente probaron la conexión local predeterminada; las ejecuciones finales de laboratorio dirigieron todos sus intentos iniciales a 3312. La recuperación de ese MariaDB sigue siendo una tarea independiente.

## Referencias técnicas

La reanudación considera los commits implícitos del DDL de MariaDB 10.4: [documentación oficial de DDL](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit). La cuenta DEMO utiliza [password_hash de PHP](https://www.php.net/password-hash). La instancia nueva de laboratorio se inicializó con la utilidad oficial de Windows [mysql_install_db / mariadb-install-db](https://mariadb.com/docs/server/server-management/install-and-upgrade-mariadb/installing-mariadb/installing-system-tables-mariadb-install-db/mariadb-install-db-exe), con directorio nuevo y sin registrar servicios.
