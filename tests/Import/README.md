# Tests de importación de Excel de artículos

Suite de integración de `ArticleController@import` (importación de artículos por Excel).

## Requisitos del entorno

- `phpunit.xml` apunta a la base `empresa_test`.
- `empresa_test` tiene el tenant de prueba (id = `ImportTestCase::TENANT_USER_ID`) SIN artículos propios.
- `QUEUE_CONNECTION=sync`, así el `Bus::chain` de la importación corre inline dentro del POST y no hace falta ningún `queue:work`.
- Todas las tablas de `empresa_test` en motor `InnoDB` (ver `ImportTestCase::verificar_motor_innodb()`). Sobre `MyISAM`, `DatabaseTransactions` no revierte nada y la suite mide basura sin avisar.

## Fixtures

Los archivos `.xlsx` se generan con:

```
php tests/Import/fixtures/generar.php
```

El script (`tests/Import/fixtures/generar.php`) es la documentación viva de qué hay en cada celda y, sobre todo, de qué **tipo** es cada celda (string PHP = celda de texto, pasa por el parseo; float/int = celda numérica, lo saltea).

Los fixtures `12_` a `17_` (hojas múltiples, cabecera fusionada, encabezado corrido, `.xls` viejo, lista de proveedor realista) se generan con **otro** script:

```
php tests/Import/fixtures/generar_hojas_y_fusiones.php
```

Son dos generadores y no uno a propósito: `generar.php` escribe con **OpenSpout**, que no sabe escribir celdas fusionadas ni el formato `.xls` viejo (BIFF), que es justamente lo que esos cinco fixtures necesitan. `generar_hojas_y_fusiones.php` escribe con **PhpSpreadsheet**. Mezclarlos en un solo archivo obligaría a cambiarle la librería a los once fixtures viejos. Ninguno de los dos toca los archivos del otro.

⚠️ En PhpSpreadsheet hay que ser **explícito con el tipo de celda**: `setCellValueExplicit($ref, '7790101', DataType::TYPE_STRING)` para el texto con contenido numérico, y `setCellValue()` a secas para las numéricas. `setCellValue('7790101')` adivina y lo guarda como número.

