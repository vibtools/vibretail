# Changelog

## [Unreleased]

### Release Automation Hotfix
- Fixed GitHub Actions SHA-256 verification so checksum basenames are resolved from the release asset directory.
- Masked dynamically generated CI service/admin credentials before exporting them to subsequent Actions steps.
- Updated the Node quality gate to the current `actions/setup-node@v7` / Node 24 baseline.
- Added regression coverage for checksum path resolution and CI secret masking.

### Automated Release Pipeline
- Added tag-triggered GitHub Actions publishing for `v*` Semantic Version tags.
- Added a cross-platform PHP static runner so the same regression suite runs on Windows and GitHub-hosted Linux runners.
- Added an ephemeral MariaDB 10.4 integration/UAT gate restricted to local `vibretail_ci*` databases.
- Added verified cPanel ZIP packaging with a release manifest and SHA-256 checksum.
- Hardened release validation for arbitrary active `.env.*` files, private runtime files, real product uploads and embedded private-key patterns.
- GitHub Releases now publish only after static, UAT, release-tree, ZIP-integrity and checksum gates pass.
- The workflow-generated `VibRetail-<version>-cpanel.zip` is the supported deployment asset; GitHub automatic Source code archives remain repository snapshots.

### Final UI R6 Sidebar Footer Invariant
- Removed the legacy dashboard-only `$shellShowDeveloperCredit = false` override that suppressed the Vib Tools/About footer only on Dashboard.
- Removed the shared shell footer visibility flag entirely so authenticated pages always render the company/About footer.
- Updated the reusable-shell regression to reject footer suppression on every authenticated wrapper.
- Added a dedicated footer-invariant regression that scans all authenticated wrappers and verifies the persistent flex/scroll layout contract.

### Final UI R5 Polish
- Pinned the sidebar company/About footer so it remains visible while navigation scrolls independently.
- Replaced the developer-facing database-status footer with a customer-facing Vib Tools product line and company links.
- Rebuilt topbar alignment, switched support text to a direct WhatsApp action, and reduced the profile control to the avatar/icon only.
- Added the official Vib Tools website, contact channels, social links and brand icon reference across About, login, installer and shell metadata.
- Fixed the UAT license/About gate after retirement of the public `license.php` route while retaining root/source license files.

### Final UI R4 Corrections
- Polished the authenticated topbar into a tighter 44px header and turned the System control into a direct Business Settings navigation action.
- Rebuilt Business Settings navigation into functional Business Profile, Invoice Setup, POS Settings and Backup sections.
- Added safe presentation normalization for exact legacy tagline/website defaults while preserving custom business values.
- Replaced the public `license.php` surface with a new About VibRetail page featuring Vib Tools and product capability information; license terms remain in repository/source license files.

### Final UI R2
- Removed decorative page/card/table subtitles from the operational UI.
- Added reusable client-side pagination for data tables and recent activity logs with search-aware page reset.
- Refined sidebar hover/active states to the VibTools compact border-accent structure using VibRetail colors.
- Rebuilt the dashboard into a compact operational KPI/trend/cash/recent-sales layout.
- Migrated authorized runtime/repository branding from Cloud Core POS / Cloudcore Soft to VibRetail / Vib Tools while preserving the existing non-commercial license terms.


### Repository Foundation
- Locked the target working name `VibRetail`.
- Migrated the local development workspace to the VibProject repository structure.
- Separated deployable source, tests, scripts, public docs, assets, and private project records.
- Preserved application runtime behavior and current license/attribution pending a dedicated branding/license scope.

### UI Foundation
- Added the locked VibRetail compact UI structural token system based on the VibTools Web UI v2.1.2 design structure.
- Switched the application typography foundation to an Inter-first stack and 13px primary UI text while preserving all VibRetail theme colors.
- Added opt-in `vr-*` compact card, button, form, table, badge, layout, focus, motion, and reduced-motion primitives for phased migration.
- Added automated UI-foundation regression checks that protect the frozen VibRetail palette and the compact structural contract.

### UI Shell Architecture
- Centralized authenticated application context, canonical navigation and shared shell rendering under `src/ui/`.
- Replaced 64 duplicated authenticated page shells with metadata-only route wrappers while preserving route filenames and page keys.
- Preserved the existing shell DOM, `POS_CONFIG`, session/CSRF/auth-version behavior and current visual sizing for the UI-02A architecture gate.
- Blocked direct HTTP access to internal `src/ui/` PHP source and added reusable-shell regression coverage.

### Compact Application Shell
- Added an isolated `ui-shell.css` visual layer that activates the UI-01 shell measurements without recoloring VibRetail.
- Compacted the authenticated desktop sidebar to 196px, topbar to 44px, navigation rows to 30px and shell controls to 28px.
- Flattened shell navigation/dropdown elevation, reduced navigation typography weight and tightened sidebar/topbar spacing.
- Added locally anchored Quick Add/Profile dropdowns, responsive compact-shell rules and automated UI-02B visual-shell regression coverage.

### Approved Light Professional Color System
- Replaced the previous saturated green-heavy application palette with the exact user-approved light professional reference palette.
- Standardized the application on neutral gray/white surfaces, professional blue primary actions/navigation, and restrained semantic emerald/blue/amber/purple/red accents.
- Reworked Dashboard quick actions into neutral controls with one primary CTA and changed KPI cards to white surfaces with semantic 4px left-border accents.
- Updated table headers/row hover, badges, form focus, sidebar active/hover, login/setup surfaces and trend-chart colors to the approved reference system.

### Compact Core Content Components
- Added an isolated `ui-components.css` layer for common authenticated page-content primitives while preserving the VibRetail palette.
- Compacted operational page headers, generic panels, buttons, form fields, form grids, action bars and summary rows.
- Replaced content-level hover lift, elevation and input focus glow with flat border/outline interaction states.
- Added automated UI-03A regression coverage while leaving tables, dashboard cards, overlays and transaction layout composition for later gates.

### Final UI Completion
- Added the authoritative `ui-complete.css` layer to finish the remaining compact application presentation against the frozen VibRetail palette.
- Completed compact tables, row actions, badges, tabs, native choice controls, file uploads, dashboard surfaces, modal/toast/loading states, reports, HRM, settings, marketplace and transaction density.
- Applied the same structural UI language to login, installer and license presentation without changing branding, license terms, routes, API behavior, database logic or RBAC.
- Added final UI regression coverage and compact print styling while preserving all previously accepted UI foundation/shell/component gates.
