import type { BrowserOptions } from "@sentry/nextjs";
import { filterBreadcrumb, filterEvent } from "./filter";

/**
 * The options of the SDK that keep the data of customers out of Sentry, the same for the server and the browser
 * (spec: docs/features/sentry/spec.md, D4). The SDK of this version has no `sendDefaultPii`; `dataCollection` is its
 * replacement and collects no user, cookies, bodies, query strings, frame variables or lines of source around the
 * frames (they would carry the literals of the code; source maps give the code), and of the headers only User-Agent,
 * Content-Type and Accept. The filter removes the same once more from everything the SDK sends.
 */
export const filterOptions = {
  dataCollection: {
    userInfo: false,
    cookies: false,
    httpHeaders: { request: { allow: ["user-agent", "content-type", "accept"] }, response: false },
    httpBodies: [],
    urlQueryParams: false,
    stackFrameVariables: false,
    frameContextLines: 0,
    databaseQueryData: false,
    queues: false,
    graphQL: { document: false, variables: false },
    genAI: { inputs: false, outputs: false },
    mcp: { inputs: false, outputs: false },
  },
  beforeSend: filterEvent,
  beforeSendTransaction: filterEvent,
  beforeBreadcrumb: filterBreadcrumb,
} satisfies Pick<BrowserOptions, "dataCollection" | "beforeSend" | "beforeSendTransaction" | "beforeBreadcrumb">;
