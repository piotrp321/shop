// The settings of a run, read from the environment of the k6 container (the runners in load/k6.sh and
// infra/k8s/scripts/load.sh pass them with `-e`). A missing or malformed required setting stops the run before it starts.
// Apart from config.js, which requires the address of the store, so that a script without the store (writes.js) can read
// its settings.

export function text(name, fallback) {
  const value = __ENV[name];
  if (value === undefined || value === '') {
    if (fallback === undefined) {
      throw new Error(`${name} is required`);
    }
    return fallback;
  }
  return value;
}

export function number(name, fallback) {
  const raw = __ENV[name];
  if (raw === undefined || raw === '') {
    if (fallback === undefined) {
      throw new Error(`${name} is required`);
    }
    return fallback;
  }
  const value = Number(raw);
  if (!Number.isFinite(value) || value < 0) {
    throw new Error(`${name} must be a non-negative number, got '${raw}'`);
  }
  return value;
}
