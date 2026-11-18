# Reparación controlada de Total facturado

Origen: soporte #145, San Cayetano, 18/11/2026. La venta visible 51362 (ID interno 51381) tiene
un acumulado de 23000 y un único comprobante autorizado de 11500 según el diagnóstico. Este
documento prepara la operación; no certifica que el código esté instalado ni que esa evidencia
siga vigente al momento del corte.

El comando `soporte:reparar-total-facturado` es manual. No se agenda, no es una migración y no
debe cargarse como un comando automático para todos los clientes en el release. Solo cambia
`sales.total_facturado`. Conserva `updated_at`, documentos fiscales, caja, stock y cuenta corriente.
No hay que emitir otra factura ni una nota de crédito para corregir este acumulado local.

## Antes de escribir

1. Instalar la versión que contiene la corrección por el carril operativo y comprobar el frente
   activo, negocio y versión. El ticket debe tener la pausa confirmada y la ventana autorizada;
   siguen vigentes los controles de actividad, compatibilidad y respaldo de la operación.
2. Leer el ID del usuario **dueño** de la instalación y el ID interno de cada venta. `--ventas`
   recibe `sales.id`, no el número visible `sales.num`. Se exige una selección explícita de hasta
   100 ventas del mismo dueño. No se infiere el negocio ni se recorre toda la base.
3. Crear una carpeta privada fuera de `public` y `storage/app/public`, con permisos del usuario
   que corre artisan. El respaldo debe ser un archivo `.json` nuevo, con ruta absoluta. Se
   conserva con su archivo `.auditoria.jsonl`. No se descarga a una ubicación pública ni se envía
   al cliente.
4. Ejecutar la simulación sin `--aplicar`. Solo toma candados de lectura/transacción, lee el
   estado actual y muestra JSON sanitizado: ID interno, número visible, importe anterior,
   destino, moneda, comprobantes y huella SHA-256. No escribe datos ni archivos.

Desde la raíz de la instalación, reemplazar `ID_DUENO` por el ID verificado del dueño:

```powershell
php artisan soporte:reparar-total-facturado --negocio=ID_DUENO --ventas=51381
```

Si la evidencia original sigue vigente, el destino de esa venta debe ser `11500.00`. Revisar el
único comprobante vigente, sus importes, moneda y las notas de crédito. Un estado `revision` no
se fuerza: se investiga su motivo. Tampoco se suman filas duplicadas ni se adivinan monedas.
El comando sale con código 1 si cualquier venta de la selección requiere revisión; no modifica
ninguna de ellas.

## Aplicación y comprobación

Guardar la salida de la simulación como evidencia operativa, revisar su selección y copiar su
`huella`. En la misma ventana autorizada ejecutar con esa huella y una ruta privada:

```powershell
php artisan soporte:reparar-total-facturado --negocio=ID_DUENO --ventas=51381 --huella=SHA256_REVISADO --respaldo='C:\ruta-privada\145-total-facturado.json' --aplicar
```

Las rutas del servidor Linux se escriben con su ruta absoluta real (por ejemplo bajo una carpeta
privada del usuario de la instalación); no copiar una ruta de Windows al servidor.

La aplicación vuelve a leer las ventas y sus comprobantes bajo candados, compara la huella,
crea el respaldo de forma exclusiva y comprueba su contenido y sello con una relectura. Recién
después escribe la columna seleccionada y vuelve a verificar el estado antes y después del commit,
manteniendo el candado fiscal. Si
algo cambió después de la simulación, no escribe y pide volver a simular. El respaldo contiene
los importes anteriores, destino y huellas de la venta, comprobantes e instalación; no contiene
CAE, CUIT, request ni response.

Repetir exactamente la aplicación con el mismo respaldo y la huella original es idempotente.
No reemplazar un respaldo existente ni borrar su auditoría para intentar forzar el comando.
Una huella SHA detecta cambios de evidencia; no reemplaza la revisión ni la autorización de la
ventana. La auditoría distingue `preparado` de `verificado_en_transaccion`: si el proceso se corta,
el resultado real se determina releyendo la base y repitiendo la simulación, no por una línea
aislada de log. `confirmado_y_releido` registra la comprobación posterior al commit. Si esa
comprobación falla, el comando informa que la escritura ya fue confirmada y requiere revisar el
estado real; no declara verificada la reparación ni promete haberla revertido.

Después del commit, verificar con el perfil afectado el dato corregido y el flujo original:
consultar repetidamente la misma factura debe conservar el acumulado, el número y la cantidad
de comprobantes. Confirmar que caja, stock, cuenta corriente y documentos fiscales no cambiaron.
El ticket continúa abierto hasta esa verificación y la prueba posterior del negocio.

## Reversión

La reversión usa el respaldo original, la misma selección y la misma huella informada al
aplicar. Primero simular:

```powershell
php artisan soporte:reparar-total-facturado --negocio=ID_DUENO --ventas=51381 --respaldo='C:\ruta-privada\145-total-facturado.json' --revertir
```

Con la revisión y ventana operativa vigentes, agregar `--aplicar` y la huella original:

```powershell
php artisan soporte:reparar-total-facturado --negocio=ID_DUENO --ventas=51381 --huella=SHA256_ORIGINAL --respaldo='C:\ruta-privada\145-total-facturado.json' --revertir --aplicar
```

Solo restaura los importes respaldados cuando la venta y su evidencia fiscal siguen siendo las
mismas y el acumulado coincide con el original o el reparado. Repetirla es idempotente. Un
comprobante nuevo, un cambio en la venta, otra base/host/instalación, otro dueño, un respaldo
alterado o un acumulado diferente bloquean la reversión para no pisar trabajo posterior. La
reversión no es una solución a una diferencia fiscal: requiere el diagnóstico correspondiente.
