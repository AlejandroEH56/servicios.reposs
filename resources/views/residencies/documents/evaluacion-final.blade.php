<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formato de Evaluación y Seguimiento</title>
    <style>
        @page {
            size: letter;
            margin-top: 0cm;
            margin-right: 1.5cm;
            margin-bottom: 1cm;
            margin-left: 2cm;
        }

        @font-face {
            font-family: 'Berlin Sans FB Demi';
            font-style: normal;
            font-weight: bold;
            src: local('/public/formatos_RP/font4.woff') format('woff');
            font-display: swap;
        }

        @font-face {
            font-family: 'Berlin Sans FB Demi';
            font-style: normal;
            font-weight: bold;
            src: url('/var/www/servicios.proyecto/public/formatos_RP/font4.woff') format('woff');
        }

        :root {
            --font-family: 'Arial Narrow', sans-serif;
            --font-size: 8pt;
            --title-font-family: 'Berlin Sans FB Demi';
            --title-font-size: 12pt;
            --text-color: black;
            --border-color: black;
        }

        body {
            font-family: var(--font-family);
            font-size: var(--font-size);
            color: var(--text-color);
        }

        .imagenes-encabezado {
            width: 100%;
            height: 9%;
            position: absolute;
            left: 0px;
            top: 0px;
            overflow: hidden;
            display: flex;
        }

        .image-2 {
            flex: 1;
            height: 50%;
            margin-top: 30px;
            margin-right: 38px;
            object-fit: cover;
        }

        .image-2:first-child {
            margin-left: 0px;
            /* Elimina el margen izquierdo de la primera imagen */
        }

        .image-2:last-child {
            margin-right: 0;
            /* Elimina el margen derecho de la última imagen */
        }

        .title {
            text-align: center;
            font-family: 'Berlin Sans FB Demi', sans-serif;
            font-style: normal;
            font-weight: bold;
            font-size: var(--title-font-size);
            text-decoration: underline;
            padding-top: 80px;
            padding-bottom: 10px;
        }

        .info-container {
            display: flex;
            justify-content: space-around;
            margin-bottom: 20px;
        }

        .info {
            text-align: left;
            margin: 0;
        }

        .underline {
            border-bottom: 1px solid var(--border-color);
            width: fit-content;
            height: 1px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0px;
            margin-bottom: 0px;
            font-family: var(--font-family);
            font-size: var(--font-size);
        }

        th,
        td {
            border: 1px solid var(--border-color);
            padding: 0px 10px;
            text-align: left;
        }

        th {
            text-align: center;
            font-weight: bold;
        }

        .verticalCol {
            width: 30px;
            padding: 0px;
            margin: 0px;
        }

        .verticalText {
            /* writing-mode: vertical-rl; */
            transform: rotate(270deg);
            text-align: center;
            vertical-align: center;
            /* display: inline-block; */
            max-width: 100px;
            padding: 0px;
        }

        .valor-align {
            text-align: center;
        }

        .right-align {
            text-align: right;
        }

        .bold {
            font-weight: bold;
        }

        .info-section {
            width: 100%;
            padding-bottom: 5px;
        }

        .info-section td {
            margin-top: 0px;
            margin-bottom: 0px;
            border: none;
        }

        .info-section td.border-bt {
            border-bottom: 1px solid black;
        }

        .mark-section table p {
            font-size: 7pt;
            text-align: center;
            margin-top: 60px;
            margin-bottom: 0px;
        }

        .mark-section table {
            width: 100%;
            table-layout: fixed;
        }

        .mark-section table td {
            width: 33.33%;
            text-align: center;
        }

        .line {
            border-bottom: 1px solid var(--border-color);
            display: inline-block;
            width: 100%;
            margin: 0 0 0 3px;
        }

        .datos-tabla {
            width: 1%;
            white-space: nowrap;
            padding: 0;
            margin: 0;
        }

        .texto-fila {
            margin: 0 0 0 3px;
        }

        .fin-de-pagina {
            padding-top: 2px;
            display: flex;
            flex-direction: row;
            gap: 5px;
            align-items: center;
            justify-content: center;
            width: 100%;
            position: relative;
        }

        .info-pie-de-pagina-itsh {
            color: rgba(0, 0, 0, 0.5);
            text-align: center;
            font-family: "Montserrat-Medium", sans-serif;
            font-size: 9px;
            line-height: 1;
            font-weight: 500;
            position: relative;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>

<body>

    <div class="imagenes-encabezado">
        <?php foreach ($imagenes as $row) { ?>
            <img class="image-2" src="<?= e($row['data_uri']) ?>" />
        <?php } ?>
    </div>
    <div class="title">FORMATO DE EVALUACIÓN DE REPORTE DE RESIDENCIA PROFESIONAL</div>

    <div class="info-section">
        <table>
            <tr>
                <td class="datos-tabla">
                    <p class="texto-fila">Nombre del/la Residente:</p>
                </td>
                <td class="border-bt"><?= e($data['nombre_residente']) ?></td>
                <td class="datos-tabla">
                    <p class="texto-fila">No. de Control:</p>
                </td>
                <td class="border-bt" style="width: 100px;"><?= e($data['numero_control']) ?></td>
            </tr>
        </table>
        <table>
            <tr>
                <td class="datos-tabla">
                    <p class="texto-fila">Nombre del Proyecto:</p>
                </td>
                <td colspan="3" class="border-bt"><?= e($data['nombre_proyecto']) ?></td>
            </tr>
        </table>
        <table>
            <tr>
                <td class="datos-tabla">
                    <p class="texto-fila">Programa Educativo:</p>
                </td>
                <td colspan="3" class="border-bt"><?= e($data['programa_educativo']) ?></td>
            </tr>
        </table>
        <table>
            <tr>
                <td class="datos-tabla">
                    <p class="texto-fila">Período de Realización de Residencia Profesional:</p>
                </td>
                <td colspan="3" class="border-bt"><?= e($data['fecha_periodo']) ?></td>
            </tr>
        </table>
        <table>
            <tr>
                <td class="datos-tabla">
                    <p class="texto-fila">Calificación Parcial (promedio de ambas evaluaciones):</p>
                </td>
                <td colspan="3" class="border-bt"></td>
            </tr>
        </table>
    </div>
    <section id="asesor_externo">
        <table>
            <tbody>
                <tr>
                    <th colspan="4">En qué medida el residente cumple con lo siguiente</th>
                </tr>
                <tr>
                    <th colspan="2">Criterios a evaluar</th>
                    <th>Valor</th>
                    <th>Evaluación</th>
                </tr>
                <tr class="verticalCol">
                    <td rowspan="15">
                        <p class="verticalText">Evaluación por el Asesor Externo</p>
                    </td>
                    <td>Portada</td>
                    <td class="valor-align">2</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Agradecimientos</td>
                    <td class="valor-align">2</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Resumen</td>
                    <td class="valor-align">2</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Índice</td>
                    <td class="valor-align">2</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Introducción</td>
                    <td class="valor-align">2</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Problemas a resolver, priorizándolos</td>
                    <td class="valor-align">5</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Objetivos</td>
                    <td class="valor-align">5</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Justificación</td>
                    <td class="valor-align"> / </td>
                    <td></td>
                </tr>
                <tr>
                    <td>Marco teórico (fundamentos teóricos)</td>
                    <td class="valor-align">10</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Procedimiento y descripción de las actividades realizadas</td>
                    <td class="valor-align">5</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Resultados, planos, gráficas, prototipos, manuales, programas, análisis estadísticos, modelos
                        matemáticos, simulaciones, normativos, regulaciones y restricciones, entre otros. Solo para
                        proyectos que por su naturaleza lo requieran: estudio de mercado, estudio técnico y estudio
                        económico.</td>
                    <td class="valor-align">45</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Conclusiones, recomendaciones y experiencia profesional adquirida.</td>
                    <td class="valor-align">15</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Competencias desarrolladas y/o aplicadas</td>
                    <td class="valor-align">3</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Funetes de información</td>
                    <td class="valor-align">2</td>
                    <td></td>
                </tr>
                <tr>
                    <td class="bold right-align">Calificación total</td>
                    <td class="valor-align">100</td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <div class="info-section">
            <table style="padding-left: 3px;">
                <tr>
                    <td style="padding-left: 3px;width: 1%;">
                        <p class="texto-fila">Observaciones:</p>
                    </td>
                    <td colspan="3" class="border-bt">
                    </td>
                </tr>
            </table>
            <table style="padding-left: 3px; padding-top: 10px;">
                <tr>
                    <td colspan="4" class="border-bt">
                    </td>
                </tr>
            </table>
        </div>

        <div class="mark-section" style="padding-top: 5px;">
            <table>
                <tr>
                    <td>
                        <p>Nombre y firma del Asesor Externo</p>
                    </td>
                    <td>
                        <p>Sello de la empresa, organismo o dependencia</p>
                    </td>
                    <td>
                        <p>Fecha de Evaluación</p>
                    </td>
                </tr>
            </table>
        </div>

    </section>
    <section id="asesor_interno">
        <table style="padding-top: 5px;">
            <tbody>
                <tr>
                    <th colspan="4">En qué medida el residente cumple con lo siguiente</th>
                </tr>
                <tr>
                    <th colspan="2">Criterios a evaluar</th>
                    <th>Valor</th>
                    <th>Evaluación</th>
                </tr>
                <tr class="verticalCol">
                    <td rowspan="15">
                        <p class="verticalText">Evaluación por el Asesor Interno</p>
                    </td>
                    <td>Portada</td>
                    <td class="valor-align">2</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Agradecimientos</td>
                    <td class="valor-align">2</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Resumen</td>
                    <td class="valor-align">2</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Índice</td>
                    <td class="valor-align">2</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Introducción</td>
                    <td class="valor-align">2</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Problemas a resolver, priorizándolos</td>
                    <td class="valor-align">5</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Objetivos</td>
                    <td class="valor-align">5</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Justificación</td>
                    <td class="valor-align"> / </td>
                    <td></td>
                </tr>
                <tr>
                    <td>Marco teórico (fundamentos teóricos)</td>
                    <td class="valor-align">10</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Procedimiento y descripción de las actividades realizadas</td>
                    <td class="valor-align">5</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Resultados, planos, gráficas, prototipos, manuales, programas, análisis estadísticos, modelos
                        matemáticos, simulaciones, normativos, regulaciones y restricciones, etre otros. Solo para
                        proyectos que por su naturalza lo requieran: estudio de mercado, estudio técnico y estudio
                        económico.</td>
                    <td class="valor-align">45</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Conclusiones, recomendaciones y experiencia profesional adquirida.</td>
                    <td class="valor-align">15</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Competencias desarrolladas y/o aplicadas</td>
                    <td class="valor-align">3</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Funetes de información</td>
                    <td class="valor-align">2</td>
                    <td></td>
                </tr>
                <tr>
                    <td class="bold right-align">Calificación total</td>
                    <td class="valor-align">100</td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <div class="info-section">
            <table style="padding-left: 3px;">
                <tr>
                    <td style="padding-left: 3px;width: 1%;">
                        <p class="texto-fila">Observaciones:</p>
                    </td>
                    <td colspan="3" class="border-bt">
                    </td>
                </tr>
            </table>
            <table style="padding-left: 3px; padding-top: 10px;">
                <tr>
                    <td colspan="4" class="border-bt">
                    </td>
                </tr>
            </table>
        </div>

        <div class="mark-section" style="padding-top: 5px;">
            <table>
                <tr>
                    <td>
                        <p>Nombre y firma del Asesor Interno</p>
                    </td>
                    <td>
                        <p>Sello de Institución</p>
                    </td>
                    <td>
                        <p>Fecha de Evaluación</p>
                    </td>
                </tr>
            </table>
        </div>
    </section>
    <div class="fin-de-pagina">
        <div class="info-pie-de-pagina-itsh">
            Av . 25 Poniente Num. 100 Col. Reserva territorial
            <br />
            Huatusco, Veracruz, México. C.P. 94100
            <br />
            Tel. (01 273-73-4-40- 00), e-mail: residencias@huatusco.tecnm.mx
            <br />
            www.itshuatusco.edu.mx
        </div>
    </div>
</body>

</html>
