// Medians of the Lighthouse runs listed in manifest.json (written by `lhci upload`), as a Markdown table.
// Usage: node summary.mjs [results directory]. Prints the table and writes it to medians.md in that directory.
// A problem with the input is only reported (exit code 0): this script never decides whether a measurement fails.
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const dash = '—';

// name, how to read the value from an lhr (a Lighthouse JSON report), how to show the median
const metrics = [
  ['SEO', (lhr) => scaled(lhr.categories?.seo?.score), (value) => String(Math.round(value))],
  ['Performance', (lhr) => scaled(lhr.categories?.performance?.score), (value) => String(Math.round(value))],
  ['LCP (ms)', (lhr) => number(lhr.audits?.['largest-contentful-paint']?.numericValue), (value) => String(Math.round(value))],
  ['CLS', (lhr) => number(lhr.audits?.['cumulative-layout-shift']?.numericValue), (value) => value.toFixed(3)],
  ['TBT (ms)', (lhr) => number(lhr.audits?.['total-blocking-time']?.numericValue), (value) => String(Math.round(value))],
];

function number(value) {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

// Scores are 0-1 in a report and 0-100 in the table.
function scaled(score) {
  const value = number(score);
  return value === null ? null : value * 100;
}

function median(values) {
  if (values.length === 0) return null;
  const sorted = [...values].sort((a, b) => a - b);
  const middle = Math.floor(sorted.length / 2);
  return sorted.length % 2 === 1 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}

// The lhr of a run, or null when its file is missing or unreadable.
function readLhr(directory, run) {
  if (typeof run.jsonPath !== 'string') return null;
  try {
    return JSON.parse(readFileSync(resolve(directory, run.jsonPath), 'utf8'));
  } catch {
    return null;
  }
}

function table(directory, runs) {
  const urls = [...new Set(runs.map((run) => run.url))];
  const lines = [
    '### Lighthouse: medians of the runs',
    '',
    `| URL | Runs | ${metrics.map(([name]) => name).join(' | ')} |`,
    `|---|---:|${metrics.map(() => '---:|').join('')}`,
  ];
  for (const url of urls) {
    const urlRuns = runs.filter((run) => run.url === url);
    const reports = urlRuns.map((run) => readLhr(directory, run)).filter((lhr) => lhr !== null);
    const cells = metrics.map(([, read, format]) => {
      const values = reports.map((lhr) => read(lhr)).filter((value) => value !== null);
      const value = median(values);
      return value === null ? dash : format(value);
    });
    lines.push(`| ${url} | ${urlRuns.length} | ${cells.join(' | ')} |`);
  }
  return `${lines.join('\n')}\n`;
}

function readManifest(directory) {
  const file = join(directory, 'manifest.json');
  if (!existsSync(file)) return { problem: `${file} is missing: there are no Lighthouse results.` };
  let manifest;
  try {
    manifest = JSON.parse(readFileSync(file, 'utf8'));
  } catch {
    return { problem: `${file} cannot be read: it is not valid JSON.` };
  }
  const runs = Array.isArray(manifest) ? manifest.filter((run) => run && typeof run.url === 'string') : [];
  if (runs.length === 0) return { problem: `${file} is empty: there are no Lighthouse runs.` };
  return { runs };
}

const directory = resolve(process.argv[2] ?? '/app/lighthouse-results');
const { runs, problem } = readManifest(directory);
if (problem) {
  console.log(problem);
} else {
  const output = table(directory, runs);
  writeFileSync(join(directory, 'medians.md'), output);
  process.stdout.write(output);
}
