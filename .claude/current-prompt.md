# Prompt 517 — SPA: UI de precios-con-IVA y costos extra facturados + descripción de CADA propiedad nueva (empresa-spa)

## Estado

bloqueado

## Descripción

Frontend del fix de IVA de la compra. Agrega los controles nuevos y —pedido explícito y reiterado de
Lucas— **la descripción de cada propiedad nueva del modelo, para que el usuario entienda desde la
interfaz qué hace cada una**. Cada propiedad que se agrega o toca debe llevar su `description` (el
texto que la UI muestra como ayuda/tooltip), siguiendo el mismo patrón con que otras propiedades ya
exponen su ayuda en los modelos de empresa-spa.

Controles nuevos:
1. **Check "Los precios ya incluyen IVA"** en la carga de la compra (`provider_order`), con default
   heredado del proveedor (`provider.precios_incluyen_iva`) al seleccionarlo; el de la compra manda.
2. **Config del proveedor** `precios_incluyen_iva` (default de sus compras).
3. Por cada **costo extra**: check `facturado`; si está tildado, selector de **alícuota** (`iva_id`) y
   check `en_factura_compra` (dentro de la factura de la compra vs factura aparte); si es factura
   aparte, campos `emisor_cuit` y `emisor_razon_social`.

## Ejecución sugerida

cursor

## Modelo sugerido

sonnet — toca el formulario de compra (uno de los más cargados) + ABM del proveedor + descripciones en
varios modelos; conviene un modelo que mantenga coherencia entre los controles y los `description`.

## Repositorios y ramas

`empresa-spa` (rama `refractor`). Trabajar y pushear en `refractor`. Vue 2 Options API, sin async.
NO develop, NO master.

## Ramas

- empresa-spa: refractor

## Dependencias

Depende de los prompts **513, 514, 515, 516** (esquema + toda la lógica de backend). Ejecutar el
frontend al final del grupo.

## Checker sugerido

sonnet. Revisar que: (a) NO se use `async/await` (usar `.then()/.catch()`) ni `.map()` para
transformar (usar `forEach`); (b) los `<style lang="sass">` usen **tabs**; (c) el default heredado del
proveedor pre-cargue pero el flag de la compra pueda sobreescribirlo; (d) los campos del costo extra
(alícuota, emisor) aparezcan/desaparezcan según `facturado` y `en_factura_compra`; (e) **cada propiedad
nueva tenga su `description`** con texto claro; (f) comentarios en español.

---

## Constraint de estilo (empresa-spa)

Vue 2 + bootstrap-vue, **Options API**. Sin `async/await` (usar `.then()/.catch()`). Comentarios en
español. `forEach` por sobre `map`. `<style lang="sass">` **con tabs** (SASS indentado, no SCSS).

---

## Contexto técnico (rama refractor)

- Modelo `@src/models/provider_order.js` — define las propiedades de la orden de compra y sus
  `description`. Acá va el `description` de `precios_incluyen_iva` (y ya existe el patrón para
  `actualizar precios`, usarlo de referencia de estilo).
- Modelo `@src/models/provider.js` — config del proveedor; sumar `precios_incluyen_iva` con su
  `description`.
- El modelo del costo extra (buscar `provider_order_extra_cost` / donde se definan los extra costs de
  la compra) — sumar `facturado`, `iva_id`, `en_factura_compra`, `emisor_cuit`, `emisor_razon_social`,
  cada uno con su `description`.
- El formulario de carga de compra (componentes de `provider-order` / compra) donde se listan artículos
  y costos extra — agregar los controles nuevos.
- El toggle tipo iPhone y el selector de alícuota (`iva_id`) ya existen en otros formularios; reusar.

## Tareas

1. **`provider_order.precios_incluyen_iva`**: check en la carga de la compra. Al elegir proveedor,
   pre-cargar el valor desde `provider.precios_incluyen_iva`; el usuario puede sobreescribirlo para esa
   compra. `description`:
   > "Indica que los precios de esta compra ya vienen con el IVA incluido por parte del proveedor.
   > Activado: el precio que cargás es el final (con IVA) y el sistema desglosa cuánto es neto y cuánto
   > IVA para la factura, y guarda el costo del artículo SIN IVA. Desactivado: el precio que cargás es
   > neto y el sistema le suma el IVA para armar la factura. En ambos casos el costo del artículo se
   > guarda siempre neto."
2. **`provider.precios_incluyen_iva`**: check en el ABM del proveedor. `description`:
   > "Valor por defecto para tus compras a este proveedor: si sus listas de precios ya incluyen IVA.
   > Al cargar una compra de este proveedor, el check 'Los precios ya incluyen IVA' viene pre-tildado
   > según esto (podés cambiarlo en cada compra)."
3. **Costo extra `facturado`**: check por costo extra. `description`:
   > "Indica si este costo extra (flete, seguro, etc.) vino facturado. Si está facturado, genera IVA
   > crédito y hay que indicar con qué alícuota; si no, suma al costo sin IVA."
4. **Costo extra `iva_id`**: selector de alícuota, visible solo si `facturado`. `description`:
   > "Alícuota de IVA con la que se facturó este costo extra (puede ser distinta a la de la mercadería;
   > ej. el flete suele ir a 21%)."
5. **Costo extra `en_factura_compra`**: check, visible solo si `facturado`. `description`:
   > "Activado: este costo extra va dentro de la misma factura de la compra. Desactivado: se factura
   > aparte (por ejemplo, cuando el transporte lo hizo otra empresa) y se genera un comprobante
   > separado con los datos del emisor."
6. **Costo extra `emisor_cuit` / `emisor_razon_social`**: campos visibles solo si `facturado` y NO
   `en_factura_compra`. `description` respectivos:
   > emisor_cuit: "CUIT del emisor de la factura aparte de este costo extra (ej. la empresa de
   > transporte que lo facturó por separado)."
   > emisor_razon_social: "Razón social del emisor de la factura aparte de este costo extra."
7. Mostrar/ocultar condicionalmente los campos (alícuota y emisor) según `facturado` y
   `en_factura_compra`, con `v-if`.

## Criterio de éxito

- Al elegir un proveedor con `precios_incluyen_iva` ON, la compra viene con el check pre-tildado;
  destildarlo en la compra funciona.
- Cada propiedad nueva muestra su ayuda/descripción en la UI (tooltip/ayuda, según el patrón existente).
- Los campos de alícuota y emisor del costo extra aparecen solo cuando corresponde.
- Sin `async/await`, `forEach` en vez de `map`, SASS con tabs.

---

Al terminar, pushea empresa.

