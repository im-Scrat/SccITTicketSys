-- Runs once on first initialization of an empty data volume.
-- Provisions the dedicated test database used by the backend test suite
-- (backend/phpunit.xml), with pgvector enabled for parity with prod.
CREATE DATABASE school_it_service_management_test;
\connect school_it_service_management_test
CREATE EXTENSION IF NOT EXISTS vector;
