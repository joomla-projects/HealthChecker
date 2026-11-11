INSERT INTO "#__extensions" ("package_id", "name", "type", "element", "folder", "client_id", "enabled", "access", "protected", "locked", "manifest_cache", "params", "custom_data", "ordering", "state")
SELECT 0, 'plg_healthcheck_siteinfo', 'plugin', 'siteinfo', 'healthcheck', 0, 0, 1, 0, 1, '', '{"context":"general"}', '', 0, 0
WHERE NOT EXISTS (SELECT * FROM "#__extensions" g WHERE g."element" = 'siteinfo' AND g."folder" = 'healthcheck');
