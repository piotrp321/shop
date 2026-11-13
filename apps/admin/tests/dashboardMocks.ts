import { vi } from "vitest";

/** The `GET` of the mocked API client, shared by the dashboard tests and their support module. */
export const mocks = { get: vi.fn() };
