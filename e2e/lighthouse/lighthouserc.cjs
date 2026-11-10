// Configuration of the Lighthouse measurement (`make lighthouse`, see run.sh and docs/features/lighthouse/spec.md).
// Mobile profile (the Lighthouse default: formFactor mobile, simulated throttling), 3 runs per address, reports only
// on the filesystem (never in a public LHCI storage). Only the SEO score blocks; the other thresholds warn.
const store = `http://localhost:${process.env.STORE_PORT ?? '3000'}`;

// Home page, a category listing and a product page of the demo data (apps/api/fixtures/catalog.yaml).
const paths = ['/', '/c/turystyka/biwak/namioty', '/p/namiot-turystyczny-norvane-biwak-2'];

// `aggregationMethod: median` is explicit: the default (optimistic) takes the best run of the three.
const median = 'median';

module.exports = {
  ci: {
    collect: {
      url: paths.map((path) => `${store}${path}`),
      numberOfRuns: 3,
      settings: {
        // Chromium of the Playwright image (CHROME_PATH is set by run.sh); the container needs --no-sandbox.
        chromeFlags: '--headless=new --no-sandbox',
      },
    },
    assert: {
      assertions: {
        'categories:seo': ['error', { minScore: 0.9, aggregationMethod: median }],
        'categories:performance': ['warn', { minScore: 0.9, aggregationMethod: median }],
        'largest-contentful-paint': ['warn', { maxNumericValue: 2500, aggregationMethod: median }],
        'cumulative-layout-shift': ['warn', { maxNumericValue: 0.1, aggregationMethod: median }],
        'total-blocking-time': ['warn', { maxNumericValue: 200, aggregationMethod: median }],
      },
    },
    upload: {
      target: 'filesystem',
      outputDir: process.env.LIGHTHOUSE_RESULTS_DIR ?? '/app/lighthouse-results',
    },
  },
};