| Archivo | Filas | Qué cubre |
|---|---|---|
| `01_codigos_de_proveedor.xlsx` | 7 | Matriz de configuraciones de `provider_code` (único, duplicado, cruzado entre proveedores, nuevo, placeholders `S/N` y `-`). F2 y F3 llevan además un sku nuevo (grupo 265, prompt 10): `RepetidosEnElArchivoTest.php` las reusa para probar la herencia de sku de la cascada contra un match único y contra un match múltiple. |
| `02_codigos_de_barra_repetidos.xlsx` | 7 | Códigos de barra repetidos dentro del archivo y ya duplicados en base. |
| `03_numeros_y_costos.xlsx` | 12 | Formatos de costo (separador de miles, moneda, decimales, notación fuera de rango, no numérico). |
| `04_stock.xlsx` | 6 | Deltas de stock, columna vacía, texto numérico, artículo sin proveedor indexado. |
| `05_rollback.xlsx` | 5 | Snapshot de rollback: edita artículos existentes y crea nuevos. |
| `06_incidente_servian.xlsx` | 50 | Regresión del incidente del 24/07/2026. Se importa con `ARTICLE_EXCEL_CHUNK_SIZE = 10` para forzar 5 lotes: placeholders repartidos entre lotes, bar_code repetido en lotes distintos, provider_code compartido, nombres idénticos, códigos numéricos y costos decimales. |
| `07_cadena_sobre_articulo_existente.xlsx` | 3 | Cadena de conflictos `fila_sobrescrita` sobre un artículo YA EXISTENTE en base: cada repetición reporta contra la fila inmediatamente anterior, no siempre contra la primera. |
| `07_repetidos_en_el_archivo.xlsx` | 9 | Repetidos DENTRO del propio archivo (no en base): jerarquía SKU, generalización de "última fila gana" a sku/provider_code, reporte de sobrescritura y flag `filas_repetidas_del_archivo`. |
| `09_cascada_herencia.xlsx` | 6 | Cascada con herencia de identificadores (grupo 265, prompt 08 + fix del grupo 285): bar_code nuevo que hereda vía sku, herencia doble (bar_code y sku) vía provider_code único, conflicto `identificador_sin_asignar` sobre provider_code repetido con el campo bar_code, y la guarda `identificadores_asignados_en_chunk` (dos filas del mismo chunk que quieren heredar el mismo bar_code nuevo a dos artículos distintos). |
| `10_escalon_nombre.xlsx` | 5 | El escalón `name` como punto de llegada de la cascada: match único por nombre, nombre ambiguo, herencia de un bar_code pendiente hasta el escalón 5, el corte de cadena cuando un `provider_code` no matchea (no baja a name), y la normalización de `normalize_name_for_match()` (mayúsculas/espacios). |
| `12_tres_hojas.xlsx` | 7 (hoja 0) | **Tres hojas**: `"Lista de precios"` (8 filas), `"Notas"` (3 filas de texto libre, sin forma de tabla de artículos a propósito, para que elegir mal se note) y `"Resumen"` (2 filas). Es el fixture que prueba que el `break` de "siempre la primera hoja" murió. |
| `13_cabecera_fusionada.xlsx` | 4 | Una hoja con `E1:F1` **fusionada** y `"PRECIOS"` en E1 (F1 vacía en el XML). Las 4 filas de datos tienen E (costo) y F (precio) llenas: es el caso caro de T3, dos propiedades del sistema debajo de un mismo nombre de columna. |
| `14_encabezado_corrido.xlsx` | 5 | Una hoja con título **y fecha de vigencia** en la fila 1, razón social **y CUIT** en la 2, la 3 vacía y el **encabezado real en la fila 4**. El defecto 3. ⚠️ El CUIT y la fecha no son decorado: sin ellos el fixture pasaba el test mientras la regla se caía con cualquier lista de proveedor real (el corte por fila de datos se disparaba con dos celdas si una era numérica). Y van sobre filas que **ya tenían contenido** a propósito: `AnalyzerHojaYEncabezadoTest` asierta números de fila físicos (5 a 9) y el conteo de la rama vieja (7), así que agregar un renglón nuevo los correría. |
| `17_lista_proveedor_real.xlsx` | 4 | **El caso completo, el que se ve en cámara al filmar la demo**: título **fusionado** sobre A1:F1, razón social con CUIT en la 2, `"Vigencia desde:"` con una celda de **fecha real** en la 3, y el encabezado en la fila 4 **corrido y además fusionado** (`E4:F4` con `"PRECIOS"` en E4). Los tres rasgos rompían la regla por motivos distintos y cada arreglo desactivaba al otro; medido antes de arreglarlo daba `fila=1, sin_candidata_clara`, con lo cual el mapeo se armaba con cinco columnas vacías y se importaban la razón social y el encabezado como si fueran artículos. |
| `15_una_sola_hoja.xlsx` | 7 | El fixture de **no regresión**: mismo contenido celda por celda que `01_codigos_de_proveedor.xlsx`, pero escrito con PhpSpreadsheet en vez de OpenSpout. Si los dos se leen idéntico, la lectura no depende de quién escribió el archivo. |
| `16_viejo.xls` | 2 | Un `.xls` **BIFF de verdad** (writer `Xls` de PhpSpreadsheet, no un `.xlsx` renombrado): no es un zip, así que `ZipArchive::open()` falla y se ejercita el mensaje limpio de `ExcelWorkbookReader::MENSAJE_ARCHIVO_ILEGIBLE`. |

⚠️ **`06_incidente_servian.xlsx` es el único fixture que se importa con varios lotes.** El `config(['app.ARTICLE_EXCEL_CHUNK_SIZE' => 10])` del `setUp()` de `IncidenteServianTest` es lo que hace que el escenario reproduzca el bug original (la deduplicación funciona *dentro* de un lote pero no *entre* lotes). Si alguien cambia o quita ese `config()`, el test deja de probar lo que dice probar aunque siga pasando en verde.

`CadenaIdentificacionTest.php` (grupo 284, prompt 05) no importa nada: es un test de caracterización de `AiExcelAnalyzer::analyze_identification_chain()` (vía `ReflectionMethod`, el método es `protected` a propósito) sobre `01_codigos_de_proveedor.xlsx` y `07_repetidos_en_el_archivo.xlsx`, para que un conteo roto en el bloque "Como se van a identificar los articulos" del paso 3 se detecte antes de llegar a un cliente.

`CascadaHerenciaTest.php` (grupo 287, prompt 02) importa `09_cascada_herencia.xlsx` contra el escenario sembrado por `ImportTestSeeder` más un artículo `PC-T-UNICO` que el propio test crea sin sku ni bar_code: cubre el lado de la cascada de `ArticleIndexCache::find_with_index()` que quedó sin test (bar_code nuevo que hereda vía sku, herencia doble vía provider_code único), el conflicto `identificador_sin_asignar` sobre un provider_code repetido con el campo bar_code (complemento del que ya prueba `08_match_unico_provider_code.xlsx` con el campo sku) y la guarda `identificadores_asignados_en_chunk` contra dos filas del mismo chunk que compiten por heredar el mismo bar_code nuevo.

