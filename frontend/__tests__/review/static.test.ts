import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

const ROOT = join(__dirname, "..", "..");
const SCAN_DIRS = [join(ROOT, "lib", "review"), join(ROOT, "components", "review"), join(ROOT, "hooks")];

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

// AC-9.4/D-237: review text is plain text with no rendering contract — the same shape of guard as
// spec 19's D-224 provider-literal test and this project's own playlist static tests.
describe("static: no HTML rendering in the review module (AC-9.4, D-237)", () => {
  const files = SCAN_DIRS.flatMap(collectSourceFiles);
  const HTML_RENDERING_MARKERS = /dangerouslySetInnerHTML|RenderHtml|react-native-render-html|WebView/;

  it("scanned at least one file", () => {
    expect(files.length).toBeGreaterThan(0);
  });

  it.each(files.map((file) => [file.replace(ROOT + "/", "")]))("%s has no HTML-rendering marker", (relativePath) => {
    const content = readFileSync(join(ROOT, relativePath), "utf8");
    expect(content).not.toMatch(HTML_RENDERING_MARKERS);
  });
});

describe("static: no HTML/WebView rendering dependency in package.json (AC-9.4)", () => {
  it("react-native-webview and react-native-render-html are not dependencies", () => {
    const packageJson = JSON.parse(readFileSync(join(ROOT, "package.json"), "utf8")) as {
      dependencies?: Record<string, string>;
      devDependencies?: Record<string, string>;
    };
    const names = [
      ...Object.keys(packageJson.dependencies ?? {}),
      ...Object.keys(packageJson.devDependencies ?? {}),
    ];
    expect(names).not.toContain("react-native-webview");
    expect(names).not.toContain("react-native-render-html");
  });
});
