import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-session-error',
  imports: [RouterLink],
  template: `
    <section aria-labelledby="error-title">
      <h2 id="error-title">No podemos comprobar tu sesión</h2>
      <p>El servicio no está disponible en este momento. Vuelve a intentarlo más tarde.</p>
      <a routerLink="/session">Reintentar</a>
    </section>
  `,
})
export class SessionError {}
