-- Runs once, as the container superuser, on first boot.
-- The application connects as `engage`: owner of its databases, but NOT superuser and NOT BYPASSRLS,
-- so FORCE ROW LEVEL SECURITY applies to it. Never run the app as a superuser.
CREATE ROLE engage LOGIN PASSWORD 'engage' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE DATABASE engage OWNER engage;
CREATE DATABASE engage_testing OWNER engage;
