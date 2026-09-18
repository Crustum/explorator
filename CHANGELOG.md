# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


## [1.0.0]

### Added

- Search engines: Algolia, Meilisearch, Typesense, Turbopuffer, database, collection, and null drivers
- Semantic and hybrid search via `Builder::semantic()` / `Builder::hybrid()`, with optional minimum similarity threshold
- Turbopuffer engine with full-text, semantic, and hybrid search, including native embeddings support
- Database vector search (semantic/hybrid) on PostgreSQL with the pgvector extension
- Meilisearch semantic and hybrid search with per-table embedding settings
- Typesense semantic and hybrid search, including document embedding generation during indexing and native embeddings support
- Native embeddings drivers for Meilisearch, Typesense, and Turbopuffer engines
