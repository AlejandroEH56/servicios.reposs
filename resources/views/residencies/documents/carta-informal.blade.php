<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Carta de Presentación</title>
    <style>
        /* 1. CONFIGURACIÓN DE PÁGINA Y VARIABLES */
        @page {
            size: letter;
            margin: 0cm 2.3cm 1cm 2.3cm;
            /* Top, Right, Bottom, Left */
        }

        :root {
            --fuente-principal: 'Arial Narrow', Arial, sans-serif;
            --color-texto: #000000;
            --tamanio-base: 12pt;
            --tamanio-med: 11pt;
            --tamanio-peque: 10pt;
        }

        /* 2. RESET GLOBAL PARA PDF */
        body {
            font-family: var(--fuente-principal);
            font-size: var(--tamanio-base);
            color: var(--color-texto);
            line-height: 1.3;
            /* Mejora la lectura */
        }

        p {
            margin: 0 0 5px 0;
        }

        /* Margen por defecto pequeño para párrafos */

        table {
            width: 100%;
            border-collapse: collapse;
            border: 0;
        }

        td {
            vertical-align: top;
        }

        /* Alineación segura para tablas en PDF */

        /* 3. CLASES DE UTILIDAD (Espaciados) */

        .mt-20 {
            margin-top: 20px;
        }

        .mt-30 {
            margin-top: 30px;
        }

        .mt-50 {
            margin-top: 50px;
        }

        .mt-60 {
            margin-top: 60px;
        }

        .mt-70 {
            margin-top: 70px;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .text-justify {
            text-align: justify;
        }

        .bold {
            font-weight: bold;
        }

        .uppercase {
            text-transform: uppercase;
        }

        .underline {
            text-decoration: underline;
        }

        /* 4. COMPONENTES ESPECÍFICOS */

        /* Título del documento */
        .header-titulo {
            font-size: var(--tamanio-peque);
            font-weight: bold;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .linea-divisoria {
            border: 0;
            border-top: 2px solid #333399;
            margin: 0;
            padding: 0;
        }

        /* Cabecera con Logo y Fecha */
        .tabla-encabezado td {
            vertical-align: bottom;
            /* Alinea fecha abajo */
            padding-top: 5px;
        }

        .logo-img {
            width: 100px;
            height: 100px;
            object-fit: contain;
        }

        .fecha-texto {
            font-family: var(--fuente-principal);
            font-size: var(--tamanio-base);
            text-align: left;
            /* Espacio entre logo y texto */
            padding-bottom: 15px;
            margin-bottom: 0;
        }

        .paginado {
            font-size: var(--tamanio-base);
            text-align: right;
            vertical-align: top !important;
        }

        /* Bloque correspondencia */
        .a-corr p {
            font-family: var(--fuente-principal);
            font-size: var(--tamanio-base);
            font-weight: bold;
            text-transform: uppercase;
            margin: 0;
            padding: 0;
        }

        .saludo-presente {
            font-size: var(--tamanio-base);
            font-weight: bold;
            letter-spacing: 5px;
        }

        /* Cuerpo de la carta */
        .cuerpo-carta {
            font-size: 12pt;
            text-indent: 1.5cm;
            /* Sangría de primera línea */
            margin-bottom: 15px;
            /* Espacio entre párrafos del cuerpo */
        }

        /* Atentamente */
        .att-text {
            font-size: var(--tamanio-base);
            font-weight: bold;
            letter-spacing: 4px;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        /* Firmas */
        .seccion-firma {
            page-break-inside: avoid;
            /* Intenta no cortar la firma en dos hojas */
        }

        .nombre-firma {
            font-size: var(--tamanio-med);
            text-transform: uppercase;
            font-weight: bold;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .linea-firma {
            display: block;
            border-bottom: 2px solid black;
            width: 300px;
            margin: 0 auto 0 auto;
            padding: 0 auto 0 auto;
        }

        .cargo-firma {
            font-size: var(--tamanio-med);
            font-weight: bold;
            text-transform: uppercase;
            max-width: 350px;
            /* Evita que el cargo se estire demasiado */
            margin: 0 auto;
            margin-top: 0;
            padding-top: 0;
        }

        /* Pie de página */
        .ccp-texto {
            font-size: var(--tamanio-peque);
            text-align: left;
            margin-bottom: 20px;
        }

        .footer-institucional {
            text-align: right;
            font-size: var(--tamanio-peque);
            line-height: 1.2;
        }

        .footer-codigos {
            font-size: 9pt;
            margin-top: 35px;
        }
    </style>
</head>

<body>

    <div class="mt-60 text-right">
        <p class="header-titulo">CARTA DE PRESENTACIÓN</p>
    </div>

    <hr class="linea-divisoria">

    <table class="tabla-encabezado">
        <tr>
            <td style="width: 60px;">
                <?php foreach ($imagenes as $row) { ?>
                    <img class="logo-img" src="<?= e($row['data_uri']) ?>" />
                <?php } ?>
            </td>
            <td>
                <p class="fecha-texto">
                    Fecha: <?= e($data['dia'].' de '.$data['mes'].' del '.$data['anio']) ?>
                </p>
            </td>
            <td class="paginado">
                Páginas: 1 de 1
            </td>
        </tr>
    </table>

    <div class="mt-20 a-corr">
        <p>A quien corresponda:</p>
    </div>

    <div class="mt-50">
        <p class="saludo-presente">PRESENTE</p>
    </div>

    <div class="mt-20">
        <p class="cuerpo-carta text-justify">
            Por este conducto presento a Usted, a (el/la) <span class="bold">C.</span> <span class="bold underline"><?= e($data['nombre_residente']) ?></span> con
            número de control <span class="bold underline"><?= e($data['numero_control']) ?></span> de la carrera de <span class="bold underline"><?= e($data['programa_educativo']) ?></span> de este
            Instituto Tecnológico Superior de Huatusco, quien se presenta ante Usted, con el objeto de solicitar un
            espacio para que realice sus <span class="bold">Residencias Profesionales</span> a través del desarrollo de una actividad
            en particular, o la realización de un proyecto.
        </p>

        <p class="cuerpo-carta text-justify">
            En caso de que le brinden la oportunidad a (el/la) portador(a) del presente de realizar su
            Residencia, le solicitamos muy atentamente nos lo haga saber por medio de un oficio o carta de
            aceptación.
        </p>

        <p class="cuerpo-carta text-justify">
            Agradeciendo de antemano las atenciones brindadas me despido de usted esperando
            contar su apreciable colaboración.
        </p>
    </div>

    <div class="mt-60 text-center">
        <p class="att-text">ATENTAMENTE</p>
    </div>

    <div class="seccion-firma mt-70 text-center">
        <p class="nombre-firma"><?= e($data['nombre_jefe_depto']) ?></p>
        <div class="linea-firma"></div>
        <p class="cargo-firma"><?= e($data['jefe_depto']) ?></p>
    </div>

    <div class="mt-50">
        <p class="ccp-texto">C.c.p. Departamento de Residencias Profesionales y Servicio Social</p>
    </div>

    <div class="footer-institucional">
        Dom. Av. 25 Poniente No. 100 Col. Reserva Territorial<br>
        C.P. 94106, Huatusco, Ver. Tel: 273-73-4-40-00 ext.220<br>
        E-mail: residencias@huatusco.tecnm.mx
    </div>

    <table class="footer-codigos">
        <tr>
            <td class="text-left">R05/0820</td>
            <td class="text-right">F-RS-04</td>
        </tr>
    </table>

</body>

</html>