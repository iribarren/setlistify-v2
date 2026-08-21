#!/usr/bin/env node
// Regenerates `frontend/api/` from the backend's OpenAPI document (AC-6.1, D-10, D-11).
//
// Default source: the file produced by
//   docker compose exec backend bin/console api:openapi:export --output=openapi.json
// which the implementer (or CI's `backend` job, via an artifact) has already placed at
// `backend/openapi.json` — deterministic, needs no running HTTP server (AC-6.2, D-10).
//
// `--live` variant: fetches the same document from a running backend's
// `GET /api/docs.jsonopenapi` instead, for a developer who already has the stack up
// (AC-6.2, documented variant).
//
// `frontend/api/` is committed (AC-6.3) and MUST NEVER be hand-edited (R-1) — if generation
// output is wrong, the fix is in the backend's resource metadata or in this script's generator
// config, never a patch to the generated file itself.

import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import openapiTS, { astToString } from "openapi-typescript";

const __dirname = dirname(fileURLToPath(import.meta.url));
const frontendRoot = resolve(__dirname, "..");
const outDir = resolve(frontendRoot, "api");
const outFile = resolve(outDir, "schema.d.ts");

const isLive = process.argv.includes("--live");

const GENERATED_HEADER = `/**
 * GENERATED FILE — do not edit by hand (AC-6.3, R-1).
 *
 * Produced by: npm run generate:api${isLive ? ":live" : ""}
 *   ${
     isLive
       ? "Source: GET \\${EXPO_PUBLIC_API_URL}/api/docs.jsonopenapi (a running backend)"
       : "Source: backend/openapi.json (docker compose exec backend bin/console api:openapi:export --output=openapi.json)"
   }
 *
 * If this file looks wrong, the fix is in the backend's API Platform resource metadata, or in
 * frontend/scripts/generate-api.mjs's generator config — never a hand-edit here. See
 * docs/specs/2026-08-21-frontend-skeleton.md, R-1.
 */
`;

async function loadSchema() {
  if (isLive) {
    const baseUrl = process.env.EXPO_PUBLIC_API_URL ?? "http://localhost:8000";
    const url = `${baseUrl}/api/docs.jsonopenapi`;
    console.log(`[generate-api] Fetching live schema from ${url}`);
    const response = await fetch(url);
    if (!response.ok) {
      throw new Error(`Failed to fetch ${url}: HTTP ${response.status}`);
    }
    return response.json();
  }

  const docPath = process.env.OPENAPI_DOCUMENT_PATH ?? resolve(frontendRoot, "..", "backend", "openapi.json");
  if (!existsSync(docPath)) {
    throw new Error(
      `No OpenAPI document at ${docPath}. Export it first:\n` +
        "  docker compose exec backend bin/console api:openapi:export --output=openapi.json\n" +
        "  docker compose cp backend:/app/openapi.json backend/openapi.json\n" +
        "…or set OPENAPI_DOCUMENT_PATH, or run `npm run generate:api:live` against a running backend.",
    );
  }
  console.log(`[generate-api] Reading schema from ${docPath}`);
  return JSON.parse(readFileSync(docPath, "utf-8"));
}

async function main() {
  const schema = await loadSchema();
  const ast = await openapiTS(schema);
  const output = astToString(ast);

  mkdirSync(outDir, { recursive: true });
  writeFileSync(outFile, GENERATED_HEADER + output);
  console.log(`[generate-api] Wrote ${outFile}`);
}

main().catch((error) => {
  console.error(`[generate-api] ${error.message}`);
  process.exitCode = 1;
});
