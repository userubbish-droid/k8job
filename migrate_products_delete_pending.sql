-- 产品删除审核：禁用后可申请删除，Boss 批准后从 products 移除；待审期间业务下拉不显示该产品
ALTER TABLE products ADD COLUMN delete_pending_at DATETIME NULL DEFAULT NULL;
ALTER TABLE products ADD COLUMN delete_pending_by INT UNSIGNED NULL DEFAULT NULL;