`EscalonNombreTest.php` (grupo 287, prompt 03) importa `10_escalon_nombre.xlsx` contra `A9`/`A10`/`A11` del escenario sembrado más un artículo `T-NORM` que el propio test crea sin códigos: cubre el escalón 5 (`name`) de la cascada como match único y como ambigüedad, la herencia de un identificador pendiente hasta ese escalón, el corte de cadena cuando un `provider_code` no matchea (no baja a `name` aunque el nombre exista en base, comportamiento a fijar) y la normalización de `normalize_name_for_match()`.

## Hoja elegida y fila de encabezado (22/8/2026)

`LecturaDeHojasTest.php` y `EncabezadoDetectadoTest.php` cubren los helpers de
`app/Http/Controllers/Helpers/import/excel/`, que centralizan la lectura del libro para
artículos, clientes y proveedores. Ninguno de los dos toca la base: extienden `Tests\TestCase`,
igual que `CadenaIdentificacionTest`.

**Qué hoja se lee.** Antes, los doce lectores del import hacían
`foreach ($reader->getSheetIterator() as $sheet) { ... break; }`: siempre la primera hoja, sin que
nadie la eligiera ni la viera. Ahora `ExcelWorkbookReader::listar_hojas()` ofrece todas (índice,
nombre y cantidad de filas) y `abrir()` devuelve la hoja pedida ya posicionada. Los nombres y los
índices los da **OpenSpout**, no otra librería: quien después lee las filas es OpenSpout, así que
el índice tiene que salir de la misma fuente que lo consume. La cantidad de filas la pone
`ExcelSheetInspector`, que va por el ZIP con `XMLReader` en streaming (no por
`listWorksheetInfo()` de PhpSpreadsheet, que mete la XML completa de cada hoja en memoria dos
veces — y esta suite ya necesita `memory_limit=-1` para terminar).

**Qué fila es el encabezado.** `ExcelHeaderDetector` mira las primeras 20 filas físicas con las
fusiones ya propagadas y elige la fila con más celdas llenas que además cumpla: al menos 2 celdas
llenas **que vengan del archivo** (las que llenó la propagación de una fusión no cuentan), ninguna
numérica ni fecha, ninguna de más de 40 caracteres y todas distintas entre sí **evaluando sólo las
que vienen del archivo**. Frena en la primera fila que ya son datos: al menos una celda numérica o
fecha y al menos **la mitad de las columnas de la tabla** llenas, con un piso de 3. Si no hay
candidata, cae a la regla vieja (primera fila con algún contenido) con
`motivo = 'sin_candidata_clara'` y `confianza = 'baja'`.

⚠️ **Las dos condiciones marcadas arriba se calibraron así después de medir, y las dos parecen de
más.** El umbral de corte era `>= 2 celdas`, y con eso cortaba ante cualquier renglón de membrete:
`"Distribuidora Bianchi S.A. | 30712345679"` son dos celdas con un número (el CUIT), igual que
`"Vigencia desde: | 2026-08-01"`. Las listas de proveedor más comunes que existen daban `fila=1,
sin_candidata_clara`. Y "todas distintas" contaba las celdas propagadas, así que propagar
`"PRECIOS"` sobre `E:F` —que es el arreglo de las cabeceras fusionadas— dejaba un duplicado que
sacaba al encabezado de candidato: **un arreglo desactivaba al otro.** Los fixtures `14_` y `17_`
son los que fijan las dos cosas.

**Cuándo sale la alerta amarilla de `columnas_sin_nombre`.** Sólo por un agujero adentro del
encabezado, o por una columna que las **filas de datos** usan de verdad (llena en al menos la mitad
de las filas debajo del encabezado). Medía contra cualquiera de las 20 filas, y una nota suelta en
J3 (`"Promo hasta fin de mes"`) alcanzaba para que la alerta saliera sobre una planilla impecable —
con la nota en AD2, 27 letras de columna. **Una alerta que sale sin motivo es peor que no tener
alerta:** a la tercera vez el usuario deja de leer las amarillas, incluida la que sí importa.

