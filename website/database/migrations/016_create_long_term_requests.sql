CREATE TABLE IF NOT EXISTS long_term_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL,
    location_id INTEGER NOT NULL,
    fulfillment_type TEXT NOT NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    day_count INTEGER NOT NULL,
    customer_notes TEXT,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'contacted', 'quoted', 'converted', 'declined', 'cancelled')),
    admin_notes TEXT,
    booking_id INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (booking_id) REFERENCES bookings(id)
);

CREATE INDEX IF NOT EXISTS idx_long_term_requests_status ON long_term_requests (status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_long_term_requests_customer ON long_term_requests (customer_id, created_at DESC);
