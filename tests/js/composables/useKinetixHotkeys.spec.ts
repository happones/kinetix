import { describe, expect, it } from "vitest";
import {
  eventMatchesStep,
  isTypingTarget,
  normalizeKey,
  sequenceMatches,
} from "@/composables/useKinetixHotkeys";

const key = (init: KeyboardEventInit): KeyboardEvent =>
  new KeyboardEvent("keydown", init);

describe("useKinetixHotkeys matcher", () => {
  it("normalizes keys", () => {
    expect(normalizeKey(" ")).toBe("space");
    expect(normalizeKey("Escape")).toBe("escape");
    expect(normalizeKey("C")).toBe("c");
  });

  it("matches a plain key only without modifiers", () => {
    expect(eventMatchesStep(key({ key: "c" }), "c")).toBe(true);
    // browser copy must not trigger a bare `c` binding
    expect(eventMatchesStep(key({ key: "c", ctrlKey: true }), "c")).toBe(false);
  });

  it("matches a mod combo (Ctrl or Cmd)", () => {
    expect(eventMatchesStep(key({ key: "e", metaKey: true }), "mod+e")).toBe(
      true,
    );
    expect(eventMatchesStep(key({ key: "e", ctrlKey: true }), "mod+e")).toBe(
      true,
    );
    expect(eventMatchesStep(key({ key: "e" }), "mod+e")).toBe(false);
  });

  it("requires shift when declared but allows it for symbol keys", () => {
    expect(eventMatchesStep(key({ key: "?", shiftKey: true }), "?")).toBe(true);
    expect(eventMatchesStep(key({ key: "s", shiftKey: true }), "shift+s")).toBe(
      true,
    );
    expect(eventMatchesStep(key({ key: "s" }), "shift+s")).toBe(false);
  });

  it("matches a sequence by the tail of the buffer", () => {
    expect(sequenceMatches(["x", "g", "i"], ["g", "i"])).toBe(true);
    expect(sequenceMatches(["g", "x"], ["g", "i"])).toBe(false);
    expect(sequenceMatches(["i"], ["g", "i"])).toBe(false);
  });

  it("detects typing targets", () => {
    const input = document.createElement("input");
    const div = document.createElement("div");
    expect(isTypingTarget(input)).toBe(true);
    expect(isTypingTarget(div)).toBe(false);
  });
});
