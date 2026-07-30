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

⚠️ **`06_incidente_servian.xlsx` es el único fixture que se importa con varios lotes.** El `config(['app.ARTICLE_EXCEL_CHUNK_SIZE' => 10])` del `setUp()` de `IncidenteServianTest` es lo que hace que el escenario reproduzca el bug original (la deduplicación funciona *dentro* de un lote pero no *entre* lotes). Si alguien cambia o quita ese `config()`, el test deja de probar lo que dice probar aunque siga pasando en verde.
