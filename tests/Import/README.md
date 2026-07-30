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
