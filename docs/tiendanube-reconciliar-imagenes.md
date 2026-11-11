# Reconciliar fotos de Tienda Nube

El alta, la actualización y los jobs de carga posterior usan el mismo sincronizador. Las fotos ya existentes se publican al crear el producto sin depender de `CARGAR_IMAGENES_DE_ARTICULOS_A_TIENDA_NUBE`. La cola sólo declara éxito tras confirmar las imágenes. Los campos de precio, stock y características conservan su cálculo actual.

## Inventario histórico acotado, sólo lectura

Antes de correr una reparación, confirmar la base activa, el dueño real de sus artículos y el conector. El ID del cliente en el admin no es el ID del dueño en Empresa. Por ejemplo, el cliente 3DTisk tiene ID 38 en el admin: **no usar 38 como `--usuario` por esa razón**. El artículo del ticket 98 es 1295, pero el dueño debe consultarse.

Esta consulta parametrizada produce candidatos; un marcador vacío **no demuestra que falte la foto en Tienda Nube**. Seleccionar y revisar como máximo 100 artículos por corrida. También revisar los candidatos con marcadores presentes si el dueño reportó una foto faltante.

```sql
SELECT a.id, a.user_id, a.name, a.tiendanube_product_id,
       i.id AS image_id, i.hosting_url, i.tienda_nube_image_id, i.tiendanube_image_id
FROM articles a
JOIN images i ON i.imageable_id = a.id AND i.imageable_type = 'article'
WHERE a.user_id = :dueno AND a.tiendanube_product_id IS NOT NULL
  AND (i.tienda_nube_image_id IS NULL OR i.tiendanube_image_id IS NULL
       OR i.tienda_nube_image_id <> i.tiendanube_image_id)
ORDER BY a.id, i.id
LIMIT 100;
```

## Simulación y aplicación explícita

Ejemplos ilustrativos para un dueño 7 y artículos 1295 y 1300, parado en la carpeta de la API. Reemplazar los números con el alcance confirmado. El directorio de evidencia debe existir y el archivo debe ser nuevo.

```powershell
php artisan tiendanube:reconciliar-imagenes --usuario=7 --articulos=1295,1300 --limite=20 --evidencia=C:\respaldo\tn-fotos-simulacion.jsonl
```

La simulación consulta el inventario remoto completo, la accesibilidad del archivo local y coincidencias por marcador, URL exacta o SHA-256 del archivo. No guarda marcadores ni envía POST/PUT/DELETE. Las descargas son por streaming, menores a 10 MB y sin enviar el token de Tienda Nube al hosting de fotos. No sigue redirecciones automáticamente.

Si hay una foto remota sin identificar, no se deduce ausencia de una local: Tienda Nube puede transformar tamaño/formato y cambiar su hash. Esos casos quedan **ambiguos** para revisión humana, al igual que marcadores discordantes, dos locales apuntando a una remota o varias coincidencias. No borrar/recrear productos ni reenviar todo el catálogo para resolverlos.

La aplicación se ejecuta solamente en el carril operativo autorizado y la ventana aceptada por el negocio, con respaldo operativo verificado y control de actividad. El comando guarda y relee su respaldo antes de escribir. Un candado por base, tienda y producto serializa la reparación con las sincronizaciones y el borrado de fotos.

```powershell
php artisan tiendanube:reconciliar-imagenes --usuario=7 --articulos=1295,1300 --limite=20 --aplicar --evidencia=C:\respaldo\tn-fotos-aplicacion.jsonl
```

La ejecución vuelve a medir el remoto dentro del candado: no aplica ciegamente la simulación anterior. Guarda ambos marcadores compatibles con el mismo ID confirmado. Cada POST se hace una sola vez y luego se confirma por GET; si se pierde la respuesta, la siguiente corrida reconcilia antes de volver a subir. Si Tienda Nube transformó esa imagen y no se puede identificar, se detiene como ambigua para evitar duplicados.

El archivo JSONL registra alcance, inventario anterior y posterior. Si falla luego de una carga parcial, registra los marcadores locales resultantes y una consulta del remoto; si ésta falla, queda explícita la verificación posterior pendiente. Conservar el archivo y revisar el artículo afectado antes de reintentar. Código de salida 1 significa error o ambigüedad, nunca éxito completo.

El comando no cambia precio/stock, no borra productos o fotos y no marca resuelto un ticket. No está programado en el scheduler ni se ejecuta automáticamente al desplegar. Repetir con un archivo nuevo es seguro para los casos verificables. Los casos ambiguos requieren resolución específica respaldada por evidencia.
