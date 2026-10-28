import vue from "@vitejs/plugin-vue";
import { defineConfig } from "vite";

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: { "@": new URL("./src", import.meta.url).pathname },
  },
  server: {
    // Inside Docker the dev server must listen on all interfaces. localhost (any port) is allowed by default.
    host: "0.0.0.0",
    port: 5173,
    strictPort: true,
  },
});
