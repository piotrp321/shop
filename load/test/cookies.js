// The test of the cookies of load/lib/http.js (make load-cookies, load/test/cookies.sh): one iteration that asks the server of
// cookie-server.py to set a Secure and a plain cookie and then reads back what the client sent. EXPECT_SECURE_SENT (true or false) is
// whether the Secure cookie must come back; the plain one always must. Any other answer fails the run (exit 99).
import { check } from 'k6';
import { newContext, storeGet } from '../lib/http.js';

export const options = {
  scenarios: { cookies: { executor: 'per-vu-iterations', vus: 1, iterations: 1, maxDuration: '30s' } },
  thresholds: { checks: ['rate==1'] },
};

export default function () {
  const expectSecure = __ENV.EXPECT_SECURE_SENT === 'true';
  const ctx = newContext(18);
  storeGet(ctx, '/set', 'cookies', 'read');
  const sent = storeGet(ctx, '/read', 'cookies', 'read').body;
  console.log(`cookies sent back: ${sent}`);
  check(sent, {
    'the plain cookie comes back': (body) => body.includes('plain_c=1'),
    [expectSecure ? 'the Secure cookie comes back' : 'the Secure cookie does not come back']: (body) => body.includes('secure_c=1') === expectSecure,
  });
}
