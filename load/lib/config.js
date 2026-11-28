// The settings of the scripts that drive the store, read from the environment of the k6 container (lib/env.js). A missing
// or malformed required setting stops the run before it starts.
import { number, text } from './env.js';

export { number, text };

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

// The share of every kind of iteration of the read and cart scenarios, in percent (the sum is 100). MIX in the environment
// ("home:10,category:20,product:35,search:25", only the kinds named, shares of any size) replaces it, e.g. a mix without
// `cart` for a run that writes nothing.
const DEFAULT_MIX = [
  ['home', 10],
  ['category', 20],
  ['product', 35],
  ['search', 25],
  ['cart', 10],
];

function mixFromEnvironment() {
  const raw = __ENV.MIX;
  if (raw === undefined || raw === '') {
    return DEFAULT_MIX;
  }
  const known = DEFAULT_MIX.map(([kind]) => kind);
  const mix = raw.split(',').map((part) => {
    const [kind, share] = part.split(':');
    const value = Number(share);
    if (!known.includes(kind) || !Number.isFinite(value) || value < 0) {
      throw new Error(`MIX must look like home:10,category:20 with kinds of ${known.join(', ')}, got '${raw}'`);
    }
    return [kind, value];
  });
  if (mix.every(([, share]) => share === 0)) {
    throw new Error(`MIX has no share above zero: '${raw}'`);
  }
  return mix;
}

export const MIX = mixFromEnvironment();

// The saturation criterion of the spec: the p95 of the responses above this many milliseconds, or the share of 5xx and
// network errors above this fraction.
export const SATURATION_P95_MS = 2000;
export const SATURATION_ERRORS = 0.01;
