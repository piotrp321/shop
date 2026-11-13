// The panel does not depend on @types/node. Tests that run in the node environment read the files of the project with
// this one function; Vitest turns `?raw` imports of .css files into empty strings, so those are read from disk.
declare module "node:fs" {
  export function readFileSync(path: string | URL, encoding: "utf8"): string;
}
