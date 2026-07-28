import { defineConfig } from "tsup";

export default defineConfig({
  entry: ["src/index.ts"],
  format: ["esm", "cjs"],
  clean: true,
  sourcemap: true,
  minify: true,
  dts: false,
  target: "es2022"
});
