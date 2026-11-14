import type { Breadcrumb, Event, StackFrame } from "@sentry/vue";

type TraceContext = NonNullable<NonNullable<Event["contexts"]>["trace"]>;

/**
 * What of an event, a transaction or a breadcrumb may leave the panel for Sentry (spec: docs/features/sentry/spec.md, D4,
 * D9 and the threat model). It removes:
 * - from the request everything but the method, the address without its query and fragment, and the headers
 *   User-Agent, Content-Type and Accept: no cookies, body, query string or address of the client;
 * - from the user everything but the id (the `sub` of the staff member: P5), and the extra data;
 * - the query and fragment of the name of a transaction, of the names of HTTP, resource and navigation spans, of the
 *   addresses in the data of spans, contexts and breadcrumbs (`fetch`, `xhr`, `navigation`) and of the addresses of the
 *   frames: the `q` of the order search is an e-mail, `code` and `state` come from /auth/callback; the data about
 *   queries, headers, cookies, bodies and the client;
 * - the console breadcrumbs and the arguments of the stack frames (`vars`).
 *
 * The texts (messages, values of exceptions, breadcrumbs, names of spans, tags and the remaining string data) are
 * masked with the formats of the log masking of Alloy (infra/k8s/argocd/apps/monitoring.yaml), the same as the filters
 * of the store (apps/store, observability/filter.ts) and of the API (SentryEventFilter). A new format of customer data
 * in the logs or exceptions is a new pattern here, in the store, in the API and in Alloy (apps/admin/CLAUDE.md).
 */

export const REDACTED = "[REDACTED]";

/** The headers of a request that are kept, in lower case. */
const KEPT_HEADERS = new Set(["user-agent", "content-type", "accept"]);

/** The data of spans, contexts and breadcrumbs that is removed: queries, headers, cookies, bodies, the client. */
const REMOVED_DATA =
  /(^|[._])(query|fragment|query_string|arguments)$|header|cookie|body|^db\.query\.parameter|^user\.|^client\.|^net\.peer\.|client_ip|ip_address/i;

/** The data of spans, contexts and breadcrumbs that is an address: its query and fragment are removed. */
const ADDRESS_DATA = /(^|[._])(url|full|target|path|href|uri|from|to)$/i;

/** The names of the keys of secrets, as in Alloy. */
const KEY = String.raw`[a-z0-9_-]*(?:password|passwd|secret|token|session|api[_-]?key|authorization|cookie)[a-z0-9_-]*`;
const KEY_OR_CODE = String.raw`${KEY}|code(?:_id|_verifier)?|username`;
const QUOTED = String.raw`"(?:[^"\\]|\\.)*"`;
/** The characters of the local part of an e-mail address. */
const LOCAL = String.raw`[^\s"'<>(),;:\[\]\\=@/%&?]`;
const LOCAL_ENCODED = String.raw`[^\s"'<>(),;:\[\]\\=@/&?]`;

/**
 * The masks in the order of Alloy and the API: a pattern (flags `d` for the offsets of its first group, `m` to mask
 * every line, `g`) and what replaces its first group. DETAIL and STATEMENT of PostgreSQL, which Alloy drops as whole
 * lines, lose the text of the line. An e-mail address starts only after a character that cannot be in it (lookbehind),
 * so a long text without separators is masked in linear time, with the same result as the patterns of the API.
 */
