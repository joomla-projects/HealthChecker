DROP TABLE IF EXISTS `#__healthcheck_database_ignored`;
CREATE TABLE IF NOT EXISTS `#__healthcheck_database_ignored` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(10) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_kind_name` (`kind`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
