# Changelog

## [Unreleased]

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

### Compact Core Content Components
- Added an isolated `ui-components.css` layer for common authenticated page-content primitives while preserving the VibRetail palette.
- Compacted operational page headers, generic panels, buttons, form fields, form grids, action bars and summary rows.
- Replaced content-level hover lift, elevation and input focus glow with flat border/outline interaction states.
- Added automated UI-03A regression coverage while leaving tables, dashboard cards, overlays and transaction layout composition for later gates.
