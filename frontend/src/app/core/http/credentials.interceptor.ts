import { DOCUMENT, inject } from '@angular/core';
import { HttpInterceptorFn } from '@angular/common/http';

export const credentialsInterceptor: HttpInterceptorFn = (request, next) => {
  const origin = inject(DOCUMENT).location.origin;
  const sameOrigin = new URL(request.url, origin).origin === origin;
  return next(request.clone({ withCredentials: sameOrigin }));
};
