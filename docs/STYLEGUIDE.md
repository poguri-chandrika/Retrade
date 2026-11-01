# Style Guide

This guide describes coding standards for PHP, JavaScript, and CSS used in Retrade.

## General
- Use UTF-8, LF endings; configured via `.editorconfig`.
- Max line length: soft 100–120 chars; wrap long HTML attributes over lines.
- Filenames: lowercase with underscores for PHP (`buy_product.php`), kebab-case for assets (`product-card.js`).
- Avoid inline `<script>`/`style` in PHP templates; prefer files in `assets/js/` and `assets/css/`.

## PHP
- 4-space indent. Use `<?php ... ?>` full tags only.
- Always use prepared statements with placeholders. Never interpolate untrusted values in SQL.
- Escape output in templates with `htmlspecialchars()`.
- Keep endpoints under `api/` return JSON with HTTP status codes and `Content-Type: application/json`.
- Organize page-specific logic near the top of each PHP page; keep rendering simple.

## JavaScript
- 2-space indent. Use `const`/`let`. Prefer event delegation for dynamic elements.
- Group features in IIFEs/modules in `assets/js/script.js` when global; page-specific files if growing.
- Use `fetch` with `credentials: 'same-origin'` for API calls. Handle non-2xx with user-friendly messages.
- Keep DOM IDs/classes consistent and semantic. Document any global functions on `window`.

## CSS
- Use CSS variables for theme tokens; already defined in `assets/css/style.css`.
- Prefer utility classes and component classes over deep selectors.
- Keep transitions subtle (150–200ms). Ensure focus-visible outlines on interactive elements.
- Add responsive behavior with mobile-first media queries.

## Commits & PRs
- Conventional commits suggested: `feat:`, `fix:`, `chore:`, `docs:`, `refactor:`, `style:`, `perf:`.
- PR description template: problem, changes, screenshots (before/after), test notes, risk & rollback.
