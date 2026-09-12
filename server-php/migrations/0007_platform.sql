-- ---------------------------------------------------------------------------
-- 0007 — infrastructure this API needs for itself.
--
-- None of it is user content: a short-lived cache of portal session lookups, a
-- rate-limit counter, and the idempotency ledger the offline sync queue needs.
-- ---------------------------------------------------------------------------

-- @UP

-- Validating a ses_key means a round trip to my.aicountly.com. Doing that on
-- every API call would put the portal in the critical path of every keystroke's
-- autosave, so a successful lookup is cached for a short window.
--
-- The key is stored as a SHA-256 hash: this table is not a place from which a
-- live session key should be readable.
CREATE TABLE api_sessions (
    ses_key_hash    varchar(64)   PRIMARY KEY,
    user_id         varchar(64)   NOT NULL,
    tenant_id       varchar(64)   NULL,
    -- Non-sensitive profile fields the portal returned (name, email), used to
    -- render an avatar without a second call.
    profile         jsonb         NOT NULL DEFAULT '{}'::jsonb,
    expires_at      timestamptz   NOT NULL,
    created_at      timestamptz   NOT NULL DEFAULT now()
);

CREATE INDEX api_sessions_expiry_idx ON api_sessions (expires_at);


CREATE TABLE api_rate_limits (
    bucket_key      varchar(200)  PRIMARY KEY,
    hits            integer       NOT NULL DEFAULT 0,
    window_start    timestamptz   NOT NULL DEFAULT now(),
    expires_at      timestamptz   NOT NULL
);

CREATE INDEX api_rate_limits_expiry_idx ON api_rate_limits (expires_at);


-- Offline sync. The client stamps every queued mutation with a UUID it
-- generates locally; replaying the queue after a flaky reconnect therefore
-- cannot apply the same create twice.
CREATE TABLE sync_operations (
    operation_id    uuid          PRIMARY KEY,
    user_id         varchar(64)   NOT NULL,
    entity_type     varchar(40)   NOT NULL,
    entity_id       uuid          NULL,
    operation       varchar(30)   NOT NULL,
    status          varchar(20)   NOT NULL DEFAULT 'applied',
    -- The response the first application produced, replayed verbatim to a
    -- duplicate so the client converges on one answer.
    result          jsonb         NULL,
    client_stamp    timestamptz   NULL,
    created_at      timestamptz   NOT NULL DEFAULT now(),

    CONSTRAINT sync_operations_status_chk CHECK (status IN ('applied', 'conflict', 'rejected'))
);

CREATE INDEX sync_operations_user_idx ON sync_operations (user_id, created_at DESC);
CREATE INDEX sync_operations_gc_idx   ON sync_operations (created_at);

-- @DOWN

DROP TABLE IF EXISTS sync_operations;
DROP TABLE IF EXISTS api_rate_limits;
DROP TABLE IF EXISTS api_sessions;
