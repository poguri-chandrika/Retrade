-- Add category_id to products and backfill from existing category names
ALTER TABLE products ADD COLUMN category_id INT NULL AFTER category;

-- Create missing categories from free-text names
INSERT INTO categories (name)
SELECT DISTINCT p.category
FROM products p
LEFT JOIN categories c ON LOWER(c.name) = LOWER(p.category)
WHERE p.category IS NOT NULL AND p.category <> '' AND c.id IS NULL;

-- Backfill category_id by matching names (case-insensitive)
UPDATE products p
JOIN categories c ON LOWER(c.name) = LOWER(p.category)
SET p.category_id = c.id
WHERE p.category_id IS NULL AND p.category IS NOT NULL AND p.category <> '';

-- Add FK (set null on delete)
ALTER TABLE products
  ADD CONSTRAINT fk_products_category
  FOREIGN KEY (category_id) REFERENCES categories(id)
  ON DELETE SET NULL;
