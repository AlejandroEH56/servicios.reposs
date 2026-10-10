import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-not-found',
  imports: [RouterLink],
  template: `
    <section aria-labelledby="not-found-title">
      <h2 id="not-found-title">Página no encontrada</h2>
      <p>La dirección solicitada no está disponible.</p>
      <a routerLink="/session">Ir al inicio</a>
    </section>
  `,
})
export class NotFound {}
