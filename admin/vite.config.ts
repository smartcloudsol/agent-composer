import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import path from "node:path";

export default defineConfig({
    plugins: [react()],
    build: {
      manifest: true,
      outDir: "dist",
      emptyOutDir: true,
      rollupOptions: {
        input: path.resolve(process.cwd(), "src", "index.tsx"),
        external: [
          "@mantine/core",
          "@mantine/hooks",
          "@mantine/notifications",
          "@wordpress/api-fetch",
          "@wordpress/i18n",
          "react",
          "react-dom",
          "react/jsx-runtime"
        ],
        output: {
          entryFileNames: "assets/[name].js",
          chunkFileNames: "assets/[name].js",
          assetFileNames: "assets/[name].[ext]"
        }
      }
    }
});