const MASKS: Array<[RegExp, string]> = [
  [new RegExp(String.raw`(?:^|\]\s)\s*(?:DETAIL|STATEMENT):\s*(.+)$`, "dgm"), REDACTED],
  // JWT.
  [new RegExp(String.raw`(eyJ[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]*)+)`, "dgm"), REDACTED],
  // Bearer tokens.
  [new RegExp(String.raw`\bbearer\s+([A-Za-z0-9._~+/=-]{16,})`, "dgim"), REDACTED],
  // Headers written as lines (the rest of the value).
  [new RegExp(String.raw`\b(?:proxy-authorization|authorization|set-cookie|cookie)\s*:\s*([^\r\n"]+)`, "dgim"), REDACTED],
  // A list of two or more strings under a key of a secret: all of them.
  [
    new RegExp(String.raw`(?:^|[^a-z0-9_-])"?(?:${KEY_OR_CODE})"?\s*[:=]\s*\[\s*(${QUOTED}(?:\s*,\s*${QUOTED})+)\s*\]`, "dgim"),
    `"${REDACTED}"`,
  ],
  // A number under a key of a secret.
  [new RegExp(String.raw`(?:^|[^a-z0-9_-])"(?:${KEY})"\s*:\s*(-?[0-9][0-9.eE+-]*)`, "dgim"), REDACTED],
  // The value of a key of a secret in JSON (also a list of one value) and in logfmt with quotes.
  [new RegExp(String.raw`(?:^|[^a-z0-9_-])"?(?:${KEY_OR_CODE})"?\s*[:=]\s*\[?\s*"((?:[^"\\]|\\.)*)"`, "dgim"), REDACTED],
  // The same inside a string of JSON (the quotes are escaped).
  [new RegExp(String.raw`(?:^|[^a-z0-9_-])\\"(?:${KEY_OR_CODE})\\"\s*:\s*\\"([^"\\]*)\\"`, "dgim"), REDACTED],
  // The value of a key of a secret in a query string, a form and logfmt without quotes.
  [new RegExp(String.raw`(?:^|[^a-z0-9_-])(?:${KEY_OR_CODE})=([^&\s"';,)}\]\\]+)`, "dgim"), REDACTED],
  // The password of an address (scheme://user:password@host).
  [new RegExp(String.raw`://[^/\s:@"]*:([^@/\s"]+)@`, "dgm"), REDACTED],
  // The parameters of a query in the message of DBAL (with params [...]).
  [new RegExp(String.raw`with params \[((?:[^\]\\]|\\.)*)\]`, "dgm"), REDACTED],
  // The values of a row and of a key of PostgreSQL outside a line DETAIL: up to the last parenthesis of the line (a
  // value may have its own), or the end of the line when it is cut.
  [new RegExp(String.raw`(?:Failing row contains |Key \(.*?\)=)\((.*(?=\))|.*)`, "dgm"), REDACTED],
  // E-mail addresses, also with the @ written as %40 in an address.
  [new RegExp(String.raw`(?<!${LOCAL})(${LOCAL}+@[^\s"'<>(),;\[\]\\@/:]+\.[^\s"'<>(),;\[\]\\@/:.&?]{2,})`, "dgm"), REDACTED],
  [new RegExp(String.raw`(?<!${LOCAL_ENCODED})(${LOCAL_ENCODED}+%40[^\s"'<>(),;\[\]\\&]+)`, "dgm"), REDACTED],
  // IPv6 addresses: the full form, the compressed form with :: and the loopback.
  [new RegExp(String.raw`(?:^|[^0-9a-z:])((?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4})`, "dgim"), REDACTED],
  [new RegExp(String.raw`(?:^|[^0-9a-z:])((?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})::(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?)`, "dgim"), REDACTED],
  [new RegExp(String.raw`(?:^|[^0-9a-zA-Z:])(::1)\b`, "dgm"), REDACTED],
  // IPv4 addresses.
  [new RegExp(String.raw`\b([0-9]{1,3}(?:\.[0-9]{1,3}){3})\b`, "dgm"), REDACTED],
];

/** The text with the first group of every match of the pattern replaced. */
function replaceGroup(text: string, pattern: RegExp, replacement: string): string {
  let result = "";
  let last = 0;
  for (const match of text.matchAll(pattern)) {
    const group = match.indices?.[1];
    if (group !== undefined) {
      result += text.slice(last, group[0]) + replacement;
      last = group[1];
    }
  }
  return result + text.slice(last);
}

/** The text with every value of the formats of the masks replaced. */
export function mask(text: string): string {
  return MASKS.reduce((masked, [pattern, replacement]) => replaceGroup(masked, pattern, replacement), text);
}

