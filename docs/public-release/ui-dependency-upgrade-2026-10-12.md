# Frontend and dependency qualification

The public candidate uses Tailwind CSS 4.3.3 with its matching PostCSS adapter. Existing theme configuration, forms plugin and Decision Desk semantic CSS are retained. Deprecated utility aliases and scoped Vue references were migrated; compatible border/ring/placeholder baselines are explicit.

Use Safari 16.4+, Chrome 111+ or Firefox 128+, following the [official Tailwind upgrade guide](https://tailwindcss.com/docs/upgrade-guide). The current frontend builds and all three locked npm workspaces report zero advisory findings at 2026-10-12. This is a current audit result, not a security certification.

Compiled synthetic controls were compared in Chromium at 1440x1000 and 390x844. No horizontal overflow occurred; the existing 3px focus outline becomes visible, with minor mobile typography/height differences. Authenticated routes, complete domain operations and Safari/Firefox were not exercised.

Tailwind/Oxide carry MIT terms; Lightning CSS and its platform wrappers carry MPL-2.0. Native third-party binaries are not included. Binary redistribution requires applicable notices/source availability; preserve separately licensed dependencies and source availability when assembling a distribution. The complete locked license snapshot has no missing license metadata.
