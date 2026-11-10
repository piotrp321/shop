import { REPORTED } from './metrics.js';

// The thresholds that make k6 keep the numbers of a tag in its summary: without a threshold a metric has no sub-metric for
// a tag. An empty list judges nothing; it only asks k6 to report `metric{selector}`.
export function reportOn(thresholds, selectors) {
  for (const selector of selectors) {
    for (const metric of REPORTED) {
      thresholds[`${metric}{${selector}}`] = thresholds[`${metric}{${selector}}`] || [];
    }
  }
  return thresholds;
}

function values(data, metric, selector) {
  const entry = data.metrics[`${metric}{${selector}}`];
  return entry ? entry.values : {};
}

const round = (value, digits) => (value === undefined || value === null ? null : Number(value.toFixed(digits)));

// The numbers of one selector: requests, the latency percentiles (ms), the share of 5xx and network errors, the 409 and 429.
export function figures(data, selector, seconds) {
  const duration = values(data, 'http_req_duration', selector);
  const requests = values(data, 'http_reqs', selector).count || 0;
  return {
    requests,
    requests_per_second: seconds > 0 ? round(requests / seconds, 2) : null,
    p50_ms: round(duration.med, 1),
    p90_ms: round(duration['p(90)'], 1),
    p95_ms: round(duration['p(95)'], 1),
    p99_ms: round(duration['p(99)'], 1),
    max_ms: round(duration.max, 1),
    server_error_rate: round(values(data, 'server_errors', selector).rate || 0, 4),
    conflicts_409: values(data, 'conflicts_409', selector).count || 0,
    rate_limited_429: values(data, 'rate_limited_429', selector).count || 0,
    other_4xx: values(data, 'other_4xx', selector).count || 0,
  };
}

export function counter(data, metric, selector) {
  const entry = selector ? data.metrics[`${metric}{${selector}}`] : data.metrics[metric];
  return entry && entry.values.count !== undefined ? entry.values.count : 0;
}

const pad = (value, width) => String(value).padEnd(width);

// A plain table for the terminal: a line per selector.
export function table(title, rows) {
  const lines = [`\n${title}`, `${pad('', 22)}${pad('requests', 10)}${pad('req/s', 9)}${pad('p50 ms', 9)}${pad('p95 ms', 9)}${pad('p99 ms', 9)}${pad('5xx/net', 9)}${pad('409', 7)}429`];
  for (const [label, f] of rows) {
    lines.push(
      `${pad(label, 22)}${pad(f.requests, 10)}${pad(f.requests_per_second, 9)}${pad(f.p50_ms, 9)}${pad(f.p95_ms, 9)}${pad(f.p99_ms, 9)}${pad(`${round(f.server_error_rate * 100, 2)}%`, 9)}${pad(f.conflicts_409, 7)}${f.rate_limited_429}`,
    );
  }
  return `${lines.join('\n')}\n`;
}
