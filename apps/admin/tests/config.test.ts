import { afterEach, describe, expect, it, vi } from "vitest";
import { readUrl } from "@/config";

afterEach(() => {
  delete window.__ADMIN_CONFIG__;
  vi.unstubAllEnvs();
});

describe("readUrl", () => {
  it("prefers window.__ADMIN_CONFIG__ over import.meta.env", () => {
    window.__ADMIN_CONFIG__ = { VITE_API_URL: "https://api.example.test" };
    vi.stubEnv("VITE_API_URL", "http://localhost:8000");

    expect(readUrl("VITE_API_URL")).toBe("https://api.example.test");
  });

  it("falls back to import.meta.env when window.__ADMIN_CONFIG__ has no such key", () => {
    window.__ADMIN_CONFIG__ = { VITE_KEYCLOAK_URL: "https://auth.example.test" };
    vi.stubEnv("VITE_API_URL", "http://localhost:8000");

    expect(readUrl("VITE_API_URL")).toBe("http://localhost:8000");
  });

  it("falls back to import.meta.env when window.__ADMIN_CONFIG__ is not defined", () => {
    vi.stubEnv("VITE_ADMIN_URL", "http://localhost:5173");

    expect(readUrl("VITE_ADMIN_URL")).toBe("http://localhost:5173");
  });

  it("throws naming the key when neither source has it", () => {
    window.__ADMIN_CONFIG__ = {};
    vi.stubEnv("VITE_KEYCLOAK_URL", "");

    expect(() => readUrl("VITE_KEYCLOAK_URL")).toThrow("VITE_KEYCLOAK_URL");
  });

  it("drops trailing slashes from the value of either source", () => {
    window.__ADMIN_CONFIG__ = { VITE_API_URL: "https://api.example.test//" };
    vi.stubEnv("VITE_ADMIN_URL", "http://localhost:5173/");

    expect(readUrl("VITE_API_URL")).toBe("https://api.example.test");
    expect(readUrl("VITE_ADMIN_URL")).toBe("http://localhost:5173");
  });

  it("reads explicit sources when given (no global state)", () => {
    expect(readUrl("VITE_API_URL", { runtime: { VITE_API_URL: "https://a.test/" }, env: {} })).toBe("https://a.test");
    expect(() => readUrl("VITE_API_URL", { runtime: undefined, env: {} })).toThrow("VITE_API_URL");
  });
});
