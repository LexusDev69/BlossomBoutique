# Blossom Boutique

Premium e-commerce website for localhost (XAMPP) with PHP + MySQL, including:

- Luxury minimalist frontend UI with animations and micro-interactions
- Home, Shop, Product, Cart, Checkout, Order Confirmation pages
- User auth (login/signup), account order history
- Admin panel (product management, order monitoring, inventory tracking, sales summary)
- Logistics tracking system (Shopee-style parcel journey, admin shipment control, rider/proof-of-delivery, customer timeline)
- Customer service support tickets (customer AI-assisted chat + admin ticket handling)
- Post-delivery product feedback system (order-based reviews, product page ratings, homepage testimonials)
- Database schema + seed data

Domain focus: premium handmade satin flower bouquets (anniversary, birthday, bridal, gifting).

## Setup (XAMPP localhost)

1. Import [`database/schema.sql`](/c:/xampp/htdocs/blossom/database/schema.sql) in phpMyAdmin.
2. Ensure Apache and MySQL are running in XAMPP.
3. Open `http://localhost/blossom`.

## Default Admin

- Email: `admin@blossom.com`
- Password: `admin123`
- Backup Email: `admin2@blossom.com`
- Backup Password: `admin_temp_123`

## Tech Notes

- DB config is in `config/config.php`.
- Cart is session-based; products/orders are persisted in MySQL.
- PayRex Checkout integration is implemented (`checkout.php`, `payrex-return.php`, `payrex-webhook.php`).
- Logistics pages:
  - Admin: `admin/logistics.php`
  - Customer: `tracking.php?id={order_id}`
  - Features: milestone progress bar, courier/rider details, failed-attempt count, proof-of-delivery image, full event timeline
- Feedback migration (for existing DB):
  - `database/feedback_migration.sql`
- Support ticket migration (for existing DB):
  - `database/support_ticket_migration.sql`
- User profile migration (for richer signup/customer details):
  - `database/user_profile_migration.sql`
- Username migration:
  - `database/username_migration.sql`
- Product catalog refresh (realistic flower photos + max PHP 2,500 pricing):
  - `database/product_catalog_refresh_migration.sql`

## PayRex Setup

1. Put your PayRex keys in [`config/config.php`](/c:/xampp/htdocs/blossom/config/config.php):
   - `PAYREX_SECRET_KEY` = your server API key
   - `PAYREX_API_BASE_URL` = `https://api.payrexhq.com`
   - `PAYREX_WEBHOOK_SECRET` = optional webhook secret (if your setup signs callbacks)
2. If your DB is already created, run [`database/payrex_migration.sql`](/c:/xampp/htdocs/blossom/database/payrex_migration.sql) once.
3. In PayRex dashboard, set webhook URL to:
   - `http://localhost/blossom/payrex-webhook.php`
4. In checkout page, choose `PayRex Checkout` and place order.
5. The app creates a PayRex Checkout Session link, redirects the browser to PayRex, then returns to:
   - `http://localhost/blossom/payrex-return.php`

## Payment Flow

1. Customer places order in checkout.
2. System creates order with `pending` status.
3. System creates a PayRex Checkout Session payment link and redirects customer.
4. On successful payment, webhook and return page finalize order as `paid`.
5. Stock is deducted only when payment is confirmed.
