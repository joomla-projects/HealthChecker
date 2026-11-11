DROP TABLE IF EXISTS "#__frontenderrors_hits";
DROP TABLE IF EXISTS "#__frontenderrors";
CREATE TABLE IF NOT EXISTS "#__frontenderrors" (
  "id" serial NOT NULL,
  "fingerprint" char(40) NOT NULL,
  "type" varchar(10) NOT NULL,
  "message" varchar(500) NOT NULL DEFAULT '',
  "file" varchar(255) NOT NULL DEFAULT '',
  "line" bigint NOT NULL DEFAULT 0,
  "col" bigint NOT NULL DEFAULT 0,
  "stack" text NOT NULL,
  "source_extension" varchar(100) DEFAULT NULL,
  "first_seen" timestamp without time zone NOT NULL,
  "last_seen" timestamp without time zone NOT NULL,
  "state" smallint NOT NULL DEFAULT 0,
  "state_changed" timestamp without time zone DEFAULT NULL,
  PRIMARY KEY ("id"),
  CONSTRAINT "#__frontenderrors_idx_fingerprint" UNIQUE ("fingerprint")
);

CREATE INDEX "#__frontenderrors_idx_state" ON "#__frontenderrors" ("state");

CREATE TABLE IF NOT EXISTS "#__frontenderrors_hits" (
  "error_id" integer NOT NULL,
  "page" varchar(255) NOT NULL,
  "page_hash" char(40) NOT NULL,
  "day" date NOT NULL,
  "origin" varchar(7) NOT NULL,
  "hits" bigint NOT NULL DEFAULT 0,
  PRIMARY KEY ("error_id", "page_hash", "day", "origin")
);

CREATE INDEX "#__frontenderrors_hits_idx_day" ON "#__frontenderrors_hits" ("day");

CREATE INDEX "#__frontenderrors_hits_idx_page_hash" ON "#__frontenderrors_hits" ("page_hash");
