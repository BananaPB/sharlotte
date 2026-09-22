-- Postgres only auto-creates the database named by POSTGRES_DB (see docker-compose.yml)
-- on first container start. This project needs a second, separate database for the Pest
-- suite (DB_DATABASE=testing in phpunit.xml, kept isolated from the dev dataset so tests
-- can freely migrate/rollback without touching real ingredient data — docs/decisions.md
-- entry 5). Anything placed in /docker-entrypoint-initdb.d/ runs once, automatically, the
-- first time the data volume is initialized.
CREATE DATABASE testing;
