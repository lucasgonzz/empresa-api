<?php

namespace Database\Seeders;

use App\Models\PaisExportacion;
use Illuminate\Database\Seeder;

class PaisExportacionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $paises = [
            ['name' => 'Argentina', 'codigo_afip' => 200],
            ['name' => 'Bolivia', 'codigo_afip' => 202],
            ['name' => 'Brasil', 'codigo_afip' => 203],
            ['name' => 'Colombia', 'codigo_afip' => 205],
            ['name' => 'Costa Rica', 'codigo_afip' => 206],
            ['name' => 'Cuba', 'codigo_afip' => 207],
            ['name' => 'Chile', 'codigo_afip' => 208],
            ['name' => 'República Dominicana', 'codigo_afip' => 209],
            ['name' => 'Ecuador', 'codigo_afip' => 210],

            ['name' => 'ESTADOS UNIDOS  ESTADOS UNIDOS', 'codigo_afip' => 212, ],
            
            [
                'codigo_afip' => 101, 
                'name'        => 'BURKINA FASO    BURKINA FASO',
            ],
            [
                'codigo_afip' => 102, 
                'name'        => 'ARGELIA ARGELIA',
            ],
            [
                'codigo_afip' => 103, 
                'name'        => 'BOTSWANA    BOTSWANA',
            ],
            [
                'codigo_afip' => 104, 
                'name'        => 'BURUNDI BURUNDI',
            ],
            [
                'codigo_afip' => 105, 
                'name'        => 'CAMERUN CAMERUN',
            ],
            [
                'codigo_afip' => 107, 
                'name'        => 'REP. CENTROAFRICANA.    REP.CENTROAFRICANA',
            ],
            [
                'codigo_afip' => 108, 
                'name'        => 'CONGO   CONGO',
            ],
            [
                'codigo_afip' => 109, 
                'name'        => 'REP.DEMOCRAT.DEL CONGO EX ZAIRE REP. DEMOCRAT. DEL CONGO EX ZAIRE',
            ],
            [
                'codigo_afip' => 110, 
                'name'        => 'COSTA DE MARFIL COSTA DE MARFIL',
            ],
            [
                'codigo_afip' => 111, 
                'name'        => 'CHAD    CHAD',
            ],
            [
                'codigo_afip' => 112, 
                'name'        => 'BENIN   BENIN',
            ],
            [
                'codigo_afip' => 113, 
                'name'        => 'EGIPTO  EGIPTO',
            ],
            [
                'codigo_afip' => 115, 
                'name'        => 'GABON   GABON',
            ],
            [
                'codigo_afip' => 116, 
                'name'        => 'GAMBIA  GAMBIA',
            ],
            [
                'codigo_afip' => 117, 
                'name'        => 'GHANA   GHANA',
            ],
            [
                'codigo_afip' => 118, 
                'name'        => 'GUINEA  GUINEA',
            ],
            [
                'codigo_afip' => 119, 
                'name'        => 'GUINEA ECUATORIAL   GUINEA ECUATORIAL',
            ],
            [
                'codigo_afip' => 120, 
                'name'        => 'KENYA   KENYA',
            ],
            [
                'codigo_afip' => 121, 
                'name'        => 'LESOTHO LESOTHO',
            ],
            [
                'codigo_afip' => 122, 
                'name'        => 'LIBERIA LIBERIA',
            ],
            [
                'codigo_afip' => 123, 
                'name'        => 'LIBIA   LIBIA',
            ],
            [
                'codigo_afip' => 124, 
                'name'        => 'MADAGASCAR  MADAGASCAR',
            ],
            [
                'codigo_afip' => 125, 
                'name'        => 'MALAWI  MALAWI',
            ],
            [
                'codigo_afip' => 126, 
                'name'        => 'MALI    MALI',
            ],
            [
                'codigo_afip' => 127, 
                'name'        => 'MARRUECOS   MARRUECOS',
            ],
            [
                'codigo_afip' => 128, 
                'name'        => 'MAURICIO,ISLAS  MAURICIO,ISLAS',
            ],
            [
                'codigo_afip' => 129, 
                'name'        => 'MAURITANIA  MAURITANIA',
            ],
            [
                'codigo_afip' => 130, 
                'name'        => 'NIGER   NIGER',
            ],
            [
                'codigo_afip' => 131, 
                'name'        => 'NIGERIA NIGERIA',
            ],
            [
                'codigo_afip' => 132, 
                'name'        => 'ZIMBABWE    ZIMBABWE',
            ],
            [
                'codigo_afip' => 133, 
                'name'        => 'RWANDA  RWANDA',
            ],
            [
                'codigo_afip' => 134, 
                'name'        => 'SENEGAL SENEGAL',
            ],
            [
                'codigo_afip' => 135, 
                'name'        => 'SIERRA LEONA    SIERRA LEONA',
            ],
            [
                'codigo_afip' => 136, 
                'name'        => 'SOMALIA SOMALIA',
            ],
            [
                'codigo_afip' => 137, 
                'name'        => 'SWAZILANDIA SWAZILANDIA',
            ],
            [
                'codigo_afip' => 138, 
                'name'        => 'SUDAN   SUDAN',
            ],
            [
                'codigo_afip' => 139, 
                'name'        => 'TANZANIA    TANZANIA',
            ],
            [
                'codigo_afip' => 140, 
                'name'        => 'TOGO    TOGO',
            ],
            [
                'codigo_afip' => 141, 
                'name'        => 'TUNEZ   TUNEZ',
            ],
            [
                'codigo_afip' => 142, 
                'name'        => 'UGANDA  UGANDA',
            ],
            [
                'codigo_afip' => 143, 
                'name'        => 'REPUBLICA DE SUDAFRICA  REPUBLICA DE SUDAFRICA',
            ],
            [
                'codigo_afip' => 144, 
                'name'        => 'ZAMBIA  ZAMBIA',
            ],
            [
                'codigo_afip' => 145, 
                'name'        => 'TERRIT.VINCULADOS AL R UNIDO    AFRICA',
            ],
            [
                'codigo_afip' => 146, 
                'name'        => 'TERRIT.VINCULADOS A ESPAÑA  AFRICA',
            ],
            [
                'codigo_afip' => 147, 
                'name'        => 'TERRIT.VINCULADOS A FRANCIA AFRICA',
            ],
            [
                'codigo_afip' => 148, 
                'name'        => 'TERRIT.VINCULADOS A PORTUGAL    AFRICA',
            ],
            [
                'codigo_afip' => 149, 
                'name'        => 'ANGOLA  ANGOLA',
            ],
            [
                'codigo_afip' => 150, 
                'name'        => 'CABO VERDE  ISLAS',
            ],
            [
                'codigo_afip' => 151, 
                'name'        => 'MOZAMBIQUE  MOZAMBIQUE',
            ],
            [
                'codigo_afip' => 152, 
                'name'        => 'SEYCHELLES  SEYCHELLES',
            ],
            [
                'codigo_afip' => 153, 
                'name'        => 'DJIBOUTI    DJIBOUTI',
            ],
            [
                'codigo_afip' => 155, 
                'name'        => 'COMORAS COMORAS',
            ],
            [
                'codigo_afip' => 156, 
                'name'        => 'GUINEA BISSAU   GUINEA BISSAU',
            ],
            [
                'codigo_afip' => 157, 
                'name'        => 'STO.TOME Y PRINCIPE STO.TOME Y PRINCIPE',
            ],
            [
                'codigo_afip' => 158, 
                'name'        => 'NAMIBIA NAMIBIA',
            ],
            [
                'codigo_afip' => 159, 
                'name'        => 'SUDAFRICA   SUDAFRICA',
            ],
            [
                'codigo_afip' => 160, 
                'name'        => 'ERITREA ERITREA',
            ],
            [
                'codigo_afip' => 161, 
                'name'        => 'ETIOPIA ETIOPIA',
            ],
            [
                'codigo_afip' => 197, 
                'name'        => 'RESTO (AFRICA)  RESTO (AFRICA)',
            ],
            [
                'codigo_afip' => 198, 
                'name'        => 'INDETERMINADO (AFRICA)  INDETERMINADO AFRICA)',
            ],
            // [
            //     'codigo_afip' => 200, 
            //     'name'        => 'ARGENTINA   ARGENTINA',
            // ],
            [
                'codigo_afip' => 201, 
                'name'        => 'BARBADOS    BARBADOS',
            ],
            // [
            //     'codigo_afip' => 202, 
            //     'name'        => 'BOLIVIA BOLIVIA',
            // ],
            // [
            //     'codigo_afip' => 203, 
            //     'name'        => 'BRASIL  BRASIL',
            // ],
            [
                'codigo_afip' => 204, 
                'name'        => 'CANADA  CANADA',
            ],
            // [
            //     'codigo_afip' => 205, 
            //     'name'        => 'COLOMBIA    COLOMBIA',
            // ],
            // [
            //     'codigo_afip' => 206, 
            //     'name'        => 'COSTA RICA  COSTA RICA',
            // ],
            // [
            //     'codigo_afip' => 207, 
            //     'name'        => 'CUBA    CUBA',
            // ],
            // [
            //     'codigo_afip' => 208, 
            //     'name'        => 'CHILE   CHILE',
            // ],
            // [
            //     'codigo_afip' => 209, 
            //     'name'        => 'DOMINICANA,REP. DOMINICANA,REP.',
            // ],
            // [
            //     'codigo_afip' => 210, 
            //     'name'        => 'ECUADOR ECUADOR',
            // ],
            [
                'codigo_afip' => 211, 
                'name'        => 'EL SALVADOR EL SALVADOR',
            ],
            // [
            //     'codigo_afip' => 212, 
            //     'name'        => 'ESTADOS UNIDOS  ESTADOS UNIDOS',
            // ],
            [
                'codigo_afip' => 213, 
                'name'        => 'GUATEMALA   GUATEMALA',
            ],
            [
                'codigo_afip' => 214, 
                'name'        => 'GUYANA  GUYANA',
            ],
            [
                'codigo_afip' => 215, 
                'name'        => 'HAITI   HAITI',
            ],
            [
                'codigo_afip' => 216, 
                'name'        => 'HONDURAS    HONDURAS',
            ],
            [
                'codigo_afip' => 217, 
                'name'        => 'JAMAICA JAMAICA',
            ],
            [
                'codigo_afip' => 218, 
                'name'        => 'MEXICO  MEXICO',
            ],
            [
                'codigo_afip' => 219, 
                'name'        => 'NICARAGUA   NICARAGUA',
            ],
            [
                'codigo_afip' => 220, 
                'name'        => 'PANAMA  PANAMA',
            ],
            [
                'codigo_afip' => 221, 
                'name'        => 'PARAGUAY    PARAGUAY',
            ],
            [
                'codigo_afip' => 222, 
                'name'        => 'PERU    PERU',
            ],
            [
                'codigo_afip' => 223, 
                'name'        => 'PUERTO RICO ESTADO ASOCIADO',
            ],
            [
                'codigo_afip' => 224, 
                'name'        => 'TRINIDAD Y -TOBAGO  TRINIDAD Y TOBAGO',
            ],
            [
                'codigo_afip' => 225, 
                'name'        => 'URUGUAY URUGUAY',
            ],
            [
                'codigo_afip' => 226, 
                'name'        => 'VENEZUELA   VENEZUELA',
            ],
            [
                'codigo_afip' => 227, 
                'name'        => 'TERRIT.VINCULADO AL R.UNIDO AMERICA',
            ],
            [
                'codigo_afip' => 228, 
                'name'        => 'TER.VINCULADOS A DINAMARCA  AMERICA',
            ],
            [
                'codigo_afip' => 229, 
                'name'        => 'TERRIT.VINCULADOS A FRANCIA AMERIC. AMERICA',
            ],
            [
                'codigo_afip' => 230, 
                'name'        => 'TERRIT. HOLANDESES  TERRIT. HOLANDESES',
            ],
            [
                'codigo_afip' => 231, 
                'name'        => 'TER.VINCULADOS A ESTADOS UNIDOS AMERICA',
            ],
            [
                'codigo_afip' => 232, 
                'name'        => 'SURINAME    SURINAME',
            ],
            [
                'codigo_afip' => 233, 
                'name'        => 'DOMINICA    DOMINICA',
            ],
            [
                'codigo_afip' => 234, 
                'name'        => 'SANTA LUCIA SANTA LUCIA',
            ],
            [
                'codigo_afip' => 235, 
                'name'        => 'SAN VICENTE Y LAS GRANADINS SAN VICENTE Y LAS GRANADINAS',
            ],
            [
                'codigo_afip' => 236, 
                'name'        => 'BELICE  BELICE',
            ],
            [
                'codigo_afip' => 237, 
                'name'        => 'ANTIGUA Y BARBUDA   ANTIGUA Y BARBUDA',
            ],
            [
                'codigo_afip' => 238, 
                'name'        => 'S.CRISTOBAL Y NEVIS S.CRISTOBAL Y NEVIS',
            ],
            [
                'codigo_afip' => 239, 
                'name'        => 'BAHAMAS BAHAMAS',
            ],
            [
                'codigo_afip' => 240, 
                'name'        => 'GRANADA GRANADA',
            ],
            [
                'codigo_afip' => 241, 
                'name'        => 'ANTILLAS HOLANDESAS TERRI.VINC.A PAISES BAJOS',
            ],
            [
                'codigo_afip' => 242, 
                'name'        => 'ARUBA   ',
            ],
            [
                'codigo_afip' => 250, 
                'name'        => 'TIERRA DEL FUEGO    (AAE)',
            ],
            [
                'codigo_afip' => 251, 
                'name'        => 'ZF LA PLATA BUENOS AIRES',
            ],
            [
                'codigo_afip' => 252, 
                'name'        => 'ZF JUSTO DARACT SAN LUIS',
            ],
            [
                'codigo_afip' => 253, 
                'name'        => 'ZF RIO GALLEGOS SANTA CRUZ',
            ],
            [
                'codigo_afip' => 254, 
                'name'        => 'ISLAS MALVINAS  ISLAS MALVINAS',
            ],
            [
                'codigo_afip' => 255, 
                'name'        => 'ZF TUCUMAN  TUCUMAN',
            ],
            [
                'codigo_afip' => 256, 
                'name'        => 'ZF CORDOBA  CORDOBA',
            ],
            [
                'codigo_afip' => 257, 
                'name'        => 'ZF MENDOZA  MENDOZA',
            ],
            [
                'codigo_afip' => 258, 
                'name'        => 'ZF GENERAL PICO LA PAMPA',
            ],
            [
                'codigo_afip' => 259, 
                'name'        => 'ZF COMODORO RIVADAVIA   CHUBUT',
            ],
            [
                'codigo_afip' => 260, 
                'name'        => 'ZF IQUIQUE  CHILE',
            ],
            [
                'codigo_afip' => 261, 
                'name'        => 'ZF PUNTA ARENAS CHILE',
            ],
            [
                'codigo_afip' => 262, 
                'name'        => 'ZF SALTA    SALTA',
            ],
            [
                'codigo_afip' => 263, 
                'name'        => 'ZF PASO DE LOS LIBRES   CORRIENTES',
            ],
            [
                'codigo_afip' => 264, 
                'name'        => 'ZF PUERTO IGUAZU    MISIONES',
            ],
            [
                'codigo_afip' => 265, 
                'name'        => 'SECTOR ANTARTICO ARG.   SECTOR ANTARTICO ARG.',
            ],
            [
                'codigo_afip' => 270, 
                'name'        => 'ZF COLON    PANAMA',
            ],
            [
                'codigo_afip' => 271, 
                'name'        => 'ZF WINNER (STA. C.DE LA SIERRA  BOLIVIA',
            ],
            [
                'codigo_afip' => 280, 
                'name'        => 'ZF COLONIA  URUGUAY',
            ],
            [
                'codigo_afip' => 281, 
                'name'        => 'ZF FLORIDA  URUGUAY',
            ],
            [
                'codigo_afip' => 282, 
                'name'        => 'ZF LIBERTAD URUGUAY',
            ],
            [
                'codigo_afip' => 283, 
                'name'        => 'ZF ZONAMERICA   EX MONTEVIDEO URUGUAY',
            ],
            [
                'codigo_afip' => 284, 
                'name'        => 'ZF NUEVA HELVECIA   URUGUAY',
            ],
            [
                'codigo_afip' => 285, 
                'name'        => 'ZF NUEVA PALMIRA    URUGUAY',
            ],
            [
                'codigo_afip' => 286, 
                'name'        => 'ZF RIO NEGRO    URUGUAY',
            ],
            [
                'codigo_afip' => 287, 
                'name'        => 'ZF RIVERA   URUGUAY',
            ],
            [
                'codigo_afip' => 288, 
                'name'        => 'ZF SAN JOSE URUGUAY',
            ],
            [
                'codigo_afip' => 291, 
                'name'        => 'ZF MANAOS   BRASIL',
            ],
            [
                'codigo_afip' => 295, 
                'name'        => 'MAR ARG ZONA ECO.EX ARGENTINA',
            ],
            [
                'codigo_afip' => 296, 
                'name'        => 'RIOS ARG NAVEG INTER    ARGENTINA',
            ],
            [
                'codigo_afip' => 297, 
                'name'        => 'RESTO AMERICA   RESTO AMERICA',
            ],
            [
                'codigo_afip' => 298, 
                'name'        => 'INDETERMINADO.(AMERICA) INDETERMINADO.(AMERICA)',
            ],
            [
                'codigo_afip' => 301, 
                'name'        => 'AFGANISTAN  AFGANISTAN',
            ],
            [
                'codigo_afip' => 302, 
                'name'        => 'ARABIA SAUDITA  ARABIA SAUDITA',
            ],
            [
                'codigo_afip' => 303, 
                'name'        => 'BAHREIN BAHREIN',
            ],
            [
                'codigo_afip' => 304, 
                'name'        => 'MYANMAR(EX-BIRMANIA)    MYANMAR(EX-BIRMANIA)',
            ],
            [
                'codigo_afip' => 305, 
                'name'        => 'BUTAN   BUTAN',
            ],
            [
                'codigo_afip' => 306, 
                'name'        => 'CAMBODYA(EX-KAMPUCHE    CAMBODYA(EX-KAMPUCHE',
            ],
            [
                'codigo_afip' => 307, 
                'name'        => 'SRI LANKA   SRI LANKA',
            ],
            [
                'codigo_afip' => 308, 
                'name'        => 'COREA DEMOCRATICA   COREA DEMOCRATICA',
            ],
            [
                'codigo_afip' => 309, 
                'name'        => 'COREA REPUBLICANA   COREA REPUBLICANA',
            ],
            [
                'codigo_afip' => 310, 
                'name'        => 'CHINA   CHINA',
            ],
            [
                'codigo_afip' => 311, 
                'name'        => 'CHIPRE  CHIPRE',
            ],
            [
                'codigo_afip' => 312, 
                'name'        => 'FILIPINAS   FILIPINAS',
            ],
            [
                'codigo_afip' => 313, 
                'name'        => 'TAIWAN  TAIWAN',
            ],
            [
                'codigo_afip' => 314, 
                'name'        => 'GAZA    GAZA',
            ],
            [
                'codigo_afip' => 315, 
                'name'        => 'INDIA   INDIA',
            ],
            [
                'codigo_afip' => 316, 
                'name'        => 'INDONESIA   INDONESIA',
            ],
            [
                'codigo_afip' => 317, 
                'name'        => 'IRAK    IRAK',
            ],
            [
                'codigo_afip' => 318, 
                'name'        => 'IRAN    IRAN',
            ],
            [
                'codigo_afip' => 319, 
                'name'        => 'ISRAEL  ISRAEL',
            ],
            [
                'codigo_afip' => 320, 
                'name'        => 'JAPON   JAPON',
            ],
            [
                'codigo_afip' => 321, 
                'name'        => 'JORDANIA    JORDANIA',
            ],
            [
                'codigo_afip' => 322, 
                'name'        => 'QATAR   QATAR',
            ],
            [
                'codigo_afip' => 323, 
                'name'        => 'KUWAIT  KUWAIT',
            ],
            [
                'codigo_afip' => 324, 
                'name'        => 'LAOS    LAOS',
            ],
            [
                'codigo_afip' => 325, 
                'name'        => 'LIBANO  LIBANO',
            ],
            [
                'codigo_afip' => 326, 
                'name'        => 'MALASIA MALASIA',
            ],
            [
                'codigo_afip' => 327, 
                'name'        => 'MALDIVAS ISLAS  MALDIVAS ISLAS',
            ],
            [
                'codigo_afip' => 328, 
                'name'        => 'OMAN    OMAN',
            ],
            [
                'codigo_afip' => 329, 
                'name'        => 'MONGOLIA    MONGOLIA',
            ],
            [
                'codigo_afip' => 330, 
                'name'        => 'NEPAL   NEPAL',
            ],
            [
                'codigo_afip' => 331, 
                'name'        => 'EMIRATOS ARABES,UNID    EMIRATOS ARABES,UNID',
            ],
            [
                'codigo_afip' => 332, 
                'name'        => 'PAKISTAN    PAKISTAN',
            ],
            [
                'codigo_afip' => 333, 
                'name'        => 'SINGAPUR    SINGAPUR',
            ],
            [
                'codigo_afip' => 334, 
                'name'        => 'SIRIA   SIRIA',
            ],
            [
                'codigo_afip' => 335, 
                'name'        => 'THAILANDIA  THAILANDIA',
            ],
            [
                'codigo_afip' => 336, 
                'name'        => 'TURQUIA TURQUIA',
            ],
            [
                'codigo_afip' => 337, 
                'name'        => 'VIETNAM VIETNAM',
            ],
            [
                'codigo_afip' => 341, 
                'name'        => 'HONG KONG   REG.ADM.ESP. DE CHINA',
            ],
            [
                'codigo_afip' => 344, 
                'name'        => 'MACAO   MACAO(REG.ADM.ESPEC)',
            ],
            [
                'codigo_afip' => 345, 
                'name'        => 'BANGLADESH  BANGLADESH',
            ],
            [
                'codigo_afip' => 346, 
                'name'        => 'BRUNEI  BRUNEI',
            ],
            [
                'codigo_afip' => 348, 
                'name'        => 'REPUBLICA DE YEMEN  REPUBLICA DE YEMEN',
            ],
            [
                'codigo_afip' => 349, 
                'name'        => 'ARMENIA ARMENIA',
            ],
            [
                'codigo_afip' => 350, 
                'name'        => 'AZERBAIJAN  AZERBAIJAN',
            ],
            [
                'codigo_afip' => 351, 
                'name'        => 'GEORGIA GEORGIA',
            ],
            [
                'codigo_afip' => 352, 
                'name'        => 'KAZAJSTAN   KAZAJSTAN',
            ],
            [
                'codigo_afip' => 353, 
                'name'        => 'KIRGUIZISTAN    KIRGUIZISTAN',
            ],
            [
                'codigo_afip' => 354, 
                'name'        => 'TAYIKISTAN  TAYIKISTAN',
            ],
            [
                'codigo_afip' => 355, 
                'name'        => 'TURKMENISTAN    TURKMENISTAN',
            ],
            [
                'codigo_afip' => 356, 
                'name'        => 'UZBEKISTAN  UZBEKISTAN',
            ],
            [
                'codigo_afip' => 357, 
                'name'        => 'TERR. AU. PALESTINOS    GAZA Y JERICO',
            ],
            [
                'codigo_afip' => 358, 
                'name'        => 'TIMOR ORIENTAL  ',
            ],
            [
                'codigo_afip' => 397, 
                'name'        => 'RESTO DE ASIA   RESTO DE ASIA',
            ],
            [
                'codigo_afip' => 398, 
                'name'        => 'INDET.(ASIA)    INDET.(ASIA)',
            ],
            [
                'codigo_afip' => 401, 
                'name'        => 'ALBANIA ALBANIA',
            ],
            [
                'codigo_afip' => 402, 
                'name'        => 'ALEMANIA FEDERAL    ALEMANIA FEDERAL',
            ],
            [
                'codigo_afip' => 403, 
                'name'        => 'ALEMANIA ORIENTAL   ALEMANIA ORIENTAL',
            ],
            [
                'codigo_afip' => 404, 
                'name'        => 'ANDORRA ANDORRA',
            ],
            [
                'codigo_afip' => 405, 
                'name'        => 'AUSTRIA AUSTRIA',
            ],
            [
                'codigo_afip' => 406, 
                'name'        => 'BELGICA BELGICA',
            ],
            [
                'codigo_afip' => 407, 
                'name'        => 'BULGARIA    BULGARIA',
            ],
            [
                'codigo_afip' => 408, 
                'name'        => 'CHECOSLOVAQUIA  CHECOSLOVAQUIA',
            ],
            [
                'codigo_afip' => 409, 
                'name'        => 'DINAMARCA   DINAMARCA',
            ],
            [
                'codigo_afip' => 410, 
                'name'        => 'ESPAÑA  ESPAÑA',
            ],
            [
                'codigo_afip' => 411, 
                'name'        => 'FINLANDIA   FINLANDIA',
            ],
            [
                'codigo_afip' => 412, 
                'name'        => 'FRANCIA FRANCIA',
            ],
            [
                'codigo_afip' => 413, 
                'name'        => 'GRECIA  GRECIA',
            ],
            [
                'codigo_afip' => 414, 
                'name'        => 'HUNGRIA HUNGRIA',
            ],
            [
                'codigo_afip' => 415, 
                'name'        => 'IRLANDA IRLANDA',
            ],
            [
                'codigo_afip' => 416, 
                'name'        => 'ISLANDIA    ISLANDIA',
            ],
            [
                'codigo_afip' => 417, 
                'name'        => 'ITALIA  ITALIA',
            ],
            [
                'codigo_afip' => 418, 
                'name'        => 'LIECHTENSTEIN   LIECHTENSTEIN',
            ],
            [
                'codigo_afip' => 419, 
                'name'        => 'LUXEMBURGO  LUXEMBURGO',
            ],
            [
                'codigo_afip' => 420, 
                'name'        => 'MALTA   MALTA',
            ],
            [
                'codigo_afip' => 421, 
                'name'        => 'MONACO  MONACO',
            ],
            [
                'codigo_afip' => 422, 
                'name'        => 'NORUEGA NORUEGA',
            ],
            [
                'codigo_afip' => 423, 
                'name'        => 'PAISES BAJOS    PAISES BAJOS',
            ],
            [
                'codigo_afip' => 424, 
                'name'        => 'POLONIA POLONIA',
            ],
            [
                'codigo_afip' => 425, 
                'name'        => 'PORTUGAL    PORTUGAL',
            ],
            [
                'codigo_afip' => 426, 
                'name'        => 'REINO UNIDO REINO UNIDO',
            ],
            [
                'codigo_afip' => 427, 
                'name'        => 'RUMANIA RUMANIA',
            ],
            [
                'codigo_afip' => 428, 
                'name'        => 'SAN MARINO  SAN MARINO',
            ],
            [
                'codigo_afip' => 429, 
                'name'        => 'SUECIA  SUECIA',
            ],
            [
                'codigo_afip' => 430, 
                'name'        => 'SUIZA   SUIZA',
            ],
            [
                'codigo_afip' => 431, 
                'name'        => 'VATICANO(SANTA SEDE)    VATICANO(SENTA SEDE)',
            ],
            [
                'codigo_afip' => 432, 
                'name'        => 'YUGOSLAVIA  ',
            ],
            [
                'codigo_afip' => 433, 
                'name'        => 'POS.BRIT.(EUROPA)   POS.BRIT.(EUROPA)',
            ],
            [
                'codigo_afip' => 434, 
                'name'        => 'HOLANDA HOLANDA',
            ],
            [
                'codigo_afip' => 435, 
                'name'        => 'CHIPRE  CHIPRE',
            ],
            [
                'codigo_afip' => 436, 
                'name'        => 'TURQUIA TURQUIA',
            ],
            [
                'codigo_afip' => 438, 
                'name'        => 'ALEMANIA,REP.FED.   ALEMANIA,REP.FED.',
            ],
            [
                'codigo_afip' => 439, 
                'name'        => 'BIELORRUSIA BIELORRUSIA',
            ],
            [
                'codigo_afip' => 440, 
                'name'        => 'ESTONIA ESTONIA',
            ],
            [
                'codigo_afip' => 441, 
                'name'        => 'LETONIA LETONIA',
            ],
            [
                'codigo_afip' => 442, 
                'name'        => 'LITUANIA    LITUANIA',
            ],
            [
                'codigo_afip' => 443, 
                'name'        => 'MOLDAVIA    MOLDAVIA',
            ],
            [
                'codigo_afip' => 444, 
                'name'        => 'RUSIA   RUSIA',
            ],
            [
                'codigo_afip' => 445, 
                'name'        => 'UCRANIA UCRANIA',
            ],
            [
                'codigo_afip' => 446, 
                'name'        => 'BOSNIA HERZEGOVINA  BOSNIA HERZEGOVINA',
            ],
            [
                'codigo_afip' => 447, 
                'name'        => 'CROACIA CROACIA',
            ],
            [
                'codigo_afip' => 448, 
                'name'        => 'ESLOVAQUIA  ESLOVAQUIA',
            ],
            [
                'codigo_afip' => 449, 
                'name'        => 'ESLOVENIA   ESLOVENIA',
            ],
            [
                'codigo_afip' => 450, 
                'name'        => 'MACEDONIA   MACEDONIA',
            ],
            [
                'codigo_afip' => 451, 
                'name'        => 'REP. CHECA  REP. CHECA',
            ],
            [
                'codigo_afip' => 452, 
                'name'        => 'FED. SER Y MONT YOGOE   ',
            ],
            [
                'codigo_afip' => 453, 
                'name'        => 'MONTENEGRO  MONTENEGRO',
            ],
            [
                'codigo_afip' => 454, 
                'name'        => 'SERBIA  SERBIA',
            ],
            [
                'codigo_afip' => 497, 
                'name'        => 'RESTO EUROPA    RESTO EUROPA',
            ],
            [
                'codigo_afip' => 498, 
                'name'        => 'INDET.(EUROPA)  INDET.(EUROPA)',
            ],
            [
                'codigo_afip' => 501, 
                'name'        => 'AUSTRALIA   AUSTRALIA',
            ],
            [
                'codigo_afip' => 503, 
                'name'        => 'NAURU   NAURU',
            ],
            [
                'codigo_afip' => 504, 
                'name'        => 'NUEVA ZELANDIA  NUEVA ZELANDIA',
            ],
            [
                'codigo_afip' => 505, 
                'name'        => 'VANATU  VANATU',
            ],
            [
                'codigo_afip' => 506, 
                'name'        => 'SAMOA OCCIDENTAL    SAMOA OCCIDENTAL',
            ],
            [
                'codigo_afip' => 507, 
                'name'        => 'TERRITORIO VINCULADOS A AUSTRALIA   OCEANIA',
            ],
            [
                'codigo_afip' => 508, 
                'name'        => 'TERRITORIOS VINCULADOS AL R. UNIDO  OCEANIA',
            ],
            [
                'codigo_afip' => 509, 
                'name'        => 'TERRITORIOS VINCULADOS A FRANCIA    OCEANIA',
            ],
            [
                'codigo_afip' => 510, 
                'name'        => 'TER VINCULADOS A NUEVA. ZELANDA OCEANIA',
            ],
            [
                'codigo_afip' => 511, 
                'name'        => 'TER. VINCULADOS A ESTADOS UNIDOS    OCEANIA',
            ],
            [
                'codigo_afip' => 512, 
                'name'        => 'FIJI, ISLAS FIJI, ISLAS',
            ],
            [
                'codigo_afip' => 513, 
                'name'        => 'PAPUA NUEVA GUINEA  PAPUA NUEVA GUINEA',
            ],
            [
                'codigo_afip' => 514, 
                'name'        => 'KIRIBATI, ISLAS KIRIBATI, ISLAS',
            ],
            [
                'codigo_afip' => 515, 
                'name'        => 'MICRONESIA,EST.FEDER    MICRONESIA,EST.FEDER',
            ],
            [
                'codigo_afip' => 516, 
                'name'        => 'PALAU   PALAU',
            ],
            [
                'codigo_afip' => 517, 
                'name'        => 'TUVALU  TUVALU',
            ],
            [
                'codigo_afip' => 518, 
                'name'        => 'SALOMON,ISLAS   SALOMON,ISLAS',
            ],
            [
                'codigo_afip' => 519, 
                'name'        => 'TONGA   TONGA',
            ],
            [
                'codigo_afip' => 520, 
                'name'        => 'MARSHALL,ISLAS  MARSHALL,ISLAS',
            ],
            [
                'codigo_afip' => 521, 
                'name'        => 'MARIANAS,ISLAS  MARIANAS,ISLAS',
            ],
            [
                'codigo_afip' => 597, 
                'name'        => 'RESTO OCEANIA   RESTO OCEANIA',
            ],
            [
                'codigo_afip' => 598, 
                'name'        => 'INDET.(OCEANIA) INDET.(OCEANIA)',
            ],
            [
                'codigo_afip' => 601, 
                'name'        => 'URSS    URSS',
            ],
            [
                'codigo_afip' => 652, 
                'name'        => 'ANGUILA (TERRITORIO NO AUTONOMO DEL R. UNIDO)   ANGUILA (TERRITORIO NO AUTONOMO DEL R. UNIDO)',
            ],
            [
                'codigo_afip' => 659, 
                'name'        => 'ANTILLAS HOLANDESAS (TERRITORIO DE PAISES BAJOS)    ',
            ],
            [
                'codigo_afip' => 653, 
                'name'        => 'ARUBA (TERRITORIO DE PAISES BAJOS)  ',
            ],
            [
                'codigo_afip' => 662, 
                'name'        => 'ASCENCION   ',
            ],
            [
                'codigo_afip' => 663, 
                'name'        => 'BERMUDAS (TERRITORIO NO AUTONOMO DEL R UNIDO)   ',
            ],
            [
                'codigo_afip' => 664, 
                'name'        => 'CAMPIONE DITALIA    ',
            ],
            [
                'codigo_afip' => 665, 
                'name'        => 'COLONIA DE GIBRALTAR    ',
            ],
            [
                'codigo_afip' => 666, 
                'name'        => 'GROENLANDIA ',
            ],
            [
                'codigo_afip' => 667, 
                'name'        => 'GUAM (TERRITORIO NO AUTONOMO DE LOS ESTADO UNIDOS   ',
            ],
            [
                'codigo_afip' => 668, 
                'name'        => 'HONG KONG (TERRITORIO DE CHINA) ',
            ],
            [
                'codigo_afip' => 669, 
                'name'        => 'ISLAS AZORES    ',
            ],
            [
                'codigo_afip' => 670, 
                'name'        => 'ISLAS DEL CANAL (GUERNESEY, JERSEY, ALDERNEY,G.STARK, L.SARK, ETC)  ',
            ],
            [
                'codigo_afip' => 671, 
                'name'        => 'ISLAS CAIMAN (TERRITORIO NO AUTONOMO DE R UNIDO)    ',
            ],
            [
                'codigo_afip' => 672, 
                'name'        => 'ISLA CHRISTMAS  ',
            ],
            [
                'codigo_afip' => 673, 
                'name'        => 'ISLA DE COCOS O KEELING ',
            ],
            [
                'codigo_afip' => 654, 
                'name'        => 'ISLA DE COOK (TERRITORIO AUTONOMO ASOCIADO A NUEVA ZELANDA) ',
            ],
            [
                'codigo_afip' => 676, 
                'name'        => 'ISLA DE MAN (TERRITORIO DEL REINO UNIDO)    ',
            ],
            [
                'codigo_afip' => 677, 
                'name'        => 'ISLA DE NORFOLK (TERRITORIO DEL R UNIDO)    ',
            ],
            [
                'codigo_afip' => 678, 
                'name'        => 'ISALAS TURKAS Y CAICOS (TERRITORIO NO AUTONOMO DEL REINO UNIDO) ',
            ],
            [
                'codigo_afip' => 679, 
                'name'        => 'ISLAS PACIFICO  ',
            ],
            [
                'codigo_afip' => 680, 
                'name'        => 'ISLAS DE SAN PEDRO Y MIGUELON   ',
            ],
            [
                'codigo_afip' => 681, 
                'name'        => 'ISLA QESHM  ',
            ],
            [
                'codigo_afip' => 682, 
                'name'        => 'ISLAS VIRGENES BRITANICAS (TERRITORIO NO AUTONOMO DEL REINO UNIDO)  ',
            ],
            [
                'codigo_afip' => 683, 
                'name'        => 'ISLAS VIRGENES DE ESTADOS UNIDOS DE AMERICA ',
            ],
            [
                'codigo_afip' => 684, 
                'name'        => 'LABUAM  ',
            ],
            [
                'codigo_afip' => 685, 
                'name'        => 'MADEIRA (TERRITORIO DE PORTUGAL)    ',
            ],
            [
                'codigo_afip' => 686, 
                'name'        => 'MONSERRAT (TERRITORIO NO AUTONOMO DEL REINO UNIDO)  ',
            ],
            [
                'codigo_afip' => 687, 
                'name'        => 'NIUE    ',
            ],
            [
                'codigo_afip' => 655, 
                'name'        => 'PATAU   ',
            ],
            [
                'codigo_afip' => 690, 
                'name'        => 'PITCAIRN    ',
            ],
            [
                'codigo_afip' => 656, 
                'name'        => 'POLINESI FRANCESA (TERRITORIO DE ULTRAMAR DE FRANCIA)   ',
            ],
            [
                'codigo_afip' => 693, 
                'name'        => 'REGIMEN APLICABLE A LAS SA FINANCIERAS (LEY 11073 DE LA ROU)    ',
            ],
            [
                'codigo_afip' => 694, 
                'name'        => 'SANTA ELENA ',
            ],
            [
                'codigo_afip' => 695, 
                'name'        => 'SAMAO AMERICANA (TERRITORIO NO AUTONOMO DE LOS ESTADOS UNIDOS)  ',
            ],
            [
                'codigo_afip' => 696, 
                'name'        => 'ARCHIPIELAGO DE SVBALBARD   ',
            ],
            [
                'codigo_afip' => 697, 
                'name'        => 'TRISTAN DACUNHA ',
            ],
            [
                'codigo_afip' => 698, 
                'name'        => 'TRIESTE (ITALIA)    ',
            ],
            [
                'codigo_afip' => 699, 
                'name'        => 'TOKELAU ',
            ],
            [
                'codigo_afip' => 700, 
                'name'        => 'ZONA LIBRE DE OSTRAVA (CIUDAD DE LA ATIGUA CHECOSLOVAQUIA)  ',
            ],
            [
                'codigo_afip' => 997, 
                'name'        => 'RESTO CONTINENTE    RESTO CONTINENTE',
            ],
            [
                'codigo_afip' => 998, 
                'name'        => 'INDET.(CONTINENTE)  INDET.(CONTINENTE)',
            ],
            [
                'codigo_afip' => 999, 
                'name'        => 'OTROS PAISES    ',
            ],
        ];




        foreach ($paises as $pais) {
            $pais['name'] = $this->limpiarNombre($pais['name']);
            PaisExportacion::create($pais);
        }
    }

    function limpiarNombre($name)
    {
        // Normaliza espacios
        $name = trim(preg_replace('/\s+/', ' ', $name));

        // Divide en palabras
        $words = explode(' ', $name);

        // Elimina duplicados manteniendo orden
        $uniqueWords = array_values(array_unique($words));

        // Reconstruye el string
        return implode(' ', $uniqueWords);
    }
}