🔴 **La misma regla está implementada dos veces**, en PHP acá y en JavaScript en
`empresa-spa/src/components/listado/modals/ai-excel-import/Index.vue`, método
`detect_header_row()`. Si cambiás una, cambiá la otra: el navegador calcula `start_row` con su
copia y el backend arma el mapeo con ésta, y si divergen se llega al peor escenario posible —el
mapeo armado con la fila 4 y la importación arrancando en la fila 2, sin ningún error visible.
`test_los_once_fixtures_existentes_siguen_teniendo_el_encabezado_en_la_fila_1` es la red que
detecta cualquier cambio de esa regla sobre el parque de fixtures existente.

**Cuántas filas dice tener cada hoja.** `ExcelSheetInspector` lee `<dimension>`, que es O(1), pero
**no le cree siempre**: un `ref="A1:A1"` (o `ref="A1"`) decía "1 fila" sobre una hoja de 6, y una
hoja vacía decía "1 filas" en el selector. Con un `ref` de una sola celda se recorren los `<row>`.
Y como también hay `<dimension>` que declaran de **menos** (`ref="A1:D2"` con 6 filas reales), en
las hojas chicas (hasta `BYTES_PARA_VERIFICAR_DIMENSION`, 64 KB de XML sin comprimir) se recorre
igual y gana el número más grande. En las hojas grandes se le cree al archivo: recorrer una hoja de
20.000 filas son **~800 ms medidos**, y esa cantidad de filas es sólo un rótulo del selector.
Se aclara acá porque es una decisión, no un olvido.

ℹ️ `EncabezadoDetectadoTest.php` tiene un `saltear_si_la_unidad_2_no_aterrizo()` que **hoy no
saltea nada**: la unidad 2 aterrizó y `AiExcelAnalyzer::analyze()` ya tiene su tercer parámetro
`array $opciones = []`. Queda como guarda por si alguien vuelve a partir el trabajo en unidades.

## Estabilidad de orden de ejecución (Grupo 289, 31/7/2026)

Confirmado el 30/7/2026 que el gate diferencial de `carril-merge.ps1` había visto resultados
distintos entre corridas de esta suite (comentarios ~581 y ~670 de ese script), lo que hacía
sospechar de dependencia de orden entre tests.

Medido con 4 corridas completas de `phpunit tests/Import`: orden por defecto + `--order-by=random`
con semillas `111`, `222` y `333`. Las cuatro dan **exactamente el mismo resultado** — 90 tests,
1136 assertions, 9 rojos (1 error + 8 failures), siempre los mismos nombres. La suite ya es
estable; no hizo falta ningún cambio de aislamiento nuevo. El reset de
`ArticleIndexCache::reset_runtime_de_tests()` + `Cache::flush()` en `setUp()`/`tearDown()` de
`ImportTestCase` (presente desde que se creó la suite, grupo 238) ya cubre el único estado que
sobrevive entre tests dentro del mismo proceso PHP, y como **todas** las clases de `tests/Import`
extienden `ImportTestCase`, ya actúa como el trait común que pedía este prompt — no se agregó un
trait aparte porque hubiera sido el mismo reset duplicado en otro archivo. También se revisó
`config()` (Laravel reconstruye la aplicación completa en cada test — `TestCase::tearDown()` pone
`$this->app = null` — así que no persiste entre tests) y Mockery (`tearDown()` de Laravel ya llama
`Mockery::close()` siempre): ninguno de los dos es fuente real de fuga acá.

🔴 **Ese baseline de 90 tests y 9 rojos quedó viejo. No lo uses.** Las dos mediciones vigentes, las
dos del **22/8/2026** en el slot `s8` y las dos con
`php -d memory_limit=-1 vendor/bin/phpunit tests/Import`:

| Cuándo | Resultado | Para qué sirve |
|---|---|---|
| **Antes** de la misión de importación de Excel (hoja elegida, cabecera fusionada, encabezado corrido) | `122 tests, 1457 assertions, 3 failures` | referencia histórica: es contra esto que se midió que la misión no metió regresiones |
| **Hoy**, con la misión y los arreglos de su chequeo independiente en el árbol | `179 tests, 1685 assertions, 3 failures` | **éste es el baseline: el que tiene que dar una corrida limpia hoy** |

⚠️ El total de tests sigue creciendo mientras aterrizan arreglos, así que si no coincide al test no
entres en pánico: **lo que es baseline son los 3 rojos, con estos nombres exactos.** Un cuarto rojo,
o un nombre distinto, es una regresión — salvo que se te haya solapado otra corrida, ver el bloque
de abajo.

Los tres rojos vigentes son los mismos en las dos mediciones, con nombre y todo:

1. `IncidenteServianTest::test_no_se_crean_dos_articulos_con_el_mismo_bar_code`
2. `IncidenteServianTest::test_reimportar_no_genera_movimientos_nuevos`
3. `RollbackTest::test_el_rollback_borra_los_articulos_creados`

