CREATE TABLE IF NOT EXISTS products (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  name_key TEXT NOT NULL UNIQUE,
  category TEXT NOT NULL CHECK (category IN ('Elektronika', 'Książki', 'Odzież')),
  price_cents INTEGER NOT NULL CHECK (
    (category = 'Elektronika' AND price_cents BETWEEN 5000 AND 5000000) OR
    (category = 'Książki' AND price_cents BETWEEN 500 AND 50000) OR
    (category = 'Odzież' AND price_cents BETWEEN 1000 AND 500000)
  ),
  quantity INTEGER NOT NULL CHECK (quantity >= 0)
) STRICT;

CREATE TABLE IF NOT EXISTS forbidden_phrases (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  phrase TEXT NOT NULL,
  phrase_key TEXT NOT NULL UNIQUE
) STRICT;

-- No foreign key to products: audit records must survive product deletion.
CREATE TABLE IF NOT EXISTS product_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id INTEGER NOT NULL,
  operation TEXT NOT NULL CHECK (operation IN ('CREATE', 'UPDATE', 'DELETE')),
  changed_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
  before_json TEXT,
  after_json TEXT
) STRICT;
CREATE INDEX IF NOT EXISTS ix_history_product ON product_history(product_id, id);

-- Triggers make audit writes atomic with the data change, even below the service layer.
CREATE TRIGGER IF NOT EXISTS products_insert_history AFTER INSERT ON products
BEGIN
  INSERT INTO product_history(product_id, operation, after_json)
  VALUES (NEW.id, 'CREATE', json_object('id', NEW.id, 'name', NEW.name,
    'category', NEW.category, 'price', NEW.price_cents / 100.0, 'quantity', NEW.quantity));
END;

CREATE TRIGGER IF NOT EXISTS products_update_history AFTER UPDATE ON products
WHEN OLD.name IS NOT NEW.name OR OLD.category IS NOT NEW.category
  OR OLD.price_cents IS NOT NEW.price_cents OR OLD.quantity IS NOT NEW.quantity
BEGIN
  INSERT INTO product_history(product_id, operation, before_json, after_json)
  VALUES (NEW.id, 'UPDATE',
    json_object('id', OLD.id, 'name', OLD.name, 'category', OLD.category,
      'price', OLD.price_cents / 100.0, 'quantity', OLD.quantity),
    json_object('id', NEW.id, 'name', NEW.name, 'category', NEW.category,
      'price', NEW.price_cents / 100.0, 'quantity', NEW.quantity));
END;

CREATE TRIGGER IF NOT EXISTS products_delete_history AFTER DELETE ON products
BEGIN
  INSERT INTO product_history(product_id, operation, before_json)
  VALUES (OLD.id, 'DELETE', json_object('id', OLD.id, 'name', OLD.name,
    'category', OLD.category, 'price', OLD.price_cents / 100.0, 'quantity', OLD.quantity));
END;
