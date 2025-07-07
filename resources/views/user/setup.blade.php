<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configurador de nuevo usuario</title>
    <style>
        body {
            background-color: #f0f2f5;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px 0;
        }

        .container {
            width: 100%;
            margin: 30px 0;
/*            height: 100vh;*/
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .form-box {
            background-color: #ffffff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 600px;
        }

        h1 {
            text-align: center;
            margin-bottom: 25px;
            color: #333;
        }

        label {
            display: block;
            margin-top: 15px;
            color: #333;
        }

        input[type="text"],
        select {
            width: 100%;
            padding: 8px 10px;
            margin-top: 5px;
            border-radius: 5px;
            border: 1px solid #ccc;
        }

        input[type="checkbox"] {
            margin-right: 10px;
        }

        button {
            margin-top: 20px;
            width: 100%;
            background-color: #007bff;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }

        button:hover {
            background-color: #0056b3;
        }

        .status {
            color: green;
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="form-box">
        <h1>Dar de alta usuario</h1>

        @if(session('status'))
            <p class="status">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('user.setup') }}">
            @csrf

            <label>Nombre</label>
            <input type="text" name="user_name"/>

            <label>Nombre empresa</label>
            <input type="text" name="company_name"/>

            <label>User id</label>
            <input type="text" name="user_id"/>

            <label>Documento</label>
            <input type="text" name="doc_number"/>

            <label>Email</label>
            <input type="text" name="email"/>

            <label>Telefono</label>
            <input type="text" name="phone"/>

            <label>Valor del mes</label>
            <input type="text" name="total_a_pagar"/>



            <label>Tipo de negocio:</label>
            <select name="business_type" required>
                <option value="">-- Seleccionar --</option>
                <option value="ferreteria">Ferretería</option>
                <option value="distribuidora">Distribuidora</option>
                <option value="ropa">Tienda de ropa</option>
            </select>

            
            <hr>
            <p>Sucursales</p>
            <label><input type="checkbox" name="use_deposits" value="1"> Usa depósitos</label>

            <hr>
            <p>Precios</p>
            <label><input type="checkbox" name="use_price_lists" value="1"> Usa listas de precios</label>
            <label><input type="checkbox" name="cambiar_price_type_en_vender" value="1"> Cambian manualmente la lista de precios en VENDER</label>
            <label><input type="checkbox" name="cambiar_price_type_en_vender_item_por_item" value="1"> Cambian la lista de precios a cada item individualmente</label>
            <label><input type="checkbox" name="iva_included" value="1"> Iva ya incluido en los precios</label>

            <hr>
            <p>Vender</p>

            <label><input type="checkbox" name="ventas_con_fecha_de_entrega" value="1"> Ventas con fecha de entrega (Hojas de ruta)</label>
            
            <label><input type="checkbox" name="ask_amount_in_vender" value="1"> Preguntar la cantidad en vender</label>
            <label><input type="checkbox" name="redondear_centenas_en_vender" value="1"> Redondear precios de a centenas</label>
            <label><input type="checkbox" name="usan_cuentas_corrientes" value="1"> Usan cuentas corrientes con los clientes</label>
            <label><input type="checkbox" name="budgets" value="1"> Usa presupuestos</label>
            
            <hr>

            <p>Tesoreria</p>
            <label><input type="checkbox" name="cajas" value="1"> Usa cajas</label>
            
            <hr>
            <p>Articulos</p>
            <label><input type="checkbox" name="imagenes" value="1"> Usa imágenes en los artículos</label>
            <label><input type="checkbox" name="usar_codigos_de_barra" value="1"> Usa códigos de barra</label>
            <label><input type="checkbox" name="consultora_de_precios" value="1"> Ofrece consultoras de precio a sus clientes en el local</label>

            <label><input type="checkbox" name="codigos_de_barra_por_defecto" value="1"> Codigos de barra por defecto (se generan con el numero interno del producto)</label>

            <hr>
            <p>Produccion</p>
            <label><input type="checkbox" name="produccion" value="1"> Usa modulo de produccion</label>

            <button type="submit">Crear demo</button>
        </form>
    </div>
</div>
</body>
</html>
