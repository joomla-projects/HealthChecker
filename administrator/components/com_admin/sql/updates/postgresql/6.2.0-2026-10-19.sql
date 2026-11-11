CREATE TABLE IF NOT EXISTS "#__healthcheck_database_ignored" (
  "id" serial NOT NULL,
  "kind" varchar(10) NOT NULL,
  "name" varchar(255) NOT NULL,
  "created_by" bigint NOT NULL DEFAULT 0,
  "created" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id"),
  CONSTRAINT "#__healthcheck_database_ignored_idx_kind_name" UNIQUE ("kind", "name")
);

INSERT INTO "#__extensions" ("package_id", "name", "type", "element", "folder", "client_id", "enabled", "access", "protected", "locked", "manifest_cache", "params", "custom_data", "ordering", "state")
SELECT 0, 'plg_healthcheck_database', 'plugin', 'database', 'healthcheck', 0, 0, 1, 0, 1, '', '{"context":"general"}', '', 2, 0
 WHERE NOT EXISTS (SELECT * FROM "#__extensions" g WHERE g."element" = 'database' AND g."folder" = 'healthcheck');