**Los otros seis rojos que listaba este README se arreglaron en el medio** (los dos restantes de
`RollbackTest`, los otros dos de `IncidenteServianTest` —incluido el `array_map()` que era el único
error, no failure— y los dos de `CascadaHerenciaTest`). La suite creció de 90 a 122 tests, y de 122
a lo que dice la tabla de arriba con la misión de importación de Excel. Un cuarto rojo, o un nombre
distinto de esos tres, es una regresión nueva.

## 🔴 Una corrida por vez. La suite NO es segura en paralelo contra la misma base

Dos corridas simultáneas de `tests/Import` contra `empresa_testing_s8` **producen un rojo distinto
cada vez**. Medido el 22/8/2026: apareció un cuarto rojo en
`CodigosDeBarraRepetidosTest::test_el_merge_deja_los_valores_de_la_ultima_fila` que **desapareció al
correr la suite sola** — se le había solapado otra corrida contra la misma base. Los tests usan
`DatabaseTransactions` sobre un mismo esquema y un mismo tenant sembrado: dos procesos a la vez se
pisan las filas.

El síntoma es traicionero justamente porque el rojo **cambia de nombre en cada corrida**, así que
parece un bug de concurrencia del código y no del entorno de test. **Si ves un rojo raro, volvé a
correr esa clase sola antes de creerle.** Y si hay varias sesiones trabajando sobre el mismo slot,
una corre la suite y las otras esperan. Costó una corrida entera aprenderlo.

El detalle de abajo es la foto del 31/7/2026 y se deja como historia de la medición de estabilidad,
no como baseline.

Los 9 rojos estables **no son ruido de orden**: son bugs reproducibles y reales, fuera del alcance
de este grupo (que es sobre estabilidad, no sobre cero rojos). Quedan documentados para el próximo
grupo correctivo:

- **`RollbackTest` (3 tests)** — `POST /api/import-history/rollback/{id}` no restaura
  `final_price`/`stock` a su valor original ni borra los artículos creados por la importación
  revertida (`test_el_rollback_deja_todo_exactamente_como_estaba`,
  `test_el_rollback_borra_los_articulos_creados`, `test_revertir_dos_veces_es_idempotente`).
- **`IncidenteServianTest` (4 rojos: 1 error + 3 failures)** — la variante multi-lote
  (`ARTICLE_EXCEL_CHUNK_SIZE=10`) vuelve a mostrar síntomas de la familia del incidente original:
  `array_map(): Expected parameter 2 to be an array, int given` en
  `test_el_bar_code_repetido_entre_lotes_se_reporta` (línea 184), bar_code duplicado entre lotes
  sin reportarse (`test_no_se_crean_dos_articulos_con_el_mismo_bar_code`), una reimportación que
  genera un movimiento de stock que no debería
  (`test_reimportar_no_genera_movimientos_nuevos`), y nombres idénticos sin código que no se
  marcan ambiguos (`test_nombres_identicos_sin_codigo_son_ambiguos`).
- **`CascadaHerenciaTest` (2 failures)** — el bucket `sku` cuenta 2 filas en vez de 3
  (`test_buckets_de_la_importacion_completa`), y dos filas del mismo chunk terminan heredando el
  mismo valor cuando no deberían (`test_dos_filas_no_pueden_heredar_el_mismo_valor_en_el_mismo_chunk`).

`prompts/suites.json` (repo `claude-comerciocity`) se actualizó con `baseline_rojos: 9` y el
detalle de esta medición, para que el gate diferencial de merge no confunda estos 9 rojos
conocidos con una regresión nueva. ⚠️ **Ese `baseline_rojos: 9` también quedó viejo**: hoy son 3
(ver el bloque de arriba). Si el gate diferencial sigue comparando contra 9, va a dejar pasar seis
regresiones sin chistar.

**Gotcha de entorno encontrado midiendo esto:** un slot recién provisionado puede no tener
corridas las migraciones más recientes contra `.env.testing` (acá faltaban
`add_fila_ganadora_to_import_conflicts` y `extend_cost_decimals_on_articles_table`, entre otras) ni
el tenant `id=900` que pide `ImportTestCase::TENANT_USER_ID` — no hay seeder para ese tenant
todavía, se asume restaurado desde un dump. Si la suite da ~85/90 rojos con
`"No existe el usuario de prueba id=900"` o un `SQLSTATE[42S22]: Column not found`, correr
`php artisan migrate --env=testing --force` y sembrar el tenant a mano antes de sospechar de un
bug real.
