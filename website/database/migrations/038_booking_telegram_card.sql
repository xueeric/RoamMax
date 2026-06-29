CREATE TABLE IF NOT EXISTS booking_telegram_messages (
    booking_id INTEGER PRIMARY KEY,
    chat_id TEXT NOT NULL,
    message_id INTEGER NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_booking_telegram_messages_updated
    ON booking_telegram_messages (updated_at);
