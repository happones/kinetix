import DefaultTheme from "vitepress/theme";
import type { EnhanceAppContext } from "vitepress";
import { h } from "vue";
import DocZoom from "./DocZoom.vue";
import Screenshot from "./Screenshot.vue";
import "./custom.css";

export default {
  extends: DefaultTheme,
  // DocZoom lives once in the layout: it makes every diagram open in a
  // pan-and-zoom viewer and every content image enlarge in place.
  Layout: () => h(DefaultTheme.Layout, null, { "layout-bottom": () => h(DocZoom) }),
  enhanceApp({ app }: EnhanceAppContext) {
    app.component("Screenshot", Screenshot);
  },
};
