import DefaultTheme from "vitepress/theme";
import type { EnhanceAppContext } from "vitepress";
import Screenshot from "./Screenshot.vue";
import "./custom.css";

export default {
  extends: DefaultTheme,
  enhanceApp({ app }: EnhanceAppContext) {
    app.component("Screenshot", Screenshot);
  },
};
