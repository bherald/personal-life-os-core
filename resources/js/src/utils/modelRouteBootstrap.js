export function normalizeModelRouteResponse(response) {
  const seen = new Set();
  const modes = (Array.isArray(response?.modes) ? response.modes : [])
    .filter(mode => mode && typeof mode === 'object')
    .map(mode => ({ ...mode, id: typeof mode.id === 'string' ? mode.id.trim() : '' }))
    .filter(mode => {
      if (!mode.id || seen.has(mode.id)) return false;
      seen.add(mode.id);
      return true;
    });

  return {
    modes,
    privateSupported: response?.private_supported === true,
    overrideSupported: response?.override_supported === true,
  };
}

export function selectModelRouteId(modes, current = 'standard') {
  if (!Array.isArray(modes) || modes.length === 0) return null;
  if (modes.some(mode => mode.id === current)) return current;

  return modes.find(mode => mode.id === 'standard')?.id || modes[0].id;
}
