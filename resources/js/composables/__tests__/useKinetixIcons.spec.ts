import { describe, expect, it } from "vitest";
import { resolveIcon } from "../useKinetixIcons";

describe("resolveIcon", () => {
  it("resolves every prebuilt action icon name to a component", () => {
    // Names set by EditAction/ViewAction/DeleteAction/CreateAction/
    // RestoreAction/ForceDeleteAction/DownloadAction/PreviewAction.
    for (const name of [
      "edit",
      "eye",
      "trash",
      "trash-2",
      "rotate-ccw",
      "plus",
      "download",
    ]) {
      expect(resolveIcon(name), `icon "${name}" should resolve`).toBeTruthy();
    }
  });

  it("is case-insensitive", () => {
    expect(resolveIcon("Trash-2")).toBe(resolveIcon("trash-2"));
  });

  it("returns null for empty or unknown names", () => {
    expect(resolveIcon(null)).toBeNull();
    expect(resolveIcon(undefined)).toBeNull();
    expect(resolveIcon("")).toBeNull();
    expect(resolveIcon("definitely-not-an-icon")).toBeNull();
  });
});
