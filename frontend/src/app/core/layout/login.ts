import { Component } from '@angular/core';

@Component({
  selector: 'app-login',
  template: `
    <section aria-labelledby="login-title">
      <h2 id="login-title">Acceso institucional</h2>
      <p>Ingresa con tu cuenta Microsoft institucional para consultar los servicios disponibles.</p>
      <a class="button" href="/auth/entra/login">Ingresar con Microsoft</a>
    </section>
  `,
})
export class Login {}
