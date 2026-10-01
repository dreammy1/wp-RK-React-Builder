import { describe, expect, it } from "vitest";
import { previewUrl } from "./previewUrl";

describe("previewUrl", () => {
  it("prefers the URL WordPress returns (all-in-one mode)", () => {
    expect(
      previewUrl(
        { token: "t", url: "https://cms.example/?rk_preview=1&page=4&token=t" },
        "https://x",
        "s"
      )
    ).toBe("https://cms.example/?rk_preview=1&page=4&token=t");
  });
  it("builds the headless frontend link from the token", () => {
    expect(
      previewUrl({ token: "a.b" }, "https://site.example/", "my page")
    ).toBe("https://site.example/preview/my%20page?token=a.b");
  });
  it("refuses non-http(s) and malformed URLs and missing context", () => {
    expect(
      previewUrl({ token: "t", url: "javascript:alert(1)" }, "https://x", "s")
    ).toBeNull();
    expect(
      previewUrl({ token: "t", url: "not a url" }, "https://x", "s")
    ).toBeNull();
    expect(previewUrl({ token: "t" }, "", "s")).toBeNull();
    expect(previewUrl({ token: "t" }, "https://x", "")).toBeNull();
  });
});
