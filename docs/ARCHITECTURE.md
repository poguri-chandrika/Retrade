# Architecture Overview

This document explains how the Retrade app is organized, the data flow between layers, and the responsibilities of each folder.

## High-level
- Client (PHP templates + Bootstrap + JS)
- API (PHP endpoints under `api/`)
- Database (MySQL; migrations in `migrations/`)
- Realtime (Socket.IO server under `ws-server/`)

## Folders
- `assets/`
  - `css/` theme and component styles (`style.css`, `chat.css`, `admin.css`).
  - `js/` client logic (`script.js` global UI; `chat.js` chat modal; `admin.js` admin tools).
  - `img/` product images and static assets.
  - `uploads/` runtime uploads (chat attachments). Ignored by Git.
- `api/` stateless JSON endpoints (fetch, save, list, etc.).
  - Chat: `chat_history.php`, `chat_message_save.php`, `messages_summary.php`, `messages_mark_read.php`.
  - Notifications: `notifications_fetch.php`, `notifications_mark_read.php`.
  - Commerce: cart, wishlist, checkout (Razorpay).
- `includes/` shared layout pieces (`header.php`, `footer.php`, `sidebar.php`).
- `migrations/` SQL migration files creating/altering tables and seeding.
- `ws-server/` Node-based Socket.IO service for chat presence, typing, realtime.
- Root PHP pages: `index.php`, `product_details.php`, `dashboard.php`, etc.

## Data Flow (Chat)
1. User clicks a `.openChatBtn` (from product card/dropdown/inbox).
2. `assets/js/chat.js` requests a token from `api/ws_token.php` (or `ws_token_seller.php`).
3. Client connects to Socket.IO, joins room; history loads via `api/chat_history.php`.
4. On send: emit socket event and persist via `api/chat_message_save.php`.
5. Header dropdown pulls summaries/unread via `api/messages_summary.php`.
6. Opening a chat marks thread read via `api/messages_mark_read.php`.

## Database
- `products`, `users`, `transactions` (core commerce).
- `negotiations`, `messages` (chat threads and messages).
- `negotiation_reads` (per-user last_read_at for unread counts).
- `notifications` (site-wide notifications).

## Conventions
- All API endpoints return JSON and proper HTTP codes; use prepared statements.
- Frontend JS uses event delegation for buttons; minimal inline JS in PHP.
- CSS variables define theme tokens; utilities/components live in `style.css`.

## Build/Runtime
- PHP via XAMPP Apache; MySQL.
- `ws-server/` uses Node 18+; run with `npm install && npm start` from `ws-server/`.

## Future
- Extract page-specific JS/CSS into dedicated files per page (e.g., `product_details.js`).
- Introduce a simple Router for API endpoints (namespaced functions).
- Add tests for API using PHPUnit (or simple integration checks).
