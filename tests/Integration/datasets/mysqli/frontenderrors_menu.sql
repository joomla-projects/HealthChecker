DROP TABLE IF EXISTS `#__menu`;
CREATE TABLE IF NOT EXISTS `#__menu` (
  `id` int NOT NULL AUTO_INCREMENT,
  `menutype` varchar(24) NOT NULL,
  `title` varchar(255) NOT NULL,
  `alias` varchar(400) NOT NULL,
  `link` varchar(1024) NOT NULL,
  `type` varchar(16) NOT NULL,
  `published` tinyint NOT NULL DEFAULT 0,
  `parent_id` int unsigned NOT NULL DEFAULT 1,
  `level` int unsigned NOT NULL DEFAULT 0,
  `access` int unsigned NOT NULL DEFAULT 0,
  `lft` int NOT NULL DEFAULT 0,
  `rgt` int NOT NULL DEFAULT 0,
  `home` tinyint unsigned NOT NULL DEFAULT 0,
  `language` varchar(7) NOT NULL DEFAULT '',
  `client_id` tinyint NOT NULL DEFAULT 0,
  `publish_up` datetime DEFAULT NULL,
  `publish_down` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_left_right` (`lft`, `rgt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
