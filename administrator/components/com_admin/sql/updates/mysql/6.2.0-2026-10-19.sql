CREATE TABLE IF NOT EXISTS `#__healthcheck_database_ignored` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(10) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_kind_name` (`kind`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

INSERT INTO `#__extensions` (`package_id`, `name`, `type`, `element`, `folder`, `client_id`, `enabled`, `access`, `protected`, `locked`, `manifest_cache`, `params`, `custom_data`, `ordering`, `state`)
SELECT 0, 'plg_healthcheck_database', 'plugin', 'database', 'healthcheck', 0, 0, 1, 0, 1, '', '{"context":"general"}', '', 2, 0
 WHERE NOT EXISTS (SELECT * FROM `#__extensions` g WHERE g.`element` = 'database' AND g.`folder` = 'healthcheck');
