// Only `process.env` and `Buffer.from` are used, and `@types/node` is not a dependency of this package.
declare const process: { env: Record<string, string | undefined> };
declare const Buffer: { from(data: Uint8Array): Uint8Array };
