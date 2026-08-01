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

⚠️ **`06_incidente_servian.xlsx` es el único fixture que se importa con varios lotes.** El `config(['app.ARTICLE_EXCEL_CHUNK_SIZE' => 10])` del `setUp()` de `IncidenteServianTest` es lo que hace que el escenario reproduzca el bug original (la deduplicación funciona *dentro* de un lote pero no *entre* lotes). Si alguien cambia o quita ese `config()`, el test deja de probar lo que dice probar aunque siga pasando en verde.

`CadenaIdentificacionTest.php` (grupo 284, prompt 05) no importa nada: es un test de caracterización de `AiExcelAnalyzer::analyze_identification_chain()` (vía `ReflectionMethod`, el método es `protected` a propósito) sobre `01_codigos_de_proveedor.xlsx` y `07_repetidos_en_el_archivo.xlsx`, para que un conteo roto en el bloque "Como se van a identificar los articulos" del paso 3 se detecte antes de llegar a un cliente.

`CascadaHerenciaTest.php` (grupo 287, prompt 02) importa `09_cascada_herencia.xlsx` contra el escenario sembrado por `ImportTestSeeder` más un artículo `PC-T-UNICO` que el propio test crea sin sku ni bar_code: cubre el lado de la cascada de `ArticleIndexCache::find_with_index()` que quedó sin test (bar_code nuevo que hereda vía sku, herencia doble vía provider_code único), el conflicto `identificador_sin_asignar` sobre un provider_code repetido con el campo bar_code (complemento del que ya prueba `08_match_unico_provider_code.xlsx` con el campo sku) y la guarda `identificadores_asignados_en_chunk` contra dos filas del mismo chunk que compiten por heredar el mismo bar_code nuevo.

`EscalonNombreTest.php` (grupo 287, prompt 03) importa `10_escalon_nombre.xlsx` contra `A9`/`A10`/`A11` del escenario sembrado más un artículo `T-NORM` que el propio test crea sin códigos: cubre el escalón 5 (`name`) de la cascada como match único y como ambigüedad, la herencia de un identificador pendiente hasta ese escalón, el corte de cadena cuando un `provider_code` no matchea (no baja a `name` aunque el nombre exista en base, comportamiento a fijar) y la normalización de `normalize_name_for_match()`.

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
conocidos con una regresión nueva.

**Gotcha de entorno encontrado midiendo esto:** un slot recién provisionado puede no tener
corridas las migraciones más recientes contra `.env.testing` (acá faltaban
`add_fila_ganadora_to_import_conflicts` y `extend_cost_decimals_on_articles_table`, entre otras) ni
el tenant `id=900` que pide `ImportTestCase::TENANT_USER_ID` — no hay seeder para ese tenant
todavía, se asume restaurado desde un dump. Si la suite da ~85/90 rojos con
`"No existe el usuario de prueba id=900"` o un `SQLSTATE[42S22]: Column not found`, correr
`php artisan migrate --env=testing --force` y sembrar el tenant a mano antes de sospechar de un
bug real.
