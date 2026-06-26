import { createApp, defineComponent, h } from "vue";
import { createI18n } from "vue-i18n";
import { Toaster } from "vue-sonner";
import enMessages from "./messages.en.json";
import { specimens } from "./specimens";
import "./app.css";

const params = new URLSearchParams(location.search);
const name = params.get("s") ?? specimens[0].name;
const theme = params.get("theme") ?? "light";

if (theme === "dark") {
  document.documentElement.classList.add("dark");
}

const specimen = specimens.find((s) => s.name === name) ?? specimens[0];

const i18n = createI18n({
  legacy: false,
  locale: "en",
  missingWarn: false,
  fallbackWarn: false,
  messages: { en: enMessages as Record<string, string> },
});

const Frame = defineComponent({
  name: "GalleryFrame",
  setup() {
    const mounted = h(specimen.component as any, specimen.props ?? {}, specimen.slots ?? {});

    // Components that aren't already a card render inside one so the screenshot
    // shows them with realistic in-app chrome.
    const body =
      specimen.frame === "card"
        ? h(
            "div",
            { class: "rounded-xl border border-border bg-card p-6 shadow-sm" },
            [mounted],
          )
        : mounted;

    return () =>
      h(
        "div",
        {
          // The screenshot script crops to #specimen.
          id: "specimen",
          style: {
            width: `${specimen.width ?? 600}px`,
            padding: "32px",
            background: "hsl(var(--background))",
          },
        },
        [body],
      );
  },
});

const app = createApp({
  render: () => h("div", { style: "display:inline-block" }, [h(Frame), h(Toaster)]),
});
app.use(i18n);
app.mount("#app");
