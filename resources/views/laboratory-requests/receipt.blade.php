<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><title>Comprobante de solicitud de laboratorio</title>
<style>
@page { margin: 36pt; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; line-height: 1.18; color: #17334a; }
h1 { font-size: 16pt; margin: 0 0 6pt; } h2 { font-size: 11pt; margin: 10pt 0 5pt; border-bottom: 1pt solid #b2c4cf; padding-bottom: 3pt; }
p { margin: 6pt 0; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; } td { padding: 5pt; border: 1pt solid #d5e0e6; overflow-wrap: anywhere; vertical-align: top; }
td:first-child { width: 28%; font-weight: bold; background: #f1f5f7; }
p, td { overflow-wrap: anywhere; word-wrap: break-word; } .reference { font-size: 8pt; } .footer { font-size: 8pt; margin-top: 18pt; color: #425b6c; }
</style></head>
<body>
@php($request = $snapshot['request'])
<h1>Solicitud de laboratorio</h1>
<p class="reference">Referencia: {{ $request['id'] }}<br>Decisión: {{ $snapshot['decisionId'] }}</p>
<h2>{{ $request['type'] }} - {{ $request['status'] }}</h2>
<table>
<tr><td>Laboratorio</td><td>{{ $snapshot['laboratory'] }}</td></tr>
<tr><td>Solicitante</td><td>{{ $snapshot['requester'] }}</td></tr>
<tr><td>Correo de contacto</td><td>{{ $snapshot['requesterEmail'] ?? 'Sin correo de contacto' }}</td></tr>
<tr><td>Capacidad del laboratorio</td><td>{{ $snapshot['capacity'] }}</td></tr>
<tr><td>Título</td><td>{{ $request['title'] }}</td></tr>
<tr><td>Inicio</td><td>{{ (new DateTimeImmutable($request['startAt']))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('d/m/Y H:i') }}</td></tr>
<tr><td>Fin</td><td>{{ (new DateTimeImmutable($request['endAt']))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('d/m/Y H:i') }}</td></tr>
<tr><td>Participantes</td><td>{{ $request['attendees'] }}</td></tr>
@if($request['type'] === 'PRACTICA')
<tr><td>Práctica</td><td>{{ $request['practice']['name'] }}<br>Número: {{ $request['practice']['number'] ?? 'Sin número' }}</td></tr>
<tr><td>Carrera</td><td>{{ $snapshot['academic']['career'] }}</td></tr>
<tr><td>Asignatura y clave</td><td>{{ $snapshot['academic']['subject'] }} · {{ $snapshot['academic']['subjectCode'] }}</td></tr>
<tr><td>Grupo</td><td>{{ $snapshot['academic']['group'] }}</td></tr>
@elseif($request['type'] === 'VARIA')
<tr><td>Actividad</td><td>{{ $request['various']['useCode'] }}<br>{{ $request['various']['description'] }}</td></tr>
@else
<tr><td>Justificación</td><td>{{ $request['extraordinary']['justification'] }}</td></tr>
@endif
<tr><td>Responsable de la decisión</td><td>{{ $snapshot['reviewer'] }}</td></tr>
<tr><td>Fecha de decisión</td><td>{{ (new DateTimeImmutable($snapshot['decidedAt']))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('d/m/Y H:i') }}</td></tr>
<tr><td>Fecha de envío</td><td>{{ $request['submittedAt'] ? (new DateTimeImmutable($request['submittedAt']))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('d/m/Y H:i') : 'Borrador sin envío' }}</td></tr>
</table>
<h2>Propósito</h2><p>{{ $request['purpose'] }}</p>
<h2>Insumos y observaciones</h2><p>{{ $request['supplies'] ?? 'Sin observaciones' }}</p>
<h2>Motivo de la decisión</h2><p>{{ $snapshot['reason'] }}</p>
<p class="footer">Horario de Ciudad de México. Este comprobante conserva la decisión registrada en su fecha de emisión. Consulta el portal para conocer el estado actual; una cancelación posterior deja sin efecto la autorización anterior.</p>
</body></html>
