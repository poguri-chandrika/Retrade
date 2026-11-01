# Retrade - Mini Marketplace with Escrow (PHP + MySQL)

Retrade is a mini marketplace web app featuring Buyer, Seller, and Admin roles with an escrow-like payment system. Built with PHP (XAMPP), MySQL, Bootstrap 5.

## Features
- Buyer: Register/Login, browse/search, view products, buy via escrow, confirm or dispute delivery.
- Seller: Register/Login, add/edit/delete products, see stats and earnings.
- Admin: Approve/reject products, manage users, oversee escrow payments (approve, release, refund), see stats and commission.

## Tech Stack
- Frontend: HTML5, CSS3, JavaScript, Bootstrap 5
- Backend: PHP 7+/8+
- Database: MySQL (XAMPP)

## Project Structure
```
retrade/
 ├── index.php
 ├── register.php
 ├── login.php
 ├── logout.php
 ├── dashboard.php
 ├── product_details.php
 ├── buy_product.php
 ├── confirm_delivery.php
 ├── add_product.php
 ├── edit_product.php
 ├── delete_product.php
 ├── admin_panel.php
 ├── approve_product.php
 ├── manage_users.php
 ├── manage_payments.php
 ├── database.sql
 ├── config.php
 ├── includes/
 │   ├── header.php
 │   ├── navbar.php
 │   └── footer.php
 └── assets/
     ├── css/style.css
     ├── js/script.js
     └── img/ (uploads)
```

## Setup (XAMPP)
1. Copy the `Retrade-WT` folder into `htdocs` (e.g., `C:/xampp/htdocs/Retrade-WT`).
2. Start Apache and MySQL in XAMPP Control Panel.
3. Open phpMyAdmin at http://localhost/phpmyadmin.
4. Import the database:
   - Create DB `retrade_db` or import `database.sql` directly.
   - File: `database.sql` in project root contains schema + seed data.
5. Configure DB credentials if needed in `config.php` (defaults: user `root`, no password).
6. Visit the app at: http://localhost/Retrade-WT/

## Demo Accounts
- Admin: `admin@retrade.com` / `password`
- Seller: `seller@retrade.com` / `password`
- Buyer: `buyer@retrade.com` / `password`

## Escrow Flow (Simulated)
- Buyer clicks Buy Now -> amount is deducted from buyer balance and credited to Admin balance (escrow).
- Transaction created with status `pending`.
- Admin can `Approve` (no money movement), `Release` (moves 90% to seller, keeps 10% commission), or `Refund` (full refund to buyer) in `manage_payments.php`.
- Buyer can `Confirm Delivery` (auto-release funds) or `Dispute` (flags for admin review) in `confirm_delivery.php`.

## Security & Best Practices
- Prepared statements everywhere.
- Passwords stored with `password_hash` and checked with `password_verify`.
- File uploads are type and size restricted and stored under `assets/img/`.
- Role-based access enforced via `config.php` helper functions.

## Notes
- Balances are stored in `users.balance`. The app simulates a wallet + escrow using the Admin account as the escrow holder.
- Image uploads require write permissions to `assets/img/`.
- You can add your own payment gateway in `buy_product.php` (insert gateway before the DB transaction and only mark as paid when gateway returns success).

## Customization
- Commission rate is currently 10%. Adjust in `buy_product.php` (admin_commission) to a different percentage if desired.
- Default buyer signup bonus is 1000 (see `register.php`).

## Troubleshooting
- If you see connection errors, verify DB credentials in `config.php` and that MySQL is running.
- If product images don’t appear, ensure files exist in `assets/img/` and PHP can write to that folder.
- If you imported `database.sql` multiple times, you may get duplicate seed data. You can truncate tables in phpMyAdmin.

## Next Steps (Optional Enhancements)
- Add pagination and advanced filters in `index.php`.
- Add seller order history and payout statements.
- Email notifications to buyer/seller/admin on key events.
- Integrate PayPal Sandbox or Razorpay test mode.

Enjoy using Retrade!
