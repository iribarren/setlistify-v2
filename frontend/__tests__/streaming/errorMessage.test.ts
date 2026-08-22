import { ApiError } from "@/lib/api";
import { describeStreamingError, providerDisplayName, revocationFollowUp } from "@/lib/streaming/errorMessage";

describe("describeStreamingError", () => {
  it("gives an honest network message for a status-0 failure", () => {
    expect(describeStreamingError(new ApiError({ status: 0, title: "Network request failed" }))).toMatch(
      /couldn't reach the server/i,
    );
  });

  it("tells the user to log in again on 403", () => {
    expect(describeStreamingError(new ApiError({ status: 403, title: "Forbidden" }))).toMatch(/log in again/i);
  });

  it("gives a not-found message on 404 (D-77's cross-owner shape)", () => {
    expect(describeStreamingError(new ApiError({ status: 404, title: "Not Found" }))).toMatch(/could not be found/i);
  });

  it("falls back to the server's detail/title otherwise", () => {
    expect(describeStreamingError(new ApiError({ status: 500, title: "Internal Server Error" }))).toBe(
      "Internal Server Error",
    );
  });

  it("gives a generic message for a non-ApiError value", () => {
    expect(describeStreamingError(new Error("boom"))).toMatch(/something went wrong/i);
  });
});

describe("providerDisplayName", () => {
  it("names Spotify explicitly", () => {
    expect(providerDisplayName("spotify")).toBe("Spotify");
  });

  it("title-cases an unrecognised provider key rather than failing", () => {
    expect(providerDisplayName("youtube")).toBe("Youtube");
  });
});

describe("revocationFollowUp (D-81/AC-3.3)", () => {
  it("tells the user Spotify has no revocation endpoint and links to their Spotify apps settings", () => {
    const followUp = revocationFollowUp("spotify");
    expect(followUp).not.toBeNull();
    expect(followUp?.message).toMatch(/Spotify/);
    expect(followUp?.url).toBe("https://www.spotify.com/account/apps/");
  });

  it("is null for a provider with no known revocation gap", () => {
    expect(revocationFollowUp("youtube")).toBeNull();
  });
});
