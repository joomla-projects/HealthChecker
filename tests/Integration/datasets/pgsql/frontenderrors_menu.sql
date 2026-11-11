DROP TABLE IF EXISTS "#__menu";
CREATE TABLE IF NOT EXISTS "#__menu" (
  "id" serial NOT NULL,
  "menutype" varchar(24) NOT NULL,
  "title" varchar(255) NOT NULL,
  "alias" varchar(400) NOT NULL,
  "link" varchar(1024) NOT NULL,
  "type" varchar(16) NOT NULL,
  "published" smallint NOT NULL DEFAULT 0,
  "parent_id" integer NOT NULL DEFAULT 1,
  "level" integer NOT NULL DEFAULT 0,
  "access" bigint NOT NULL DEFAULT 0,
  "lft" bigint NOT NULL DEFAULT 0,
  "rgt" bigint NOT NULL DEFAULT 0,
  "home" smallint NOT NULL DEFAULT 0,
  "language" varchar(7) NOT NULL DEFAULT '',
  "client_id" smallint NOT NULL DEFAULT 0,
  "publish_up" timestamp without time zone DEFAULT NULL,
  "publish_down" timestamp without time zone DEFAULT NULL,
  PRIMARY KEY ("id")
);

CREATE INDEX "#__menu_idx_left_right" ON "#__menu" ("lft", "rgt");
