<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Acceso a Servicios</title>
<body><main><h1>Servicios institucionales</h1><p>Accede con tu cuenta Microsoft institucional.</p>
@if ($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
<a href="{{ route('microsoft.redirect') }}">Iniciar sesión con Microsoft</a></main></body></html>
