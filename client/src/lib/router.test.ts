import { describe, expect, it } from "vitest";
import { parseRoute } from "./router";

describe("parseRoute", () => {
  it("standalone: /builder?page=ID opens the editor; everything else is the page list", () => {
    expect(parseRoute("/builder", "?page=42")).toEqual({
      name: "builder",
      pageId: 42,
      demo: false,
    });
    expect(parseRoute("/builder/", "?page=42&demo=1")).toMatchObject({
      pageId: 42,
      demo: true,
    });
    for (const search of [
      "",
      "?page=",
      "?page=0",
      "?page=-3",
      "?page=4x",
      "?page=99999999999",
      "?page=1.5",
      "?page=%00",
    ]) {
      expect(parseRoute("/builder", search), search).toEqual({ name: "pages" });
    }
    expect(parseRoute("/elsewhere", "?page=42")).toEqual({ name: "pages" });
  });
  it("embedded in wp-admin: uses rk_page and ignores the path", () => {
    expect(
      parseRoute("/wp-admin/admin.php", "?page=rk-builder&rk_page=7", true)
    ).toEqual({ name: "builder", pageId: 7, demo: false });
    expect(parseRoute("/wp-admin/admin.php", "?page=rk-builder", true)).toEqual(
      { name: "pages" }
    );
    expect(
      parseRoute("/wp-admin/admin.php", "?page=rk-builder&rk_page=abc", true)
    ).toEqual({ name: "pages" });
  });
});
