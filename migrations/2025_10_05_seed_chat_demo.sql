-- Seed a demo negotiation and messages if none exist
-- Picks the most recent approved product and pairs its seller with the first non-seller user as buyer

INSERT INTO negotiations (product_id, seller_id, buyer_id)
SELECT p.id, p.seller_id, u.id
FROM products p
JOIN users u ON u.id <> p.seller_id AND u.role <> 'admin'
WHERE p.status = 'approved'
ORDER BY p.created_at DESC
LIMIT 1
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- Insert a couple of messages into the latest negotiation if it has no messages yet
INSERT INTO messages (negotiation_id, sender_id, message)
SELECT n.id, n.buyer_id, CONCAT('Hi, is the ', p.title, ' still available?')
FROM negotiations n
JOIN products p ON p.id = n.product_id
LEFT JOIN messages m ON m.negotiation_id = n.id
WHERE m.id IS NULL
ORDER BY n.id DESC
LIMIT 1;

INSERT INTO messages (negotiation_id, sender_id, message)
SELECT n.id, n.seller_id, 'Yes, it is available. When would you like to proceed?'
FROM negotiations n
LEFT JOIN messages m ON m.negotiation_id = n.id
WHERE m.id IS NULL
ORDER BY n.id DESC
LIMIT 1;
