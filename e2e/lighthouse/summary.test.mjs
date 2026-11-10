// Tests of summary.mjs: medians of the Lighthouse runs listed in manifest.json (run with `node --test`).
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { after, beforeEach, describe, test } from 'node:test';
import { fileURLToPath } from 'node:url';

const script = join(dirname(fileURLToPath(import.meta.url)), 'summary.mjs');
const home = 'http://localhost:3000/';
const category = 'http://localhost:3000/c/turystyka/biwak/namioty';

const roots = [];
let dir;

beforeEach(() => {
  dir = mkdtempSync(join(tmpdir(), 'lh-summary-'));
  roots.push(dir);
});

after(() => {
  for (const root of roots) rmSync(root, { recursive: true, force: true });
});

// An lhr in the shape of a Lighthouse JSON report; a null value is a metric Lighthouse could not compute.
function lhr(values) {
  return {
    categories: {
      seo: { score: values.seo },
      performance: { score: values.performance },
    },
    audits: {
      'largest-contentful-paint': { numericValue: values.lcp },
      'cumulative-layout-shift': { numericValue: values.cls },
      'total-blocking-time': { numericValue: values.tbt },
    },
  };
}

// Adds a run to the manifest; without values the run has no lhr file.
function addRun(runs, url, values, name) {
  const jsonPath = join(dir, name ?? `lhr-${runs.length}.json`);
  if (values !== undefined) writeFileSync(jsonPath, JSON.stringify(lhr(values)));
  runs.push({ url, isRepresentativeRun: false, jsonPath, htmlPath: jsonPath.replace(/\.json$/, '.html') });
}

function writeManifest(runs) {
  writeFileSync(join(dir, 'manifest.json'), JSON.stringify(runs));
}

function run() {
  return spawnSync('node', [script, dir], { encoding: 'utf8' });
}

// Cells of the table row of a URL after the URL itself: runs, SEO, Performance, LCP, CLS, TBT.
function row(stdout, url) {
  const line = stdout.split('\n').find((candidate) => candidate.includes(url));
  assert.ok(line, `no row for ${url} in:\n${stdout}`);
  return line
    .split('|')
    .slice(1, -1)
    .map((cell) => cell.trim())
    .slice(1);
}

describe('summary.mjs', () => {
  test('prints the median of three runs of each metric in the units of the table', () => {
    const runs = [];
    addRun(runs, home, { seo: 0.92, performance: 0.7, lcp: 3000.4, cls: 0.1, tbt: 120.6 });
    addRun(runs, home, { seo: 1, performance: 0.85, lcp: 2100.2, cls: 0.0004, tbt: 300.2 });
    addRun(runs, home, { seo: 0.9, performance: 0.55, lcp: 2500.6, cls: 0.05, tbt: 80.4 });
    writeManifest(runs);

    const result = run();

    assert.equal(result.status, 0, result.stderr);
    // seo 0.92, performance 0.7, lcp 2500.6 -> 2501, cls 0.05, tbt 120.6 -> 121
    assert.deepEqual(row(result.stdout, home), ['3', '92', '70', '2501', '0.050', '121']);
  });

  test('keeps the addresses apart and in the order of the manifest', () => {
    const runs = [];
    for (const seo of [1, 1, 1]) addRun(runs, home, { seo, performance: 0.9, lcp: 1000, cls: 0, tbt: 0 });
    for (const seo of [0.8, 0.9, 1]) addRun(runs, category, { seo, performance: 0.5, lcp: 4000, cls: 0.2, tbt: 400 });
    writeManifest(runs);

    const result = run();

    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(row(result.stdout, home), ['3', '100', '90', '1000', '0.000', '0']);
    assert.deepEqual(row(result.stdout, category), ['3', '90', '50', '4000', '0.200', '400']);
    assert.ok(result.stdout.indexOf(home) < result.stdout.indexOf(category));
  });

  test('leaves a run without a file out of the median and still counts it', () => {
    const runs = [];
    addRun(runs, home, { seo: 0.9, performance: 0.5, lcp: 1000, cls: 0.1, tbt: 100 });
    addRun(runs, home, { seo: 1, performance: 0.7, lcp: 3000, cls: 0.3, tbt: 300 });
    addRun(runs, home, undefined, 'missing.json');
    writeManifest(runs);

    const result = run();

    assert.equal(result.status, 0, result.stderr);
    // the median of two values is their mean
    assert.deepEqual(row(result.stdout, home), ['3', '95', '60', '2000', '0.200', '200']);
  });

  test('leaves a null value out of the median of that metric only', () => {
    const runs = [];
    addRun(runs, home, { seo: 0.9, performance: 0.5, lcp: null, cls: 0.1, tbt: 100 });
    addRun(runs, home, { seo: 1, performance: null, lcp: 3000, cls: 0.1, tbt: 300 });
    addRun(runs, home, { seo: 0.9, performance: 0.7, lcp: 1000, cls: null, tbt: 200 });
    writeManifest(runs);

    const result = run();

    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(row(result.stdout, home), ['3', '90', '60', '2000', '0.100', '200']);
  });

  test('shows a dash for a metric with no value in any run', () => {
    const runs = [];
    for (let index = 0; index < 3; index += 1) {
      addRun(runs, home, { seo: 0.95, performance: null, lcp: null, cls: null, tbt: null });
    }
    writeManifest(runs);

    const result = run();

    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(row(result.stdout, home), ['3', '95', '—', '—', '—', '—']);
  });

  test('shows dashes for an address whose runs all lack their files', () => {
    const runs = [];
    for (let index = 0; index < 3; index += 1) addRun(runs, home, undefined, `none-${index}.json`);
    writeManifest(runs);

    const result = run();

    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(row(result.stdout, home), ['3', '—', '—', '—', '—', '—']);
  });

  test('writes medians.md next to the manifest with the table that it prints', () => {
    const runs = [];
    addRun(runs, home, { seo: 0.9, performance: 0.9, lcp: 1000, cls: 0, tbt: 0 });
    writeManifest(runs);

    const result = run();

    assert.equal(result.status, 0, result.stderr);
    const file = readFileSync(join(dir, 'medians.md'), 'utf8');
    assert.equal(file, result.stdout);
    assert.match(file, /^\|[ :|-]+\|$/m, 'the header separator row is missing');
  });

  test('reports a missing manifest.json without a table and exits 0', () => {
    const result = run();

    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /manifest\.json/);
    assert.doesNotMatch(result.stdout, /\|/);
    assert.equal(existsSync(join(dir, 'medians.md')), false);
  });

  test('reports an empty manifest.json without a table and exits 0', () => {
    writeManifest([]);

    const result = run();

    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /manifest\.json/);
    assert.doesNotMatch(result.stdout, /\|/);
  });

  test('exits 0 for a manifest that is not valid JSON', () => {
    writeFileSync(join(dir, 'manifest.json'), '{broken');

    const result = run();

    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /manifest\.json/);
    assert.doesNotMatch(result.stdout, /\|/);
  });
});
