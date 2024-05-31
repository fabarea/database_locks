CREATE TABLE `tx_flock_lock` (
		id INT AUTO_INCREMENT PRIMARY KEY,
		name VARCHAR(255) NOT NULL,
		value VARCHAR(255) NOT NULL,
		ttl int(10) UNSIGNED NOT NULL DEFAULT 0,
		UNIQUE INDEX unique_name_value (name, value)
) ENGINE = MEMORY;
