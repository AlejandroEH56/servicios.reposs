import { Identity } from '../../api/models/identity';

export function activeIdentity(): Identity {
  return { id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', displayName: 'Persona de ensayo', email: null, status: 'ACTIVA',
    roles: [], permissions: [], scopes: [], sessionExpiresAt: new Date(Date.now() + 300_000).toISOString() };
}
