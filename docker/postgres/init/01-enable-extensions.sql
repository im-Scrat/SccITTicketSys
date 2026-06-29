-- Runs once, on first initialization of an empty data volume, against
-- the POSTGRES_DB database. Enables pgvector so the future AI/RAG
-- (Retrieval-Augmented Generation) layer can store embeddings without
-- requiring a container swap or schema-restructuring migration later.
CREATE EXTENSION IF NOT EXISTS vector;
