INSERT INTO "#__extensions" ("package_id", "name", "type", "element", "folder", "client_id", "enabled", "access", "protected", "locked", "manifest_cache", "params", "custom_data", "ordering", "state")
SELECT 0, 'plg_healthcheck_securityheaders', 'plugin', 'securityheaders', 'healthcheck', 0, 0, 1, 0, 1, '', '{"context":"security"}', '', 0, 0
WHERE NOT EXISTS (SELECT * FROM "#__extensions" g WHERE g."element" = 'securityheaders' AND g."folder" = 'healthcheck');
