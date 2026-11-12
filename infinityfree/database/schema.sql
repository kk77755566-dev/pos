-- Girls Bar POS - MySQL 8.0 initial schema
-- Apply to a new database. Existing tax.html is intentionally preserved.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE stores (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_code VARCHAR(40) NOT NULL,
  store_name VARCHAR(120) NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Tokyo',
  business_day_start TIME NOT NULL DEFAULT '06:00:00',
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_stores_code (store_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_code VARCHAR(40) NOT NULL,
  role_name VARCHAR(80) NOT NULL,
  permissions_json JSON NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (role_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  login_id VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  last_login_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_store_login (store_id, login_id),
  KEY ix_users_store_role (store_id, role_id),
  CONSTRAINT fk_users_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE store_settings (
  store_id BIGINT UNSIGNED NOT NULL,
  tax_enabled BOOLEAN NOT NULL DEFAULT TRUE,
  tax_rate DECIMAL(7,4) NOT NULL DEFAULT 10.0000,
  tax_mode ENUM('inclusive','exclusive') NOT NULL DEFAULT 'exclusive',
  service_enabled BOOLEAN NOT NULL DEFAULT FALSE,
  service_rate DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
  service_mode ENUM('rate','fixed') NOT NULL DEFAULT 'rate',
  service_fixed_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  service_taxable BOOLEAN NOT NULL DEFAULT TRUE,
  service_basis ENUM('sets_only','items_only','all_subtotal','configured') NOT NULL DEFAULT 'all_subtotal',
  rounding_mode ENUM('floor','half_up','ceil') NOT NULL DEFAULT 'floor',
  work_rounding_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  work_rounding_mode ENUM('floor','half_up','ceil') NOT NULL DEFAULT 'floor',
  auto_close_enabled BOOLEAN NOT NULL DEFAULT TRUE,
  auto_close_time TIME NOT NULL DEFAULT '06:30:00',
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (store_id),
  CONSTRAINT fk_settings_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id),
  CONSTRAINT chk_settings_tax_rate CHECK (tax_rate >= 0),
  CONSTRAINT chk_settings_service_rate CHECK (service_rate >= 0),
  CONSTRAINT chk_settings_rounding CHECK (work_rounding_minutes IN (1,5,10,15,30,60))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  category_name VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_categories_name (store_id, category_name),
  UNIQUE KEY uq_product_categories_store_id (store_id, id),
  CONSTRAINT fk_product_categories_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  product_name VARCHAR(120) NOT NULL,
  selling_price DECIMAL(12,2) NOT NULL,
  back_rate DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
  back_type ENUM('percentage','fixed_per_unit','none') NOT NULL DEFAULT 'percentage',
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_store_id (store_id, id),
  KEY ix_products_store_category (store_id, category_id, is_active),
  CONSTRAINT fk_products_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_products_category FOREIGN KEY (store_id, category_id) REFERENCES product_categories(store_id, id),
  CONSTRAINT chk_products_price CHECK (selling_price >= 0),
  CONSTRAINT chk_products_back_rate CHECK (back_rate >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE set_menus (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  is_extension BOOLEAN NOT NULL DEFAULT FALSE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_set_menus_store_id (store_id, id),
  KEY ix_set_menus_store_active (store_id, is_active),
  CONSTRAINT fk_set_menus_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT chk_set_menus_duration CHECK (duration_minutes > 0),
  CONSTRAINT chk_set_menus_price CHECK (price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE casts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  cast_code VARCHAR(40) NOT NULL,
  cast_name VARCHAR(120) NOT NULL,
  hourly_wage DECIMAL(12,2) NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_casts_store_code (store_id, cast_code),
  UNIQUE KEY uq_casts_store_id (store_id, id),
  CONSTRAINT fk_casts_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT chk_casts_hourly_wage CHECK (hourly_wage >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD COLUMN cast_id BIGINT UNSIGNED NULL AFTER display_name,
  ADD UNIQUE KEY uq_users_store_id (store_id,id),
  ADD UNIQUE KEY uq_users_store_cast (store_id,cast_id),
  ADD CONSTRAINT fk_users_cast FOREIGN KEY (store_id,cast_id) REFERENCES casts(store_id,id);

CREATE TABLE cast_pay_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  cast_id BIGINT UNSIGNED NOT NULL,
  hourly_wage DECIMAL(12,2) NOT NULL,
  valid_from DATE NOT NULL,
  valid_to DATE NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_cast_pay_rules_start (cast_id, valid_from),
  KEY ix_cast_pay_rules_lookup (store_id, cast_id, valid_from, valid_to),
  CONSTRAINT fk_cast_pay_rules_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_cast_pay_rules_cast FOREIGN KEY (store_id, cast_id) REFERENCES casts(store_id, id),
  CONSTRAINT chk_cast_pay_rules_wage CHECK (hourly_wage >= 0),
  CONSTRAINT chk_cast_pay_rules_dates CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tables (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  table_name VARCHAR(40) NOT NULL,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 4,
  status ENUM('available','occupied','checkout','disabled') NOT NULL DEFAULT 'available',
  sort_order INT NOT NULL DEFAULT 0,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tables_store_name (store_id, table_name),
  UNIQUE KEY uq_tables_store_id (store_id, id),
  CONSTRAINT fk_tables_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  customer_name VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  notes TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_store_id (store_id, id),
  KEY ix_customers_store_phone (store_id, phone),
  CONSTRAINT fk_customers_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE table_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  table_id BIGINT UNSIGNED NOT NULL,
  business_date DATE NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  customer_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  started_at DATETIME(3) NOT NULL,
  ended_at DATETIME(3) NULL,
  status ENUM('open','checkout','closed','cancelled') NOT NULL DEFAULT 'open',
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(100) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_idempotency (store_id, idempotency_key),
  UNIQUE KEY uq_sessions_store_id (store_id, id),
  KEY ix_sessions_store_status (store_id, status, started_at),
  KEY ix_sessions_business_date (store_id,business_date,status),
  CONSTRAINT fk_sessions_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_sessions_table FOREIGN KEY (store_id, table_id) REFERENCES tables(store_id, id),
  CONSTRAINT fk_sessions_customer FOREIGN KEY (store_id, customer_id) REFERENCES customers(store_id, id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE session_casts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  cast_id BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME(3) NOT NULL,
  released_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY ix_session_casts_session (store_id, session_id),
  CONSTRAINT fk_session_casts_session FOREIGN KEY (store_id, session_id) REFERENCES table_sessions(store_id, id),
  CONSTRAINT fk_session_casts_cast FOREIGN KEY (store_id, cast_id) REFERENCES casts(store_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE session_set_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  set_menu_id BIGINT UNSIGNED NOT NULL,
  name_snapshot VARCHAR(120) NOT NULL,
  duration_minutes_snapshot SMALLINT UNSIGNED NOT NULL,
  price_snapshot DECIMAL(12,2) NOT NULL,
  started_at DATETIME(3) NOT NULL,
  ends_at DATETIME(3) NOT NULL,
  idempotency_key VARCHAR(100) NOT NULL,
  status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uq_session_set_items_idempotency (store_id,idempotency_key),
  KEY ix_session_set_items_session (store_id, session_id, status),
  CONSTRAINT fk_session_set_items_session FOREIGN KEY (store_id, session_id) REFERENCES table_sessions(store_id, id),
  CONSTRAINT fk_session_set_items_menu FOREIGN KEY (store_id, set_menu_id) REFERENCES set_menus(store_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE session_discounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  discount_type ENUM('percentage','fixed') NOT NULL,
  discount_value DECIMAL(12,2) NOT NULL,
  discount_amount DECIMAL(12,2) NOT NULL,
  reason VARCHAR(200) NOT NULL,
  idempotency_key VARCHAR(100) NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_session_discounts_idempotency (store_id,idempotency_key),
  KEY ix_session_discounts_session (store_id,session_id),
  CONSTRAINT fk_session_discounts_session FOREIGN KEY (store_id,session_id) REFERENCES table_sessions(store_id,id),
  CONSTRAINT fk_session_discounts_user FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT chk_session_discounts_value CHECK (discount_value > 0 AND discount_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  order_number VARCHAR(50) NOT NULL,
  entered_by_user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  ordered_at DATETIME(3) NOT NULL,
  idempotency_key VARCHAR(100) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_number (store_id, order_number),
  UNIQUE KEY uq_orders_idempotency (store_id, idempotency_key),
  UNIQUE KEY uq_orders_store_id (store_id, id),
  KEY ix_orders_session_time (store_id, session_id, ordered_at),
  CONSTRAINT fk_orders_session FOREIGN KEY (store_id, session_id) REFERENCES table_sessions(store_id, id),
  CONSTRAINT fk_orders_user FOREIGN KEY (entered_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  product_name_snapshot VARCHAR(120) NOT NULL,
  quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit_price_snapshot DECIMAL(12,2) NOT NULL,
  back_rate_snapshot DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
  back_type_snapshot ENUM('percentage','fixed_per_unit','none') NOT NULL DEFAULT 'percentage',
  back_amount_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  cancelled_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_items_store_id (store_id, id),
  KEY ix_order_items_order (store_id, order_id, status),
  CONSTRAINT fk_order_items_order FOREIGN KEY (store_id, order_id) REFERENCES orders(store_id, id),
  CONSTRAINT fk_order_items_product FOREIGN KEY (store_id, product_id) REFERENCES products(store_id, id),
  CONSTRAINT chk_order_items_quantity CHECK (quantity > 0),
  CONSTRAINT chk_order_items_price CHECK (unit_price_snapshot >= 0),
  CONSTRAINT chk_order_items_back_rate CHECK (back_rate_snapshot >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_item_back_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  cast_id BIGINT UNSIGNED NOT NULL,
  allocation_rate DECIMAL(7,4) NOT NULL,
  back_amount DECIMAL(12,2) NOT NULL,
  status ENUM('active','reversed') NOT NULL DEFAULT 'active',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_back_allocations_item (store_id, order_item_id, status),
  KEY ix_back_allocations_cast (store_id, cast_id, status),
  CONSTRAINT fk_back_allocations_item FOREIGN KEY (store_id, order_item_id) REFERENCES order_items(store_id, id),
  CONSTRAINT fk_back_allocations_cast FOREIGN KEY (store_id, cast_id) REFERENCES casts(store_id, id),
  CONSTRAINT chk_back_allocations_rate CHECK (allocation_rate >= 0 AND allocation_rate <= 100),
  CONSTRAINT chk_back_allocations_amount CHECK (back_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  payment_method ENUM('cash','credit_card','qr','electronic_money','transfer','other') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  status ENUM('completed','refunded','voided') NOT NULL DEFAULT 'completed',
  transaction_reference VARCHAR(120) NULL,
  idempotency_key VARCHAR(100) NOT NULL,
  paid_at DATETIME(3) NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_store_id (store_id,id),
  UNIQUE KEY uq_payments_idempotency (store_id, idempotency_key),
  KEY ix_payments_session (store_id, session_id, status),
  CONSTRAINT fk_payments_session FOREIGN KEY (store_id, session_id) REFERENCES table_sessions(store_id, id),
  CONSTRAINT fk_payments_user FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT chk_payments_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE session_charge_snapshots (
  store_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  items_subtotal DECIMAL(12,2) NOT NULL,
  sets_subtotal DECIMAL(12,2) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  service_amount DECIMAL(12,2) NOT NULL,
  tax_amount DECIMAL(12,2) NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  settings_snapshot JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (store_id, session_id),
  CONSTRAINT fk_charge_snapshot_session FOREIGN KEY (store_id, session_id) REFERENCES table_sessions(store_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_refunds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NOT NULL,
  business_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  reason VARCHAR(500) NOT NULL,
  refunded_at DATETIME(3) NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_refunds_idempotency (store_id,idempotency_key),
  KEY ix_payment_refunds_payment (store_id,payment_id),
  CONSTRAINT fk_payment_refunds_payment FOREIGN KEY (store_id,payment_id) REFERENCES payments(store_id,id),
  CONSTRAINT fk_payment_refunds_user FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT chk_payment_refunds_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  cast_id BIGINT UNSIGNED NOT NULL,
  business_date DATE NOT NULL,
  clock_in_at DATETIME(3) NOT NULL,
  clock_out_at DATETIME(3) NULL,
  break_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('working','completed','corrected') NOT NULL DEFAULT 'working',
  created_by_user_id BIGINT UNSIGNED NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_store_id (store_id, id),
  KEY ix_attendance_cast_date (store_id, cast_id, business_date, status),
  CONSTRAINT fk_attendance_cast FOREIGN KEY (store_id, cast_id) REFERENCES casts(store_id, id),
  CONSTRAINT fk_attendance_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_attendance_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payroll_details (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  cast_id BIGINT UNSIGNED NOT NULL,
  business_date DATE NOT NULL,
  work_minutes_raw INT UNSIGNED NOT NULL,
  work_minutes_payable INT UNSIGNED NOT NULL,
  rounding_minutes_snapshot SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  rounding_mode_snapshot ENUM('floor','half_up','ceil') NOT NULL DEFAULT 'floor',
  hourly_wage_snapshot DECIMAL(12,2) NOT NULL,
  hourly_pay DECIMAL(12,2) NOT NULL,
  back_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  adjustment DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  adjustment_reason VARCHAR(200) NULL,
  total_pay DECIMAL(12,2) NOT NULL,
  status ENUM('calculated','confirmed','paid','adjusted') NOT NULL DEFAULT 'calculated',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_payroll_cast_date (store_id, cast_id, business_date),
  UNIQUE KEY uq_payroll_store_id (store_id, id),
  CONSTRAINT fk_payroll_cast FOREIGN KEY (store_id, cast_id) REFERENCES casts(store_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payroll_back_details (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  payroll_detail_id BIGINT UNSIGNED NOT NULL,
  back_allocation_id BIGINT UNSIGNED NOT NULL,
  back_amount DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payroll_back_allocation (payroll_detail_id, back_allocation_id),
  CONSTRAINT fk_payroll_back_payroll FOREIGN KEY (store_id, payroll_detail_id) REFERENCES payroll_details(store_id, id),
  CONSTRAINT fk_payroll_back_allocation FOREIGN KEY (back_allocation_id) REFERENCES order_item_back_allocations(id),
  CONSTRAINT chk_payroll_back_amount CHECK (back_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  business_date DATE NOT NULL,
  category VARCHAR(80) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  description VARCHAR(500) NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_expenses_store_date (store_id, business_date),
  CONSTRAINT fk_expenses_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_expenses_user FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT chk_expenses_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE daily_closings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  business_date DATE NOT NULL,
  status ENUM('pending','processing','blocked','closed','reopened') NOT NULL DEFAULT 'pending',
  sales_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  service_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  sales_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  refund_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  cash_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  card_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  other_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payroll_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  expense_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  difference DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  settings_snapshot JSON NOT NULL,
  started_at DATETIME(3) NULL,
  closed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_daily_closings_store_date (store_id, business_date),
  UNIQUE KEY uq_daily_closings_store_id (store_id, id),
  CONSTRAINT fk_daily_closings_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE daily_closing_details (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  daily_closing_id BIGINT UNSIGNED NOT NULL,
  item_type VARCHAR(60) NOT NULL,
  reference_id BIGINT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  detail_json JSON NULL,
  PRIMARY KEY (id),
  KEY ix_closing_details_parent (store_id, daily_closing_id),
  CONSTRAINT fk_closing_details_parent FOREIGN KEY (store_id, daily_closing_id) REFERENCES daily_closings(store_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE closing_errors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  daily_closing_id BIGINT UNSIGNED NOT NULL,
  error_code VARCHAR(60) NOT NULL,
  description VARCHAR(500) NOT NULL,
  reference_type VARCHAR(60) NULL,
  reference_id BIGINT UNSIGNED NULL,
  resolved_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_closing_errors_open (store_id, daily_closing_id, resolved_at),
  CONSTRAINT fk_closing_errors_parent FOREIGN KEY (store_id, daily_closing_id) REFERENCES daily_closings(store_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  target_type VARCHAR(80) NOT NULL,
  target_id BIGINT UNSIGNED NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  request_id VARCHAR(100) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_audit_logs_store_time (store_id, created_at),
  KEY ix_audit_logs_target (store_id, target_type, target_id),
  CONSTRAINT fk_audit_logs_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed role definitions. User accounts and store data are created by the setup process.
INSERT INTO roles (role_code, role_name, permissions_json) VALUES
('owner', 'オーナー', JSON_OBJECT('all', true)),
('manager', '店長', JSON_OBJECT('pos', true, 'casts', true, 'payroll', true, 'closing', true, 'settings', true)),
('staff', 'スタッフ', JSON_OBJECT('tables', true, 'orders', true, 'checkout', true)),
('cast', 'キャスト', JSON_OBJECT('self_attendance', true, 'self_payroll', true));
