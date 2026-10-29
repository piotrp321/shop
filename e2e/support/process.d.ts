// Only `process.env` is used, and `@types/node` is not a dependency of this package.
declare const process: { env: Record<string, string | undefined> };
