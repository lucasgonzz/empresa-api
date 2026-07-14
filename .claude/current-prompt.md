# Prompt 287: Export Excel de ventas fidedigno (frontend) — Total.vue manda el estado completo del filtro

## Objetivo

Par frontend del prompt 286. Hoy los dos botones de Excel del módulo de ventas abren un link GET con **solo el rango de fechas** (`window.open`). Este prompt los cambia para que hagan un **POST autenticado** a los endpoints nuevos del 286, mandando el estado completo del filtro (filtro por columnas, rango de fechas, las tres show options y la pestaña sucursal/empleado), y disparen la descarga del archivo desde la respuesta (blob). Así el Excel queda fidedigno con lo que se ve en pantalla.

Este prompt es **solo frontend (empresa-spa)**. Depende del 286 (los endpoints POST tienen que existir).

## Repositorio y rama

`empresa-spa` (rama `refractor`). ⚠️ Este grupo (55) se trabaja en `refractor`, no en `develop`.

## Ramas

- empresa-spa: refractor

## Contexto técnico

- `@empresa-spa/src/components/ventas/components/Total.vue` — único archivo a tocar. Contiene los dos botones (`@click="export_excel"` y `@click="export_breakdown_excel"`) y los dos métodos que hoy hacen `window.open(process.env.VUE_APP_API_URL + '/sales/excel/export/' + this.from_date + '/' + this.until_date)`. Ya importa el mixin `sale` (le da `addresses`, `employees`, `sales_to_show`, etc.).
- Los nuevos endpoints del 286 son `POST sales/excel/export` y `POST sales/excel/breakdown-export`, bajo el grupo `auth:sanctum` de `api.php` (o sea, la URL real es `/api/sales/excel/export`).
- Instancia HTTP: usar **`this.$api`** (la misma que usa `@empresa-spa/src/common-vue/components/horizontal-nav/ExcelDropDown.vue` en `this.$api.get(this.model_name + '/excel/export')`), cuya baseURL ya incluye `/api` y manda el token. Verificar en el proyecto que `this.$api.post('sales/excel/export', ...)` resuelve a `/api/sales/excel/export` con auth. Si por algún motivo `$api` no prefijara `/api`, usar el mismo `axios` global que usa el store (`@empresa-spa/src/store/sale/index.js` hace `axios.post('/api/...')`) con la ruta `/api/sales/excel/export`. Lo importante: que sea una request **autenticada** (con token), no `window.open`.
- Estado del filtro (todo en `this.$store.state.sale`): `is_filtered`, `filters`, `ventas_cobradas_show_option`, `afip_ticket_show_option`, `payment_method_show_option`. El rango de fechas ya está como computed en el componente (`this.from_date`, `this.until_date`).
- Resolución de la pestaña: `this.view` y `this.sub_view` (globales, del route) + `this.addresses` / `this.employees`. Hay que resolverlas **igual que `sales_to_show`** en `@empresa-spa/src/mixins/sale.js`: la sucursal se matchea por `address.street` y el empleado por `employee.name` (con `.replaceAll('-', ' ')` y `toLowerCase()`); si `sub_view != 'todos'` y no matchea ningún empleado, es el caso "dueño" (`only_owner`).

## Tarea

En `Total.vue`, **reemplazar** los métodos `export_excel` y `export_breakdown_excel` y **agregar** los helpers. Dejar todo el resto del componente igual (template, computeds, imports).

```js
methods: {
    export_excel() {
        this.download_sales_excel('sales/excel/export', 'ventas')
    },
    export_breakdown_excel() {
        this.download_sales_excel('sales/excel/breakdown-export', 'ventas_desglosado')
    },
    /**
     * Arma el cuerpo del POST con el estado completo del filtro que ve la pantalla.
     * @returns {Object}
     */
    build_export_body() {
        let state = this.$store.state.sale
        let scope = this.resolve_view_scope()
        return {
            is_filtered: state.is_filtered,
            filters: state.filters,
            from_date: this.from_date,
            until_date: this.until_date,
            ventas_cobradas_show_option: state.ventas_cobradas_show_option,
            afip_ticket_show_option: state.afip_ticket_show_option,
            payment_method_show_option: state.payment_method_show_option,
            address_id: scope.address_id,
            employee_id: scope.employee_id,
            only_owner: scope.only_owner,
        }
    },
    /**
     * Resuelve la pestaña actual (sucursal/empleado) igual que sales_to_show (mixins/sale.js).
     * @returns {{address_id: (number|null), employee_id: (number|null), only_owner: boolean}}
     */
    resolve_view_scope() {
        let address_id = null
        let employee_id = null
        let only_owner = false

        if (this.view != 'todas') {
            let address = this.addresses.find(model => {
                return model.street.toLowerCase() == this.view.replaceAll('-', ' ').toLowerCase()
            })
            if (typeof address != 'undefined') {
                address_id = address.id
            }
        }

        if (this.sub_view != 'todos') {
            let employee = this.employees.find(model => {
                return model.name.toLowerCase() == this.sub_view.replaceAll('-', ' ').toLowerCase()
            })
            if (typeof employee == 'undefined') {
                // Caso "dueño": ventas sin empleado asignado.
                only_owner = true
            } else {
                employee_id = employee.id
            }
        }

        return { address_id, employee_id, only_owner }
    },
    /**
     * POST autenticado al endpoint de export; descarga el .xlsx desde la respuesta (blob).
     * @param {string} endpoint 'sales/excel/export' | 'sales/excel/breakdown-export'
     * @param {string} filename_prefix Prefijo del nombre del archivo.
     * @returns {void}
     */
    download_sales_excel(endpoint, filename_prefix) {
        this.$api.post(endpoint, this.build_export_body(), { responseType: 'blob' })
            .then(res => {
                let url = window.URL.createObjectURL(new Blob([res.data]))
                let a = document.createElement('a')
                a.href = url
                let now = new Date()
                let stamp = ('0' + now.getDate()).slice(-2)
                    + '-' + ('0' + (now.getMonth() + 1)).slice(-2)
                    + '-' + String(now.getFullYear()).slice(-2)
                a.download = filename_prefix + '_' + stamp + '.xlsx'
                document.body.appendChild(a)
                a.click()
                a.remove()
                window.URL.revokeObjectURL(url)
            })
            .catch(() => {
                this.$toast.error('No se pudo generar el Excel', { duration: 4000 })
            })
    },
}
```

Nota: mantener `import sale from '@/mixins/sale'` y el resto de los computeds (`from_date`, `until_date`, totales, etc.) tal como están.

## Criterio de éxito

1. Filtrar por la columna cliente y exportar cualquiera de los dos Excel → el archivo trae **todas** las ventas de ese cliente (no una página), igual que la tabla.
2. Aplicar show options (cobradas/sin cobrar, con/sin factura, método de pago) → el Excel cambia acorde, igual que la pantalla.
3. Estando en una pestaña de sucursal o empleado → el Excel respeta esa pestaña.
4. Sin ningún filtro (solo rango de fechas) → el Excel trae las ventas del rango, como antes.
5. La descarga se dispara sola (nombre `ventas_dd-mm-yy.xlsx` / `ventas_desglosado_dd-mm-yy.xlsx`); ante error muestra el toast.
6. La request va autenticada (con token), no por `window.open`.

## Ejecución sugerida

cursor

## Modelo sugerido

Claude Sonnet

## Al finalizar

pushea empresa

