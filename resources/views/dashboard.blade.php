<!doctype html><html lang="es"><meta charset="utf-8"><title>Servicios</title>
<body><main><h1>Servicios institucionales</h1><p>Sesión de {{ auth()->user()->name }}</p>
<p>Los módulos se habilitarán conforme avance la modernización.</p>
<form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Cerrar sesión</button></form></main></body></html>
