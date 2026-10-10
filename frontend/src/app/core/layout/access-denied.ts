import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-access-denied',
  imports: [RouterLink],
  template: `
    <section aria-labelledby="denied-title">
      <h2 id="denied-title">Acceso denegado</h2>
      <p>
        No fue posible autorizar tu acceso institucional. Consulta al responsable del servicio si
        necesitas acceso.
      </p>
      <a routerLink="/login">Volver al inicio de sesión</a>
    </section>
  `,
})
export class AccessDenied {}
