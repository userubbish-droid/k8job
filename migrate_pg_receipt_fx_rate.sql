-- PG Telegram 回执：固定汇率（应下发 = 今日入款合计 × receipt_fx_rate）
ALTER TABLE telegram_quick_txn_config_pg
    ADD COLUMN receipt_fx_rate DECIMAL(14,6) NOT NULL DEFAULT 1.000000 AFTER undo_window_sec;
