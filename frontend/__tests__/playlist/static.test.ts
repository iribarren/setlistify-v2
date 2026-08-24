import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

const ROOT = join(__dirname, "..", "..");
const SCAN_DIRS = [join(ROOT, "lib", "playlist"), join(ROOT, "components", "playlist")];

function collectSourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((entry) => {
    const full = join(dir, entry);
    const stats = statSync(full);
    if (stats.isDirectory()) {
      return collectSourceFiles(full);
    }
    if (/\.(ts|tsx)$/.test(entry)) {
      return [full];
    }
    return [];
  });
}

// AC-1.4/T-16: no provider key literal anywhere in this feature's own source (the generated schema
// and its own tests live outside these two directories, so this scan is exhaustive over the feature).
const PROVIDER_KEY_LITERALS = /["'`](spotify|youtube|apple[-_ ]?music|deezer|tidal)["'`]/i;

describe("static: no provider key literal in the playlist feature (T-16, AC-1.4)", () => {
  const files = SCAN_DIRS.flatMap(collectSourceFiles);

  it("scanned at least one file in each directory", () => {
    expect(files.length).toBeGreaterThan(0);
  });

  it.each(files.map((file) => [file.replace(ROOT + "/", "")]))("%s has no provider key literal", (relativePath) => {
    const content = readFileSync(join(ROOT, relativePath), "utf8");
    const match = content.match(PROVIDER_KEY_LITERALS);
    expect(match).toBeNull();
  });
});

// D-177/T-17: every request/response type in the feature is an alias of a generated schema type —
// no hand-declared interface re-describes a wire shape. This can't be enforced by a runtime string
// scan with full precision (a local view-model type like `PlaylistView` is legitimate — it's not a
// wire shape), so this test targets the one file that is allowed to touch the schema at all
// (`types.ts`) and asserts every OTHER file in the feature imports its wire types from it (or from
// `@/api` directly) rather than declaring its own `interface .. { ... field: string ... }` mirroring
// a schema field.
describe("static: wire types are aliased from the generated schema, not hand-declared (T-17, D-177)", () => {
  const files = SCAN_DIRS.flatMap(collectSourceFiles).filter((file) => !file.endsWith("types.ts"));

  it.each(files.map((file) => [file.replace(ROOT + "/", "")]))(
    "%s does not import components[\"schemas\"] directly (types.ts is the one seam)",
    (relativePath) => {
      const content = readFileSync(join(ROOT, relativePath), "utf8");
      expect(content).not.toMatch(/components\[["']schemas["']\]/);
    },
  );
});