/** The text up to the first ? or #: an address without its query and fragment. */
export function withoutQuery(text: string): string {
  const end = text.search(/[?#]/);
  return end < 0 ? text : text.slice(0, end);
}

type Data = Record<string, unknown>;

/**
 * The data of a span, a context or a breadcrumb as it may leave: without the removed keys, the addresses without
 * their query, the texts masked; objects nested up to `depth` levels are filtered the same way, deeper ones and lists
 * are removed (they may hold anything).
 */
function filterData(data: Data, depth = 0): Data {
  const filtered: Data = {};
  for (const [name, value] of Object.entries(data)) {
    if (REMOVED_DATA.test(name)) {
      continue;
    }
    if (typeof value === "string") {
      filtered[name] = mask(ADDRESS_DATA.test(name) ? withoutQuery(value) : value);
    } else if (value === null || typeof value === "number" || typeof value === "boolean") {
      filtered[name] = value;
    } else if (depth > 0 && typeof value === "object" && !Array.isArray(value)) {
      filtered[name] = filterData(value as Data, depth - 1);
    }
  }
  return filtered;
}

/** The name of an HTTP, resource or navigation span is (a method and) an address, which loses its query; other names are only masked. */
function filterDescription(op: string | undefined, description: string): string {
  return mask(op !== undefined && ADDRESS_OPERATION.test(op) ? withoutQuery(description) : description);
}

/** Operations whose name is an address: it loses its query and fragment (fetch and XHR, resources, navigation, pageload). */
const ADDRESS_OPERATION = /^(http|resource|navigation|pageload|browser)/;

type SpanLike = { op?: string; description?: string; data?: Data };

/** Filters a span or the trace context of a transaction (its root span) in place. */
function filterSpan(span: SpanLike): void {
  if (typeof span.description === "string") span.description = filterDescription(span.op, span.description);
  if (span.data !== undefined) span.data = filterData(span.data);
}

function filterFrames(frames: StackFrame[] | undefined): void {
  for (const frame of frames ?? []) {
    delete frame.vars;
    if (frame.filename !== undefined) frame.filename = withoutQuery(frame.filename);
    if (frame.abs_path !== undefined) frame.abs_path = withoutQuery(frame.abs_path);
  }
}

function filterRequest(request: NonNullable<Event["request"]>): NonNullable<Event["request"]> {
  const filtered: NonNullable<Event["request"]> = {};
  if (request.method !== undefined) filtered.method = request.method;
  if (request.url !== undefined) filtered.url = mask(withoutQuery(request.url));
  if (request.headers !== undefined) {
    filtered.headers = Object.fromEntries(
      Object.entries(request.headers).filter(([name]) => KEPT_HEADERS.has(name.toLowerCase())),
    );
  }
  return filtered;
}

/**
 * Filters a breadcrumb in place; a console breadcrumb (it carries the arguments of `console.*`) is dropped (null).
 */
export function filterBreadcrumb(breadcrumb: Breadcrumb): Breadcrumb | null {
  if (breadcrumb.category === "console") {
    return null;
  }
  if (breadcrumb.message !== undefined) {
    const isRequest = breadcrumb.type === "http" || breadcrumb.type === "navigation" || breadcrumb.category === "navigation";
    breadcrumb.message = mask(isRequest ? withoutQuery(breadcrumb.message) : breadcrumb.message);
  }
  if (breadcrumb.data !== undefined) {
    breadcrumb.data = filterData(breadcrumb.data);
  }
  return breadcrumb;
}

/** Filters an event or a transaction in place. */
export function filterEvent<T extends Event>(event: T): T {
  if (event.request !== undefined) event.request = filterRequest(event.request);
  const id = event.user?.id;
  if (typeof id === "string") event.user = { id };
  else delete event.user;
  delete event.extra;

  if (event.transaction !== undefined) event.transaction = mask(withoutQuery(event.transaction));
  if (event.message !== undefined) event.message = mask(event.message);
  if (event.logentry !== undefined) {
    const { message, params } = event.logentry;
    event.logentry = {
      ...(message === undefined ? {} : { message: mask(message) }),
      ...(params === undefined ? {} : { params: params.map((param) => mask(String(param))) }),
    };
  }
  if (event.tags !== undefined) event.tags = filterData(event.tags) as T["tags"];

  for (const [name, context] of Object.entries(event.contexts ?? {})) {
    if (context === undefined || context === null) {
      continue;
    }
    if (name === "trace") {
      const trace = { ...(context as TraceContext) };
      filterSpan(trace as SpanLike);
      event.contexts!.trace = trace;
    } else {
      event.contexts![name] = filterData(context, 1);
    }
  }

  for (const exception of event.exception?.values ?? []) {
    if (exception.value !== undefined) exception.value = mask(exception.value);
    filterFrames(exception.stacktrace?.frames);
  }
  for (const thread of event.threads?.values ?? []) {
    filterFrames(thread.stacktrace?.frames);
  }

  if (event.breadcrumbs !== undefined) {
    event.breadcrumbs = event.breadcrumbs.map(filterBreadcrumb).filter((breadcrumb) => breadcrumb !== null);
  }

  for (const span of event.spans ?? []) {
    filterSpan(span as SpanLike);
  }

  return event;
}
