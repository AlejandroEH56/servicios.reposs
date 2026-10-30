<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitud de Residencias Profesionales</title>
    <style>
        @page {
            size: letter;
            margin-top: 0cm;
            margin-right: 2cm;
            margin-bottom: 1cm;
            margin-left: 2cm;
        }

        body {
            font-family: 'Arial Narrow', sans-serif;
            font-size: 11pt;
            margin: 0;
            padding: 0;
        }

        .container {
            width: 100%;
            max-width: 100%;
            height: auto;
            padding-top: 2cm;
            padding-right: 10px;
            padding-bottom: 10px;
            padding-left: 10px;
            box-sizing: border-box;
            overflow: hidden;
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
            margin-top: 40px;
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

        .titulo {
            padding: 10px;
            display: flex;
            flex-direction: row;
            gap: 10px;
            align-items: center;
            justify-content: center;
            width: 100%;
            position: flex;
            left: 0px;
            top: 111px;
        }

        .solicitud-titulo {
            color: #000000;
            text-align: center;
            font-family: "BerlinSansFb-Bold", sans-serif;
            font-size: 12px;
            font-weight: 700;
            text-decoration: underline;
            position: relative;
            width: 100%;
        }

        .hoja {
            display: flex;
            flex-direction: row;
            gap: 10px;
            align-items: end;
            justify-content: flex-end;
            position: absolute;
            right: 0px;
            top: 2.3cm;
        }

        .hoja-1-de-1 {
            color: #000000;
            text-align: right;
            font-family: "ArialNarrow-Regular", sans-serif;
            font-size: 9px;
            font-weight: 400;
            position: relative;
            width: 100%;
        }

        h1,
        h2 {
            text-align: center;
            font-family: 'Arial Narrow', sans-serif;
            font-size: 12pt;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            table-layout: auto;
            font-family: 'Arial Narrow', sans-serif;
            font-size: 10pt;
        }

        th,
        td {
            border: 1px solid black;
            padding: 3px;
            text-align: left;
        }

        td.centrado-1 {
            border: 1px solid black;
            padding: 1px;
            text-align: center;
        }

        p.sin-margen {
            margin-bottom: 0px;
            margin-top: 0px;
        }

        p.proyecto-table {
            font-family: 'Arial Narrow', sans-serif;
            font-size: 9pt;
            font-weight: bold;
            padding: 1px;
            margin: 0px;
        }

        .firma {
            padding-top: 10px;
            text-align: center;
            margin-top: 10px;
        }

        .pie-de-pagina {
            padding-top: 10px;
            display: flex;
            flex-direction: row;
            gap: 5px;
            align-items: center;
            justify-content: center;
            width: 100%;
            position: flex;
            left: 0px;
            top: 100%;
        }

        .info-pie-de-pagina {
            color: #000000;
            text-align: center;
            font-family: "Arial-Narrow", sans-serif;
            font-size: 8px;
            font-weight: 400;
            position: relative;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .fin-de-pagina {
            padding-top: 40px;
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
            font-size: 10px;
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
    <div class="container">
        <div class="hoja">
            <div class="hoja-1-de-1">
                Hoja 1 de 1
            </div>
        </div>
        <div class="titulo">
            <div class="solicitud-titulo">
                SOLICITUD DE RESIDENCIAS PROFESIONALES
            </div>
        </div>
        <p style="font-size: 10pt; text-align: right;margin:0px;">Huatusco de Chicuellar, Ver., a <?= e($data['dia']) ?> de <?= e($data['mes']) ?> del <?= e($data['anio']) ?></p>
        <p style="font-size: 10pt;"><strong><?= e($data['nombre_jefe_depto']) ?></strong><br><strong><?= e($data['jefe_depto']) ?></strong></p>
        <p style="text-align: left; margin:0px; padding:0px;font-size: 10pt;">Por este medio, me permito solicitar el inicio de mi proceso de Residencia Profesional para el período escolar actual, contando con los siguientes generales:</p>
        <table class="table" style="width: 100%; table-layout: auto; margin-bottom: 0px; margin-top: 0px;">
            <tbody>
                <tr>
                    <td>
                        <p class="proyecto-table">Nombre del Proyecto:</p>
                    </td>
                    <td colspan="3"><?= e($data['nombre_proyecto']) ?></td>
                </tr>
                <tr>
                    <td>
                        <p class="proyecto-table" style="padding: 0px;">Opción Elegida:</p>
                    </td>
                    <td colspan="3" style="padding: 0px 3px; margin: 0;">
                        Banco de Proyectos
                        <input type="checkbox" <?= e($data['tipo_proyecto']) === 'Banco de Proyectos' ? 'checked' : '' ?> style="margin: 3px 0 0 0; vertical-align:middle;">
                        Propuesta Propia
                        <input type="checkbox" <?= e($data['tipo_proyecto']) === 'Propuesta Propia' ? 'checked' : '' ?> style="margin: 3px 0 0 0; vertical-align:middle;">
                    </td>
                </tr>
                <tr>
                    <td>
                        <p class="proyecto-table">Período Proyectado:</p>
                    </td>
                    <td><?= e($data['periodo']) ?></td>
                    <td>
                        <p class="proyecto-table">No. de Residentes:</p>
                    </td>
                    <td><?= e($data['numero_residentes']) ?></td>
                </tr>
            </tbody>
        </table>
        <h2 style="text-align: left; margin:0px; font-size: 10pt;">Datos de la Empresa:</h2>
        <table class="table" style="width: 100%; table-layout: auto; margin-bottom: 0px; margin-top: 0px;">
            <tbody>
                <tr>
                    <td width="20%">Nombre:</td>
                    <td width="80%" colspan="5"><?= e($data['nombre_empresa']) ?></td>
                </tr>
                <tr>
                    <td>Giro, Ramo:</td>
                    <td colspan="2">
                        <p style="text-align: center; margin-bottom: 0px; margin-top: 0px;"><?= e($data['ramo']) ?></p>
                    </td>
                    <td width="15%" rowspan="2">R.F.C</td>
                    <td width="15%" colspan="2" rowspan="2"><?= e($data['rfc']) ?></td>
                </tr>
                <tr>
                    <td>Sector:</td>
                    <td colspan="2">
                        <p style="text-align: center; margin-bottom: 0px; margin-top: 0px;"><?= e($data['sector']) ?></p>
                    </td>
                </tr>
                <tr>
                    <td class="centrado-1" colspan="6">
                        <p class="sin-margen">Domicilio:</p>
                    </td>
                </tr>
                <tr>
                    <td width="20%">Colonia:</td>
                    <td width="30%"><?= e($data['colonia_empresa']) ?></td>
                    <td width="5%">CP:</td>
                    <td width="10%"><?= e($data['cp_empresa']) ?></td>
                    <td width="5%">FAX:</td>
                    <td width="10%"><?= e($data['fax']) ?></td>
                </tr>
                <tr>
                    <td style="padding:1px; padding-left: 3px;">Ciudad:</td>
                    <td><?= e($data['ciudad_empresa']) ?></td>
                    <td width="10%" style="padding:1px;">Telefono: <br>Celular:</td>
                    <td width="30%" colspan="3"><?= e($data['telefono_empresa']) ?><br><?= e($data['celular_empresa']) ?></td>
                </tr>
                <tr>
                    <td>Misión de la Empresa:</td>
                    <td colspan="5"><?= e($data['mision']) ?></td>
                </tr>
                <tr>
                    <td style="padding:0px;padding-left: 3px;">Nombre del Titular de la Empresa:</td>
                    <td colspan="2"><?= e($data['nombre_titular']) ?></td>
                    <td>Puesto:</td>
                    <td width="10%" colspan="2"><?= e($data['puesto_titular']) ?></td>
                </tr>
                <tr>
                    <td style="padding:0px;padding-left: 3px;">Nombre del Asesor Externo:</td>
                    <td colspan="2"><?= e($data['nombre_asesor']) ?></td>
                    <td>Puesto:</td>
                    <td width="10%" colspan="2"><?= e($data['puesto_asesor']) ?></td>
                </tr>
            </tbody>
        </table>
        <h2 style="font-size: 10pt; text-align: left; margin:0px; padding:0px;">Datos del/la Residente:</h2>
        <table class="table" style="width: 100%; table-layout: auto; margin-bottom: 0px; margin-top: 0px;">
            <tbody>
                <tr>
                    <td width="15%">Nombre:</td>
                    <td width="85%" colspan="4"><?= e($data['nombre_estudiante']) ?></td>
                </tr>
                <tr>
                    <td>Carrera:</td>
                    <td colspan="2"><?= e($data['carrera']) ?></td>
                    <td width="15%">No. Control:</td>
                    <td width="15%"><?= e($data['numero_control']) ?></td>
                </tr>
                <tr>
                    <td style="padding: 1px;padding-left: 3px;">Domicilio:</td>
                    <td colspan="4"><?= e($data['domicilio_estudiante']) ?></td>
                </tr>
                <tr>
                    <td rowspan="2">E-Mail:</td>
                    <td rowspan="2"><?= e($data['principal_name']) ?></td>
                    <td rowspan="2" width="20%">Seguridad Social:</td>
                    <td style="padding-top: 1px; padding-bottom: 1px;"><?= e($data['tipo_seguro'] == 'IMSS' ? 'IMSS(X)' : 'IMSS( )') ?></td>
                    <td><?= e($data['tipo_seguro'] == 'ISSSTE' ? 'ISSSTE(X)' : 'ISSSTE( )') ?></td>
                </tr>
                <tr>
                    <td colspan="2" style="padding-top: 1px;">No.: <?= e($data['no_seguro']) ?></td>
                </tr>
                <tr>
                    <td rowspan="2">Ciudad:</td>
                    <td rowspan="2"><?= e($data['ciudad_estudiante']) ?></td>
                    <td style="padding: 1px;padding-left: 3px;">Telefono:</td>
                    <td colspan="2"><?= e($data['telefono_estudiante']) ?></td>
                </tr>
                <tr>
                    <td style="padding: 1px;padding-left: 3px;">Celular:</td>
                    <td colspan="2"><?= e($data['celular_estudiante']) ?></td>
                </tr>
            </tbody>
        </table>
        <p style="text-align: left; margin:0px; padding:0px;">Sin más por el momento, agradezco la atención al presente.</p>
        <div class="firma">
            <p>_______________________________</p>
            <p><strong>Firma del Alumno/a</strong></p>
        </div>
        <div class="pie-de-pagina">
            <div class="info-pie-de-pagina">
                Los datos personales recabados serán protegidos, incorporados y tratados en el sistema de datos personales del departamento de Residencias Profesionales y Servicio Social del Instituto
                <br>Tecnológico Superior de Huatusco.
            </div>
        </div>
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
    </div>

</body>

</html>