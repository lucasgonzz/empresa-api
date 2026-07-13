# Prompt 263 — Refactor precios/costos: Capa 3 — recargos/descuentos por método de pago y cuotas al vender (+ precio_base_incluye_tarjeta)

## Estado
bloqueado

## Descripción
Implementa la Capa 3 del marco de precios (`refactor_empresa/precios_costos.md`; plan Fase 2,
prompt B4): los costos de comercialización (recargos de tarjeta, descuentos por efectivo) NO van en
el precio base del artículo — se configuran por método de pago / cantidad de cuotas y se aplican
**al momento de vender/facturar**.

El sistema YA tiene las piezas base funcionando (verificado en `refactor_empresa/ventas.md`):
- `current_acount_payment_method_discounts`: % de descuento (o recargo si es negativo) por método,
  aplicado en Vender — incluso con pago múltiple (descuento sobre el monto asignado a cada método,
  con re-pregunta de asignación). **Esto ya funciona y se preserva.**
- `cuotas`: reglas de descuento/recargo por cantidad de cuotas (genéricas por usuario).

Lo que este prompt agrega (sobre el esquema del prompt 260):
1. **Recargos por cuotas específicos por método** (`cuotas.payment_method_id`): "crédito Visa 3
   cuotas +5%" distinto de "crédito Naranja 3 cuotas +8%". NULL = regla genérica (compatibilidad).
2. **Reglas de método limitadas a cantidad de cuotas**
   (`current_acount_payment_method_discounts.cuotas`).
3. **`precio_base_incluye_tarjeta`** (flag de `users`): modo para comerciantes que quieren que el
   precio de etiqueta ya incluya la tarjeta más cara, mostrando el efectivo como descuento.

## Ejecución sugerida

cursor

## Modelo sugerido
Claude Sonnet, esfuerzo alto — toca el flujo de Vender (el más usado del sistema) y la interacción

## Checker sugerido
`opus`. Chequeo con Opus por lógica nueva / riesgo cruzado (regla checker opt-in, 7/7/2026).
entre reglas de método, cuotas y pago múltiple. Requiere mapear la lógica actual antes de extender.

## Repositorio y rama
`empresa-api` (rama `develop`). Depende del prompt 260 (esquema). La UI se hace en el prompt 266
(empresa-spa) — este prompt deja la API lista.

---

## Contexto técnico

- Leer primero cómo se aplican hoy los descuentos por método en la venta:
  @app/Http/Controllers/Helpers/SaleHelper.php (busca `discount_percentage`) y el modelo
  @app/Models/CurrentAcountPaymentMethodDiscount.php con su controller.
- Leer el modelo @app/Models/Cuota.php y @app/Http/Controllers/CuotaController.php (reglas por
  cantidad de cuotas). Dónde se aplican hoy las cuotas en la venta: buscar los call sites de
  `Cuota` en el flujo de venta y de facturación.
- El comportamiento de pago múltiple con re-pregunta ya construido NO se modifica (descripción en
  `refactor_empresa/ventas.md`).

## Tareas

### 1. Resolución de la regla aplicable (API)
Implementar (en el helper donde hoy se resuelven descuentos por método) la lógica de precedencia,
de más específica a más genérica:
1. Regla de `cuotas` con `payment_method_id` = método elegido y `cantidad_cuotas` = cuotas elegidas.
2. Regla de `cuotas` genérica (`payment_method_id` NULL) con esa `cantidad_cuotas`.
3. Regla de `current_acount_payment_method_discounts` del método con `cuotas` = cuotas elegidas.
4. Regla de `current_acount_payment_method_discounts` del método con `cuotas` NULL (actual).

Gana la primera que matchee (no se acumulan). Documentar la precedencia en comentario del helper.

### 2. `precio_base_incluye_tarjeta` (API)
Cuando el flag del usuario está activo:
- El precio final del artículo (calculado en 261) se muestra/usa como precio de etiqueta SIN
  recargo adicional para el método/cuotas de MAYOR recargo configurado.
- Los métodos con menor recargo (típicamente efectivo) se presentan como DESCUENTO respecto del
  precio de etiqueta: `descuento_efectivo = recargo_max − recargo_metodo` (en términos de la
  fórmula, revisar que el redondeo cierre: precio_etiqueta / (1+recargo_max) × (1+recargo_metodo)).
- Exponer en la respuesta de la API de venta/artículos la información necesaria para que el SPA
  (prompt 266) muestre "Efectivo: $X (−Y%)".
- Con el flag apagado: comportamiento actual intacto (precio base + recargo al elegir método).

### 3. Qué NO hace
- NO toca la UI (266).
- NO cambia el flujo de pago múltiple existente.
- NO toca facturación AFIP (los importes ya viajan con el total final de la venta).

## Restricción PHP 7.4
Prohibido: `?->`, `match`, `str_contains`, `str_starts_with`, `str_ends_with`. Usar `isset()`,
`strpos()`, `switch`, ternarios.

## Criterio de éxito
- Regla "Visa crédito, 3 cuotas, +5%" (cuotas con payment_method_id) le gana a la genérica "3
  cuotas +3%" cuando se paga con Visa; con otra tarjeta aplica la genérica.
- Reglas actuales (todo NULL en los campos nuevos) se comportan EXACTAMENTE igual que hoy.
- Con `precio_base_incluye_tarjeta`: artículo $1210 de etiqueta (incluye crédito 6 cuotas +10%
  sobre $1100) → elegir efectivo muestra $1100 (−9.09% del precio de etiqueta). Con el flag
  apagado, mismo artículo: etiqueta $1100 y crédito 6 cuotas $1210.
- Venta con pago múltiple sigue funcionando idéntica.

---

Al terminar, pushea empresa.

