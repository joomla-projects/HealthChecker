DROP TABLE IF EXISTS "#__healthcheck_database_ignored";
CREATE TABLE IF NOT EXISTS "#__healthcheck_database_ignored" (
  "id" serial NOT NULL,
  "kind" varchar(10) NOT NULL,
  "name" varchar(255) NOT NULL,
  "created_by" bigint NOT NULL DEFAULT 0,
  "created" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id"),
  CONSTRAINT "#__healthcheck_database_ignored_idx_kind_name" UNIQUE ("kind", "name")
);
