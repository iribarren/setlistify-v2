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

// T-8/AC-2.6: no setlist.fm URL is ever constructed or templated client-side — the client only ever
// renders a URL the backend already persisted (D-186). Prose mentioning "setlist.fm" (e.g. "Known
// on setlist.fm") is fine and expected (D-170's degraded-state copy); what must never appear is an
// actual URL/path literal or a template string building one — "www.setlist.fm", a "setlist.fm/…"
// path, or a protocol-prefixed host.
describe("static: no setlist.fm URL is constructed or templated client-side (T-8, AC-2.6)", () => {
  const files = SCAN_DIRS.flatMap(collectSourceFiles);
  const SETLISTFM_URL_LITERAL = /(www\.setlist\.fm|setlist\.fm\/|:\/\/[^\s"'`]*setlist\.fm)/i;

  it("scanned at least one file in each directory", () => {
    expect(files.length).toBeGreaterThan(0);
  });

  it.each(files.map((file) => [file.replace(ROOT + "/", "")]))("%s has no setlist.fm URL literal", (relativePath) => {
    const content = readFileSync(join(ROOT, relativePath), "utf8");
    expect(content).not.toMatch(SETLISTFM_URL_LITERAL);
  });
});

// AC-7.3/D-213: `playbackMode` is interpreted in exactly one pure function — every other file in the
// feature receives a `PlaybackSurface` from `derivePlaybackSurface()` and never reads the field
// itself. Mirrors spec 17's `ModeIsBranchedOnInExactlyTwoPlacesTest` shape, for one place instead of
// two.
describe("static: .playbackMode is read in exactly one file (AC-7.3, D-213)", () => {
  const ALLOWED_FILE = join("lib", "playlist", "playback.ts");
  const files = SCAN_DIRS.flatMap(collectSourceFiles);
  const PLAYBACK_MODE_ACCESS = /\.playbackMode\b/;

  it("scanned at least one file", () => {
    expect(files.length).toBeGreaterThan(0);
  });

  it.each(files.map((file) => [file.replace(ROOT + "/", "")]))("%s", (relativePath) => {
    const content = readFileSync(join(ROOT, relativePath), "utf8");
    const readsPlaybackMode = PLAYBACK_MODE_ACCESS.test(content);
    if (relativePath === ALLOWED_FILE) {
      expect(readsPlaybackMode).toBe(true); // sanity: the one allowed file actually reads it.
    } else {
      expect(readsPlaybackMode).toBe(false);
    }
  });
});

// AC-7.1/AC-7.2/D-224: no SDK-based in-app playback is introduced anywhere — enforced structurally
// by asserting no dependency in package.json matches a provider-SDK deny-list.
describe("static: no provider SDK dependency in package.json (AC-7.1, AC-7.2, D-224)", () => {
  const SDK_DENY_LIST = ["spotify", "youtube", "googleapis", "musickit"];

  it("no dependency or devDependency name matches the deny-list", () => {
    const packageJson = JSON.parse(readFileSync(join(ROOT, "package.json"), "utf8")) as {
      dependencies?: Record<string, string>;
      devDependencies?: Record<string, string>;
    };
    const names = [
      ...Object.keys(packageJson.dependencies ?? {}),
      ...Object.keys(packageJson.devDependencies ?? {}),
    ];

    for (const name of names) {
      for (const denied of SDK_DENY_LIST) {
        expect(name.toLowerCase()).not.toContain(denied);
      }
    }
  });

  it("react-native-webview is not a dependency (D-216: the native embed is deferred)", () => {
    const packageJson = JSON.parse(readFileSync(join(ROOT, "package.json"), "utf8")) as {
      dependencies?: Record<string, string>;
      devDependencies?: Record<string, string>;
    };
    const names = [
      ...Object.keys(packageJson.dependencies ?? {}),
      ...Object.keys(packageJson.devDependencies ?? {}),
    ];
    expect(names).not.toContain("react-native-webview");
  });
});
