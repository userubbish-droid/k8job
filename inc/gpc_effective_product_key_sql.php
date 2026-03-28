<?php
/**
 * 片段：按 transactions 别名 t 计算 Game Platform 分组键（用于 GROUP BY）。
 * 有 product 用 product；否则用客户 code 对应 customer_product_accounts 中 id 最小的一条产品名。
 */
return "LOWER(TRIM(COALESCE(
  NULLIF(TRIM(COALESCE(t.product, '')), ''),
  (SELECT TRIM(a.product_name) FROM customer_product_accounts a
   INNER JOIN customers cu ON cu.id = a.customer_id AND cu.company_id = a.company_id
   WHERE a.company_id = t.company_id AND t.code IS NOT NULL AND TRIM(COALESCE(t.code, '')) <> ''
     AND cu.code = t.code
   ORDER BY a.id ASC
   LIMIT 1),
  ''
)))";
