<?php
/**
 * RelDyn tools: a SELECT-only adapter over a read-only PostgreSQL session, for tools that read
 * the live CHIM database (trait_read_seed.php, trait_read_report.php). It never writes.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** SELECT-only adapter over a read-only pg session (the CHIM sql method names the connector code uses). */
final class RelDynReadOnlyPg
{
    private $link;

    public function __construct(string $dsn)
    {
        $this->link = @pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException('cannot connect to the live database (RELDYN_LIVE_PG_DSN)');
        pg_query($this->link, 'SET default_transaction_read_only = on');
        pg_query($this->link, "SET statement_timeout = '30s'");
    }

    private function guard(string $q): void
    {
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $q) || preg_match('/\b(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE|GRANT)\b/i', preg_replace("/'[^']*'/", "''", $q))) {
            throw new RuntimeException('read-only adapter refused a non-SELECT statement');
        }
    }

    public function fetchOne($q, array $params = [])
    {
        $this->guard($q);
        $res = $params ? pg_query_params($this->link, $q, $params) : pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('query failed: ' . pg_last_error($this->link));
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $this->guard($q);
        $res = pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('query failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function escape($s) { return pg_escape_string($this->link, (string) $s); }
    public function execQuery($q) { $this->guard($q); return pg_query($this->link, $q); }
    public function insert($t, $d) { throw new RuntimeException('read-only adapter: insert refused'); }
    public function insertReturningId($t, $d, $c = 'id') { throw new RuntimeException('read-only adapter: insert refused'); }
    public function updateRow($t, $d, $w) { throw new RuntimeException('read-only adapter: update refused'); }
}
