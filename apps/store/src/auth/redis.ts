import { createClient } from "redis";

/**
 * A Redis client that fails fast: commands are rejected while the connection is down instead of queueing,
 * so an unavailable Redis turns into a 503 rather than a hanging request. Errors are logged without data.
 */
export function createRedisClient(url: string, options: { database?: number } = {}) {
  const client = createClient({
    url,
    database: options.database,
    disableOfflineQueue: true,
    socket: {
      connectTimeout: 2000,
      reconnectStrategy: (retries) => Math.min(100 * 2 ** retries, 2000),
    },
  });
  client.on("error", (error: Error) => console.error(`Redis: ${error.message}`));
  return client;
}

export type RedisClient = ReturnType<typeof createRedisClient>;
