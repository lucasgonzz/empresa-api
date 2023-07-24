<?php

namespace App\Http\Controllers\Helpers;

class PaywayHelper {
    
    static function getPaymentMethodId($card_brand) {
        $payment_methods = [
            [
                "idmediopago" => "118",
                "descripcion" => "MasterCard Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "MasterCard"
            ], 
            [
                "idmediopago" => "15",
                "descripcion" => "MasterCard",
                "moneda" => "Pesos ARA",
                "tarjeta" => "MasterCard"
            ], 
            [
                "idmediopago" => "6",
                "descripcion" => " ",
                "moneda" => "Pesos ARA",
                "tarjeta" => "American Express"
            ], 
            [
                "idmediopago" => "135",
                "descripcion" => "Diners Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Diners"
            ], 
            [
                "idmediopago" => "8",
                "descripcion" => "Diners",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Diners"
            ], 
            [
                "idmediopago" => "1",
                "descripcion" => "Visa",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa"
            ], 
            [
                "idmediopago" => "119",
                "descripcion" => "MasterCard Test Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Mastercard Test"
            ], 
            [
                "idmediopago" => "20",
                "descripcion" => "MasterCard Test",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Mastercard Test"
            ], 
            [
                "idmediopago" => "22",
                "descripcion" => "Travelpass",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Travelpass"
            ], 
            [
                "idmediopago" => "23",
                "descripcion" => "Tarjeta Shopping",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta Shopping"
            ], 
            [
                "idmediopago" => "24",
                "descripcion" => "Tarjeta Naranja",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta Naranja"
            ], 
            [
                "idmediopago" => "25",
                "descripcion" => "Pago Facil",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Pago Facil"
            ], 
            [
                "idmediopago" => "26",
                "descripcion" => "RapiPago",
                "moneda" => "Pesos ARA",
                "tarjeta" => "RapiPago"
            ], 
            [
                "idmediopago" => "120",
                "descripcion" => "Cabal Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Cabal"
            ], 
            [
                "idmediopago" => "27",
                "descripcion" => "Cabal",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Cabal"
            ], 
            [
                "idmediopago" => "28",
                "descripcion" => "Visa Mobile",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Mobile"
            ], 
            [
                "idmediopago" => "29",
                "descripcion" => "Italcred",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Italcred"
            ], 
            [
                "idmediopago" => "121",
                "descripcion" => "Argencard Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Argencard"
            ], 
            [
                "idmediopago" => "30",
                "descripcion" => "Argencard",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Argencard"
            ], 
            [
                "idmediopago" => "31",
                "descripcion" => "Visa Débito",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Débito"
            ], 
            [
                "idmediopago" => "33",
                "descripcion" => "Visa Débito del Exterior",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Débito del exte"
            ], 
            [
                "idmediopago" => "122",
                "descripcion" => "CoopePlus Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "CoopePlus"
            ], 
            [
                "idmediopago" => "34",
                "descripcion" => "CoopePlus",
                "moneda" => "Pesos ARA",
                "tarjeta" => "CoopePlus"
            ], 
            [
                "idmediopago" => "36",
                "descripcion" => "Arcash",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Arcash"
            ], 
            [
                "idmediopago" => "123",
                "descripcion" => "Nexo Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Nexo"
            ], 
            [
                "idmediopago" => "37",
                "descripcion" => "Nexo",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Nexo"
            ], 
            [
                "idmediopago" => "124",
                "descripcion" => "Credimas Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Credimas"
            ], 
            [
                "idmediopago" => "38",
                "descripcion" => "Credimas",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Credimas"
            ], 
            [
                "idmediopago" => "39",
                "descripcion" => "Tarjeta Nevada",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta Nevada"
            ], 
            [
                "idmediopago" => "41",
                "descripcion" => "PagoMisCuentas",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Banelco"
            ], 
            [
                "idmediopago" => "109",
                "descripcion" => "Nativa Prisma",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Nativa"
            ], 
            [
                "idmediopago" => "137",
                "descripcion" => "Nativa Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Nativa"
            ], 
            [
                "idmediopago" => "42",
                "descripcion" => "Nativa",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Nativa"
            ], 
            [
                "idmediopago" => "125",
                "descripcion" => "Tarjeta Cencosud Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta_Cencosud"
            ], 
            [
                "idmediopago" => "43",
                "descripcion" => "Tarjeta Cencosud",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta_Cencosud"
            ], 
            [
                "idmediopago" => "44",
                "descripcion" => "Tarjeta Carrefour",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Cetelem"
            ], 
            [
                "idmediopago" => "45",
                "descripcion" => "Tarjeta PymeNacion",
                "moneda" => "Pesos ARA",
                "tarjeta" => "PymeNacion"
            ], 
            [
                "idmediopago" => "46",
                "descripcion" => "PaySafeCard",
                "moneda" => "Pesos ARA",
                "tarjeta" => "PaySafeCard"
            ], 
            [
                "idmediopago" => "47",
                "descripcion" => "Monedero Online",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Monedero Online"
            ], 
            [
                "idmediopago" => "49",
                "descripcion" => "VisaAgro",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Agro"
            ], 
            [
                "idmediopago" => "48",
                "descripcion" => "Caja de Pagos",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Caja De Pagos"
            ], 
            [
                "idmediopago" => "126",
                "descripcion" => "BBPS Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "BBPS"
            ], 
            [
                "idmediopago" => "50",
                "descripcion" => "BBPS",
                "moneda" => "Pesos ARA",
                "tarjeta" => "BBPS"
            ], 
            [
                "idmediopago" => "51",
                "descripcion" => "Cobro Express",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Cobro Express"
            ], 
            [
                "idmediopago" => "127",
                "descripcion" => "Qida Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Qida"
            ], 
            [
                "idmediopago" => "52",
                "descripcion" => "Qida",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Qida"
            ], 
            [
                "idmediopago" => "53",
                "descripcion" => "LaPos Web Travel",
                "moneda" => "Pesos ARA",
                "tarjeta" => "LaPos Web Travel"
            ], 
            [
                "idmediopago" => "54",
                "descripcion" => "Grupar",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Grupar"
            ], 
            [
                "idmediopago" => "128",
                "descripcion" => "Patagonia 365 Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Patagonia 365"
            ], 
            [
                "idmediopago" => "55",
                "descripcion" => "Patagonia 365",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Patagonia 365"
            ], 
            [
                "idmediopago" => "129",
                "descripcion" => "Tarjeta Club Dia Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta Club Dia"
            ], 
            [
                "idmediopago" => "56",
                "descripcion" => "Tarjeta Club Dia",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta Club Dia"
            ], 
            [
                "idmediopago" => "130",
                "descripcion" => "Tarjeta TUYA Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta TUYA"
            ], 
            [
                "idmediopago" => "59",
                "descripcion" => "Tarjeta TUYA",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta TUYA"
            ], 
            [
                "idmediopago" => "60",
                "descripcion" => "Distribution",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Distribution"
            ], 
            [
                "idmediopago" => "61",
                "descripcion" => "La Anonima",
                "moneda" => "Pesos ARA",
                "tarjeta" => "La Anonima"
            ], 
            [
                "idmediopago" => "131",
                "descripcion" => "CrediGuia Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "CrediGuia"
            ], 
            [
                "idmediopago" => "62",
                "descripcion" => "CrediGuia",
                "moneda" => "Pesos ARA",
                "tarjeta" => "CrediGuia"
            ], 
            [
                "idmediopago" => "63",
                "descripcion" => "Cabal Prisma",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Cabal Prisma"
            ], 
            [
                "idmediopago" => "132",
                "descripcion" => "Tarjeta SOL Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta SOL"
            ], 
            [
                "idmediopago" => "64",
                "descripcion" => "Tarjeta SOL",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta SOL"
            ], 
            [
                "idmediopago" => "65",
                "descripcion" => "Amex",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Amex"
            ], 
            [
                "idmediopago" => "133",
                "descripcion" => "MC Debit Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "MC Debit"
            ], 
            [
                "idmediopago" => "66",
                "descripcion" => "MC Debit",
                "moneda" => "Pesos ARA",
                "tarjeta" => "MC Debit"
            ], 
            [
                "idmediopago" => "134",
                "descripcion" => "Cabal 24 Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Cabal 24"
            ], 
            [
                "idmediopago" => "67",
                "descripcion" => "Cabal 24",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Cabal 24"
            ], 
            [
                "idmediopago" => "69",
                "descripcion" => "Visa ICBC Campo",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa ICBC Campo"
            ], 
            [
                "idmediopago" => "70",
                "descripcion" => "Visa BBVA Frances AGRO",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa BBVA Frances AGRO"
            ], 
            [
                "idmediopago" => "71",
                "descripcion" => "Visa Supervielle Purchasing Ag",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Supervielle Purchasing Agro"
            ], 
            [
                "idmediopago" => "73",
                "descripcion" => "Visa Tarjeta Patagonia Agro",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Tarjeta Patagonia Agro"
            ], 
            [
                "idmediopago" => "74",
                "descripcion" => "Visa BSJ AGRO",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa BSJ AGRO"
            ], 
            [
                "idmediopago" => "75",
                "descripcion" => "Visa BERSA AGRO",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa BERSA AGRO"
            ], 
            [
                "idmediopago" => "78",
                "descripcion" => "Visa Macro Agro",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Macro Agro"
            ], 
            [
                "idmediopago" => "77",
                "descripcion" => "Visa Agro BMRosario",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Agro BMRosario"
            ], 
            [
                "idmediopago" => "80",
                "descripcion" => "Visa Tarjeta Santander Rio Agr",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Tarjeta Santander Rio Agro"
            ], 
            [
                "idmediopago" => "81",
                "descripcion" => "Visa Agro Regional Cuyo",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Agro Regional Cuyo"
            ], 
            [
                "idmediopago" => "82",
                "descripcion" => "Visa Tarjeta Agro Chubut",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Tarjeta Agro Chubut"
            ], 
            [
                "idmediopago" => "83",
                "descripcion" => "Visa Agro del BanCo",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Agro del BanCo"
            ], 
            [
                "idmediopago" => "84",
                "descripcion" => "Visa Tarjeta BANCOR AGRO",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Tarjeta BANCOR AGRO"
            ], 
            [
                "idmediopago" => "85",
                "descripcion" => "Visa Agro HSBC",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Agro HSBC"
            ], 
            [
                "idmediopago" => "87",
                "descripcion" => "Visa Agro Chaco",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Agro Chaco"
            ], 
            [
                "idmediopago" => "88",
                "descripcion" => "Visa Agro Banco Formosa",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Agro Banco Formosa"
            ], 
            [
                "idmediopago" => "89",
                "descripcion" => "Visa Bind Agro",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Bind Agro"
            ], 
            [
                "idmediopago" => "90",
                "descripcion" => "Visa Tarjeta Santa Fe Agro",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Tarjeta Santa Fe Agro"
            ], 
            [
                "idmediopago" => "91",
                "descripcion" => "Tarjeta Agronacion",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta Agronacion"
            ], 
            [
                "idmediopago" => "92",
                "descripcion" => "Tarjeta Pactar",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta Pactar"
            ], 
            [
                "idmediopago" => "93",
                "descripcion" => "Tarjeta Procampo",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta Procampo"
            ], 
            [
                "idmediopago" => "94",
                "descripcion" => "Tarjeta BICA AGRO",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta BICA AGRO"
            ], 
            [
                "idmediopago" => "95",
                "descripcion" => "Tarjeta Coinag AGRO",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta Coinag AGRO"
            ], 
            [
                "idmediopago" => "96",
                "descripcion" => "Agro Corrientes",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Agro Corrientes"
            ], 
            [
                "idmediopago" => "97",
                "descripcion" => "Agrocabal",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Agrocabal"
            ], 
            [
                "idmediopago" => "98",
                "descripcion" => "Tarjeta Galicia rural (TGR)",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Tarjeta Galicia rural (TGR)"
            ], 
            [
                "idmediopago" => "136",
                "descripcion" => "Maestro Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Maestro"
            ], 
            [
                "idmediopago" => "99",
                "descripcion" => "Maestro",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Maestro"
            ], 
            [
                "idmediopago" => "100",
                "descripcion" => "Visa Santander Distribution Ag",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Santander Distribution Agro"
            ], 
            [
                "idmediopago" => "101",
                "descripcion" => "Visa Macro Distribution Agro",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Macro Distribution Agro"
            ], 
            [
                "idmediopago" => "103",
                "descripcion" => "Favacard",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Favacard"
            ], 
            [
                "idmediopago" => "117",
                "descripcion" => "Favacard Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Favacard"
            ], 
            [
                "idmediopago" => "104",
                "descripcion" => "MasterCard Prisma",
                "moneda" => "Pesos ARA",
                "tarjeta" => "MasterCard Prisma"
            ], 
            [
                "idmediopago" => "105",
                "descripcion" => "MC Debit Prisma",
                "moneda" => "Pesos ARA",
                "tarjeta" => "MC Debit Prisma"
            ], 
            [
                "idmediopago" => "106",
                "descripcion" => "Maestro Prisma",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Maestro Prisma"
            ], 
            [
                "idmediopago" => "107",
                "descripcion" => "Cabal Débito Prisma",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Cabal Débito Prisma"
            ], 
            [
                "idmediopago" => "108",
                "descripcion" => "Cabal Débito Prisma",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Cabal Débito Prisma"
            ], 
            [
                "idmediopago" => "110",
                "descripcion" => "PEI",
                "moneda" => "Pesos ARA",
                "tarjeta" => "PEI"
            ], 
            [
                "idmediopago" => "111",
                "descripcion" => "Amex Prisma",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Amex Prisma"
            ], 
            [
                "idmediopago" => "113",
                "descripcion" => "SuCredito",
                "moneda" => "Pesos ARA",
                "tarjeta" => "SuCredito"
            ], 
            [
                "idmediopago" => "140",
                "descripcion" => "Confiable",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Confiable"
            ], 
            [
                "idmediopago" => "116",
                "descripcion" => "Master Prepaga",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Master Prepaga"
            ], 
            [
                "idmediopago" => "142",
                "descripcion" => "Master Prepaga Fiserv",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Master Prepaga"
            ], 
            [
                "idmediopago" => "115",
                "descripcion" => "Consumax",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Consumax"
            ], 
            [
                "idmediopago" => "114",
                "descripcion" => "Visa Prepaga",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Visa Prepaga"
            ], 
            [
                "idmediopago" => "139",
                "descripcion" => "Discover",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Discover"
            ], 
            [
                "idmediopago" => "138",
                "descripcion" => "Credi Guia",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Credi Guia"
            ], 
            [
                "idmediopago" => "141",
                "descripcion" => "Milenia",
                "moneda" => "Pesos ARA",
                "tarjeta" => "Milenia"
            ]
        ];
        return array_search($card_brand, array_column($payment_methods, 'tarjeta'));
    }

}