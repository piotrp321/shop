// The settings of a run, read from the environment of the k6 container (the runners in load/k6.sh and
// infra/k8s/scripts/load.sh pass them with `-e`). A missing or malformed required setting stops the run before it starts.

function text(name, fallback) {
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

const trimmed = (url) => url.replace(/\/+$/, '');

// Where k6 connects to the store and to the API (the store of the stack compose: http://store:3000 and http://api; the
// Traefik of the cluster for both). The Host header, when the address is not the domain of the service (the cluster:
// the node of k3d, hosts `localhost` and `api.localhost`).
export const storeUrl = trimmed(text('STORE_URL'));
export const storeHost = text('STORE_HOST', '');
export const apiUrl = trimmed(text('API_URL'));
export const apiHost = text('API_HOST', '');
// The public address of the store (its STORE_URL): the BFF of the store refuses a write whose Origin is another one.
export const storeOrigin = trimmed(text('STORE_ORIGIN', storeUrl));

// The share of every kind of iteration of the read and cart scenarios, in percent (the sum is 100).
export const MIX = [
  ['home', 10],
  ['category', 20],
  ['product', 35],
  ['search', 25],
  ['cart', 10],
];

// The saturation criterion of the spec: the p95 of the responses above this many milliseconds, or the share of 5xx and
// network errors above this fraction.
export const SATURATION_P95_MS = 2000;
export const SATURATION_ERRORS = 0.01;
