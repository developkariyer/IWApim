INSERT INTO iwa_marketplace_orders (marketplace_id, order_id, json) VALUES (:marketplace_id, :order_id, :json) ON DUPLICATE KEY UPDATE json = VALUES(json)