# Tests de compras (`@group compras`)

## 1. Reconstruir la base de testing y sembrar el fixture

```
php artisan migrate:fresh --env=testing
php artisan db:seed --env=testing --class="Database\Seeders\testing\TestingFerreteriaSeeder"
```

(Requiere `.env.testing` copiado de `.env.testing.example`, con `DB_DATABASE` conteniendo
`testing`, ej. `empresa_testing`.)

## 2. Correr la suite

```
vendor\bin\phpunit --group compras
```

## 3. Constantes nuevas del fixture (Grupo 242 · Prompt 02)

Ademas de los proveedores/deposito/articulos originales (`PROVIDER_BSAS`, `PROVIDER_OTRO`,
`DEPOSITO`, `ARTICULO_CENTINELA`), `TestingFerreteriaSeeder` siembra el lado de ventas y tesoreria
para las suites de cajas, gastos, IVA por condicion y reportes. Todo resoluble por constante
publica de la clase — nunca por `id` hardcodeado.

| Constante | Valor | Notas |
|---|---|---|
| `CLIENTE_CC` | `Cliente Cuenta Corriente` | Uso pensado para saldo anterior/actual y reversion de cobros. |
| `CLIENTE_CONTADO` | `Cliente Contado` | Sin cuenta corriente. |
| `CLIENTE_EXENTO` | `Cliente Exento` | `iva_condition_id` = "Exento" (distinta a Responsable Inscripto). |
| `PAGO_EFECTIVO` | `Efectivo` | Metodo de pago de cuenta corriente. |
| `PAGO_TARJETA_CREDITO` | `Credito` | Metodo de pago de cuenta corriente (nombre real del seeder base). |
| `CONCEPTO_GASTO_COMISION` | `Comisiones bancarias` | `expense_concept_id` de las cajas comisionadas. |
| `CONCEPTO_GASTO_OPERATIVO` | `Alquiler` | Gasto generico para Estado de Resultados. |
| `CAJA_EFECTIVO` | `Caja Efectivo` | Sin ninguna columna de liquidacion/comision seteada (todo null). |
| `CAJA_MP` | `Caja Mercado Pago` | `dias_liquidacion=14`, `comision_porcentaje=6.29`, `comision_iva_alicuota=21`, `comision_iva_incluido=1`, con `expense_concept_id`. |
| `CAJA_SIN_CONCEPTO` | `Caja Sin Concepto` | Misma config que `CAJA_MP` pero `expense_concept_id = null`. |
| `IMPUESTO_IIBB` | `IIBB` | `SaleTax` de ejemplo, `apply_to_all = true`. |

No se siembra ningun `caja_liquidacion_configs`: los overrides por metodo de pago los crea cada
test que los necesita.
