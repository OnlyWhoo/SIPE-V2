# Pruebas de SIPE V2

## Entorno y migración

Pruebas ejecutadas el 18 y 19 de noviembre de 2026 con Apache 2.4.65, PHP 8.3.36 y MariaDB 10.11.16 de XAMPP. Apache atendió localhost en el puerto 80 y MariaDB en el 3306. Las pruebas utilizaron bases aisladas en la misma instancia de XAMPP.

Antes de actualizar `sipe_v2` se restauró un respaldo fuera de la instalación activa y se compararon las sumas de sus 16 tablas. La migración pasó sobre la copia restaurada antes de aplicarse en la base activa. Los dos préstamos y la reserva precargados se identificaron como DEMO por sus marcas y referencias. Se retiraron de la base operativa siguiendo las claves foráneas y se conservaron en el respaldo. El administrador local de demostración recibió una credencial privada nueva. Se mantuvo su identificador de cuenta.

Se cotejaron los 123 prestatarios y los 150 recursos utilizables campo por campo. Se comprobó el código excluido, los ceros iniciales, el correo derivado, los roles, los siete programas, 123 QR únicos, 123 fotos con contenido distinto, la vigencia en DATE y las marcas internas. Las 11 referencias truncadas quedaron pendientes. La ampliación del nombre de recurso a 255 caracteres conservó tres nombres de hasta 233 caracteres.

`mysqlcheck` devolvió OK para las 17 tablas activas. Después de un reinicio de MariaDB se volvió a comprobar el padrón, incluidos los mismos QR y fotos. El primer arranque del reinicio coincidió con el cierre del proceso anterior y se recuperó repitiendo el inicio una vez liberado InnoDB.

## Regresión

| Comprobaciones | Correctas |
| --- | ---: |
| Unitarias | 17 |
| Integración | 37 |
| Flujos y reglas | 42 |
| Concurrencia en reserva y préstamo | 2 |
| Instalador | 12 |
| HTTP en una extracción limpia del ZIP | 35 |

Las pruebas cubrieron identificación por código y QR, hoja de vida, fotos protegidas y actualización de vigencia, solicitud y aprobación docente, autorización estudiantil, préstamo interno y externo, entrega, devolución, vales, reportes, auditoría, CSRF y permisos del empleado. Los casos de concurrencia verificaron una aceptación y un rechazo para cada operación simultánea.

La primera página de la copia extraída creó la base y abrió el inicio de sesión. Se ensayó también el asistente con credenciales MySQL rechazadas y se comprobó el rechazo de un nombre de base preexistente. Se validaron los archivos del ZIP con SHA-256.

Se tomaron capturas antes y después de las vistas editadas y se comprobó la distribución de paneles, formularios, botones y navegación. Las hojas de estilo y los archivos JavaScript conservaron su contenido. Se decodificó el QR mostrado en pantalla y se generó un PDF desde el navegador. El ensayo no utilizó una cámara o impresora física.

## Separación del proyecto original

Los 1.714 archivos de `Z:/SIPE` coincidieron por SHA-256 con el control histórico disponible. Los datos personales, las fotografías asociadas, los tokens QR, las credenciales y los respaldos permanecen en el entorno autorizado de la entrega.
