import { afterEach, describe, expect, it, vi } from "vitest";
import { kinetixFetch, xsrfToken } from "@/composables/useKinetixHttp";

afterEach(() => {
  vi.unstubAllGlobals();
  document.cookie = "XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT";
});

function stubFetch(
  response: Partial<Response> & { json?: () => Promise<unknown> },
) {
  const fetchMock = vi.fn().mockResolvedValue({
    ok: true,
    status: 200,
    text: () => Promise.resolve(JSON.stringify({ ok: true })),
    clone: () => ({ json: () => Promise.resolve({}) }),
    ...response,
  });
  vi.stubGlobal("fetch", fetchMock);

  return fetchMock;
}

describe("useKinetixHttp", () => {
  it("reads the XSRF-TOKEN cookie", () => {
    document.cookie = "XSRF-TOKEN=abc%3D123";
    expect(xsrfToken()).toBe("abc=123");
  });

  it("sends JSON with the standard stateful headers + credentials", async () => {
    document.cookie = "XSRF-TOKEN=tok";
    const fetchMock = stubFetch({});

    await kinetixFetch("/x", { method: "post", body: { a: 1 } });

    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe("/x");
    expect(init.method).toBe("POST");
    expect(init.credentials).toBe("same-origin");
    expect(init.headers.Accept).toBe("application/json");
    expect(init.headers["X-Requested-With"]).toBe("XMLHttpRequest");
    expect(init.headers["X-XSRF-TOKEN"]).toBe("tok");
    expect(init.headers["Content-Type"]).toBe("application/json");
    expect(init.body).toBe(JSON.stringify({ a: 1 }));
  });

  it("sends FormData as multipart (no JSON Content-Type)", async () => {
    const fetchMock = stubFetch({});
    const form = new FormData();
    form.append("file", "x");

    await kinetixFetch("/upload", { method: "POST", body: form });

    const [, init] = fetchMock.mock.calls[0];
    expect(init.headers["Content-Type"]).toBeUndefined();
    expect(init.body).toBeInstanceOf(FormData);
  });

  it("throws the server message on a non-2xx response", async () => {
    stubFetch({
      ok: false,
      status: 422,
      clone: () => ({ json: () => Promise.resolve({ message: "Too big" }) }),
    });

    await expect(kinetixFetch("/x", { method: "POST" })).rejects.toThrow(
      "Too big",
    );
  });

  it("returns null for a 204 response", async () => {
    stubFetch({ status: 204 });
    expect(await kinetixFetch("/x", { method: "DELETE" })).toBeNull();
  });
});
