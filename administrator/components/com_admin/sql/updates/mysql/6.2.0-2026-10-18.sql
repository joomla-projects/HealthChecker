CREATE TABLE IF NOT EXISTS `#__frontenderrors` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `fingerprint` char(40) NOT NULL,
  `type` varchar(10) NOT NULL,
  `message` varchar(500) NOT NULL DEFAULT '',
  `file` varchar(255) NOT NULL DEFAULT '',
  `line` int unsigned NOT NULL DEFAULT 0,
  `col` int unsigned NOT NULL DEFAULT 0,
  `stack` text NOT NULL,
  `source_extension` varchar(100) DEFAULT NULL,
  `first_seen` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  `state` tinyint NOT NULL DEFAULT 0,
  `state_changed` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_fingerprint` (`fingerprint`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__frontenderrors_hits` (
  `error_id` int unsigned NOT NULL,
  `page` varchar(255) NOT NULL,
  `page_hash` char(40) NOT NULL,
  `day` date NOT NULL,
  `origin` varchar(7) NOT NULL,
  `hits` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`error_id`, `page_hash`, `day`, `origin`),
  KEY `idx_day` (`day`),
  KEY `idx_page_hash` (`page_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

INSERT INTO `#__extensions` (`package_id`, `name`, `type`, `element`, `folder`, `client_id`, `enabled`, `access`, `protected`, `locked`, `manifest_cache`, `params`, `custom_data`, `ordering`, `state`)
SELECT 0, 'plg_healthcheck_frontenderrors', 'plugin', 'frontenderrors', 'healthcheck', 0, 0, 1, 0, 1, '', '{"context":"general"}', '', 0, 0
 WHERE NOT EXISTS (SELECT * FROM `#__extensions` g WHERE g.`element` = 'frontenderrors' AND g.`folder` = 'healthcheck');

INSERT INTO `#__extensions` (`package_id`, `name`, `type`, `element`, `folder`, `client_id`, `enabled`, `access`, `protected`, `locked`, `manifest_cache`, `params`, `custom_data`, `ordering`, `state`)
SELECT 0, 'plg_system_frontenderrors', 'plugin', 'frontenderrors', 'system', 0, 0, 1, 0, 1, '', '', '', 13, 0
 WHERE NOT EXISTS (SELECT * FROM `#__extensions` g WHERE g.`element` = 'frontenderrors' AND g.`folder` = 'system');
