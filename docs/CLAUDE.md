# Documentation rules (`docs/`)

`docs/` is the public VitePress site (`npm run docs:dev`); the sidebar lives in `docs/.vitepress/config.ts`. Host developers and their AI agents learn Kinetix from these pages and from the Boost skills in `resources/boost/skills/`, so both must describe the current behaviour exactly.

- **One page per feature,** segmented by topic, linked from the sidebar. A public API change updates its page and its Boost skill (`resources/boost/skills/kinetix-<module>/SKILL.md`) in the same commit.
- **Describe Kinetix in its own terms.** Never name other admin frameworks, here, in the skills, in the README or in published JS comments (see the root `CLAUDE.md`).
- **Show the real API:** signatures and defaults copied from the code, config keys with their defaults, a minimal working example, and the multi-team behaviour (what is scoped per team, what happens with teams off).
- **Security and limits are documented,** not implied: what is authorized where, what fails closed, engine differences (e.g. full-text falling back to Contains on SQLite).
- **Upgrade notes** live in the CHANGELOG; a page links to them when a behaviour changed. Never advise `--force` on config publishing.
- **Claims are checked:** a performance or compatibility claim is something you measured or tested, worded as exactly what was shown.
- Screenshots come from the gallery (`npm run screenshots`), not hand-made captures.
