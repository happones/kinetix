import { config, mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import KinetixFilePreview from "../KinetixFilePreview.vue";
import { executeAction } from "@/composables/useKinetixActions";
import { i18n } from "./i18n";

config.global.plugins = [i18n];

function firePreview(detail: Record<string, unknown>) {
  window.dispatchEvent(new CustomEvent("kinetix:preview", { detail }));
}

describe("KinetixFilePreview", () => {
  afterEach(() => {
    document.body.innerHTML = "";
  });

  it("opens with an <img> when an image url is dispatched", async () => {
    mount(KinetixFilePreview, { attachTo: document.body });

    firePreview({ url: "https://x/photo.png", label: "Photo" });
    await nextTick();

    const img = document.body.querySelector("img");
    expect(img).not.toBeNull();
    expect(img?.getAttribute("src")).toBe("https://x/photo.png");
  });

  it("renders an <iframe> for a PDF url", async () => {
    mount(KinetixFilePreview, { attachTo: document.body });

    firePreview({ url: "https://x/invoice.pdf" });
    await nextTick();

    expect(document.body.querySelector("iframe")).not.toBeNull();
  });

  it("honours an explicit type over the extension", async () => {
    mount(KinetixFilePreview, { attachTo: document.body });

    firePreview({ url: "https://x/file?id=9", type: "image" });
    await nextTick();

    expect(document.body.querySelector("img")).not.toBeNull();
  });

  it("removes the window listener on unmount (no leak)", () => {
    const remove = vi.spyOn(window, "removeEventListener");
    const wrapper = mount(KinetixFilePreview);

    wrapper.unmount();

    expect(remove).toHaveBeenCalledWith(
      "kinetix:preview",
      expect.any(Function),
    );
    remove.mockRestore();
  });
});

describe("executeAction preview/download", () => {
  it("dispatches kinetix:preview for a preview action", () => {
    const handler = vi.fn();
    window.addEventListener("kinetix:preview", handler);

    executeAction({
      name: "preview",
      label: "Preview",
      url: "/files/1",
      isPreview: true,
      previewType: "pdf",
    } as any);

    expect(handler).toHaveBeenCalledOnce();
    const detail = (handler.mock.calls[0][0] as CustomEvent).detail;
    expect(detail).toMatchObject({ url: "/files/1", type: "pdf" });

    window.removeEventListener("kinetix:preview", handler);
  });

  it("triggers an anchor download for a download action", () => {
    const click = vi.fn();
    const create = vi
      .spyOn(document, "createElement")
      .mockImplementation(
        () => ({ click, remove: vi.fn(), setAttribute: vi.fn() }) as any,
      );
    const append = vi
      .spyOn(document.body, "appendChild")
      .mockImplementation((n) => n);

    executeAction({
      name: "download",
      label: "Download",
      url: "/files/1/download",
      isDownload: true,
    } as any);

    expect(click).toHaveBeenCalledOnce();

    create.mockRestore();
    append.mockRestore();
  });
});
