import DefaultTheme from "vitepress/theme";
import type { EnhanceAppContext } from "vitepress";
import { createPinia } from "pinia";
import { h } from "vue";
import { createI18n } from "vue-i18n";
import KinetixGenerator from "../../../resources/js/components/KinetixGenerator.vue";
import galleryMessages from "../../../gallery/messages.en.json";
import DocZoom from "./DocZoom.vue";
import Demo from "./Demo.vue";
import Screenshot from "./Screenshot.vue";
import "./custom.css";
import "./demo.css";

export default {
  extends: DefaultTheme,
  // DocZoom lives once in the layout: it makes every diagram open in a
  // pan-and-zoom viewer and every content image enlarge in place.
  Layout: () => h(DefaultTheme.Layout, null, { "layout-bottom": () => h(DocZoom) }),
  enhanceApp({ app }: EnhanceAppContext) {
    app.component("Screenshot", Screenshot);

    // vue-i18n reads these Vue feature flags at install time. In the docs' Node
    // SSR render they're undefined (VitePress doesn't inject them for the theme
    // bundle), so define them on the global before installing the plugin.
    const g = globalThis as Record<string, unknown>;
    g.__VUE_PROD_DEVTOOLS__ ??= false;
    g.__VUE_OPTIONS_API__ ??= true;
    g.__VUE_PROD_HYDRATION_MISMATCH_DETAILS__ ??= false;

    // Live component demos (<Demo>) need the same runtime the components assume:
    // vue-i18n (the gallery's compiled English bundle) and a Pinia instance.
    app.use(
      createI18n({
        legacy: false,
        locale: "en",
        missingWarn: false,
        fallbackWarn: false,
        messages: { en: galleryMessages as Record<string, string> },
      }),
    );
    app.use(createPinia());

    app.component("Demo", Demo);
    app.component("KinetixGenerator", KinetixGenerator);
  },
};
