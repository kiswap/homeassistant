// An empty slot kept for future widgets.
export function create(el, cfg) {
  el.textContent = cfg.label || 'free slot';
  return { refresh: async () => {} };
}
