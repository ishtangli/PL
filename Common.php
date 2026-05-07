<?php
declare(strict_types=1);

/*
 * common.php
 * Safe Oracle access and data retrieval for Open TO and Picklists.
 *
 * - Returns structured arrays (not HTML) so the UI can render clean markup.
 * - Uses bind variables and proper resource cleanup.
 * - Requires OCI8 extension.
 */

/** Configuration */
const DB_USER = 'mm';
const DB_PASS = 'mm123';
const DB_CONN = 'reports';
const CREATED_DATE_THRESHOLD = '2026-01-01'; // ISO date used as bind value
const DEFAULT_FETCH_MODE = OCI_ASSOC;

/** Priority mapping (fallback) */
const PRIORITY_TARGET_HOURS = [
    'AOG' => 4,
    'WSP' => 8,
];

/**
 * Connect to Oracle DB and return connection resource.
 *
 * @return resource
 * @throws RuntimeException
 */
function connect_db()
{
    $conn = @oci_connect(DB_USER, DB_PASS, DB_CONN);
    if ($conn === false) {
        $err = oci_error();
        throw new RuntimeException('Database connection failed: ' . ($err['message'] ?? 'unknown'));
    }
    return $conn;
}

/**
 * Close Oracle connection.
 *
 * @param resource|null $conn
 * @return void
 */
function close_db(&$conn): void
{
    if ($conn) {
        @oci_close($conn);
        $conn = null;
    }
}

/**
 * Execute a query with optional binds and return statement resource.
 * Caller must free statement with oci_free_statement.
 *
 * This implementation:
 *  - Normalizes incoming binds (with or without leading colon).
 *  - Binds only variables that actually appear in the SQL text.
 *  - Validates bind names to be legal Oracle identifiers before binding.
 *  - Uses explicit bind types (string/number) to avoid ORA-00932.
 *
 * @param resource $conn
 * @param string $sql
 * @param array $binds  associative array ':name' => value or 'name' => value
 * @return resource
 * @throws RuntimeException
 */
function execute_query($conn, string $sql, array $binds = [])
{
    $stid = oci_parse($conn, $sql);
    if ($stid === false) {
        $err = oci_error();
        throw new RuntimeException('Failed to parse SQL: ' . ($err['message'] ?? 'unknown'));
    }

    // Ensure SQLT_CHR and SQLT_INT constants exist for explicit binding
    if (!defined('SQLT_CHR')) {
        define('SQLT_CHR', 1);
    }
    if (!defined('SQLT_INT')) {
        define('SQLT_INT', 3);
    }

    // Normalize incoming binds: allow keys with or without leading colon
    $normalizedBinds = [];
    foreach ($binds as $k => $v) {
        $key = ltrim((string)$k, ':');
        $normalizedBinds[$key] = $v;
    }

    // Keep a local array of bind variables so references remain valid until execute
    $bindVars = [];

    // Bind only those normalized keys that actually appear in the SQL as ":key"
    foreach ($normalizedBinds as $bindName => $value) {
        // Validate bind name: must start with a letter and contain only letters, digits, underscore
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $bindName)) {
            // Skip invalid bind names to avoid ORA-01036
            continue;
        }

        // Only bind if the SQL contains the placeholder (case-sensitive with colon)
        if (strpos($sql, ':' . $bindName) === false) {
            continue;
        }

        // Determine type and prepare local variable for binding (by reference)
        if ($value === null) {
            $bindVars[$bindName] = '';
            $type = SQLT_CHR;
        } elseif (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) {
            $bindVars[$bindName] = (int)$value;
            $type = SQLT_INT;
        } elseif (is_float($value) || (is_string($value) && preg_match('/^-?\d+\.\d+$/', $value))) {
            $bindVars[$bindName] = (float)$value;
            $type = SQLT_INT;
        } else {
            $bindVars[$bindName] = (string)$value;
            $type = SQLT_CHR;
        }

        if (!oci_bind_by_name($stid, ':' . $bindName, $bindVars[$bindName], -1, $type)) {
            $err = oci_error($stid);
            throw new RuntimeException('Failed to bind ' . $bindName . ': ' . ($err['message'] ?? 'unknown'));
        }
    }

    $ok = oci_execute($stid, OCI_DEFAULT);
    if ($ok === false) {
        $err = oci_error($stid);
        throw new RuntimeException('Failed to execute SQL: ' . ($err['message'] ?? 'unknown'));
    }

    return $stid;
}

/**
 * Build an IN clause with bind variables and return clause string and binds.
 *
 * This function returns bind keys with leading colon (':b0', ':b1', ...).
 *
 * @param string $field
 * @param array $values
 * @param string $prefix
 * @return array [string $clause, array $binds]
 */
function build_in_clause(string $field, array $values, string $prefix = 'b'): array
{
    $values = array_values(array_filter($values, fn($v) => $v !== '' && $v !== null));
    if (count($values) === 0) {
        return ['', []];
    }
    $binds = [];
    $placeholders = [];
    foreach ($values as $i => $val) {
        $key = ':' . $prefix . $i;
        $placeholders[] = $key;
        $binds[$key] = $val;
    }
    $clause = sprintf('AND %s IN (%s)', $field, implode(',', $placeholders));
    return [$clause, $binds];
}

/**
 * Map LOCATION numeric code to array of location codes.
 *
 * CHANGED: code 6 => ['AUTOMATED','RAWMAT'] (AUTOMATED + RAWMAT)
 *
 * @param int|string $location
 * @return array
 */
function get_location_codes($location): array
{
    $map = [
        1 => ['COMPOWH', 'COMPOWH-Q'],
        2 => ['AUTOMATED'],
        3 => ['MSU'],
        4 => ['INFLAM'],
        5 => ['RAWMAT'],
        6 => ['AUTOMATED', 'RAWMAT'], // combined AUTOMATED + RAWMAT group
        10 => ['AUTOMATED', 'MSU', 'INFLAM', 'RAWMAT'],
    ];

    $key = is_numeric($location) ? (int)$location : 0;
    return $map[$key] ?? [];
}

/**
 * Fetch summary counts grouped by PRIORITY and DIV_GROUP for picklists.
 *
 * Returns:
 *  [
 *    'PRIORITY' => ['AOG'=>int,'WSP'=>int,'OTHERS'=>int],
 *    'DIVISION' => ['MA-LINE'=>int,'MA-BASE'=>int,'AO'=>int,'OTHER'=>int]
 *  ]
 *
 * @param int|string $LOCATION
 * @return array
 */
function fetch_picklist_summary($LOCATION = 0): array
{
    $conn = null;
    $stid = null;
    try {
        $conn = connect_db();

        // Use LOCATION for picklists filtering
        $locCodes = get_location_codes($LOCATION);
        [$locClause, $locBinds] = build_in_clause('LOCATION', $locCodes, 'loc');

        // Base subquery to get distinct picklists matching filters
        $subSql = "
            SELECT PLH.PICKLIST
            FROM PICKLIST_HEADER PLH
            INNER JOIN PICKLIST_DISTRIBUTION PLD ON PLH.PICKLIST = PLD.PICKLIST
            WHERE PLH.PRIORITY IS NOT NULL
              AND PLH.CREATED_DATE >= TO_DATE(:created_threshold, 'YYYY-MM-DD')
              AND PLH.STATUS = 'OPEN'
              {$locClause}
            GROUP BY PLH.PICKLIST
        ";

        // Priority counts
        $sqlPriority = "
            SELECT PRIORITY, COUNT(*) AS OPENCOUNT
            FROM (
                SELECT ph.PRIORITY
                FROM ({$subSql}) PL_LIST
                LEFT JOIN PICKLIST_HEADER ph ON ph.PICKLIST = PL_LIST.PICKLIST
            )
            GROUP BY PRIORITY
        ";

        // Normalize binds: build_in_clause returns keys with leading colon; convert to no-colon keys for execute_query
        $binds = [];
        foreach (array_merge([':created_threshold' => CREATED_DATE_THRESHOLD], $locBinds) as $k => $v) {
            $binds[ltrim((string)$k, ':')] = $v;
        }

        $stid = execute_query($conn, $sqlPriority, $binds);

        $priorityCounts = ['AOG' => 0, 'WSP' => 0, 'OTHERS' => 0];
        while (($row = oci_fetch_array($stid, DEFAULT_FETCH_MODE)) !== false) {
            $priority = $row['PRIORITY'] ?? '';
            $count = (int)($row['OPENCOUNT'] ?? 0);
            if ($priority === 'AOG') {
                $priorityCounts['AOG'] = $count;
            } elseif ($priority === 'WSP') {
                $priorityCounts['WSP'] = $count;
            } else {
                $priorityCounts['OTHERS'] += $count;
            }
        }
        oci_free_statement($stid);
        $stid = null;

        // Division grouping counts: join RELATION_MASTER to get DIVISION and map to DIV_GROUP
        $sqlDivision = "
            SELECT DIV_GROUP, COUNT(*) AS CNT
            FROM (
                SELECT
                  CASE
                    WHEN SUBSTR(NVL(rm.DIVISION,''),1,3) = 'MA1' THEN 'MA-LINE'
                    WHEN SUBSTR(NVL(rm.DIVISION,''),1,3) = 'MA5' THEN 'MA-BASE'
                    WHEN SUBSTR(NVL(rm.DIVISION,''),1,2) = 'AO'  THEN 'AO'
                    ELSE 'OTHER'
                  END AS DIV_GROUP
                FROM ({$subSql}) PL_LIST
                LEFT JOIN PICKLIST_HEADER ph ON ph.PICKLIST = PL_LIST.PICKLIST
                LEFT JOIN RELATION_MASTER rm ON ph.CREATED_BY = rm.RELATION_CODE
            )
            GROUP BY DIV_GROUP
        ";

        $stid = execute_query($conn, $sqlDivision, $binds);

        $divCounts = ['MA-LINE' => 0, 'MA-BASE' => 0, 'AO' => 0, 'OTHER' => 0];
        while (($row = oci_fetch_array($stid, DEFAULT_FETCH_MODE)) !== false) {
            $dg = $row['DIV_GROUP'] ?? 'OTHER';
            $cnt = (int)($row['CNT'] ?? 0);
            if (!array_key_exists($dg, $divCounts)) $dg = 'OTHER';
            $divCounts[$dg] = $cnt;
        }

        oci_free_statement($stid);
        $stid = null;
        close_db($conn);
        $conn = null;

        return [
            'PRIORITY' => $priorityCounts,
            'DIVISION' => $divCounts,
        ];
    } catch (Throwable $e) {
        if ($stid) @oci_free_statement($stid);
        if ($conn) close_db($conn);
        error_log('fetch_picklist_summary error: ' . $e->getMessage());
        return [
            'PRIORITY' => ['AOG' => 0, 'WSP' => 0, 'OTHERS' => 0],
            'DIVISION' => ['MA-LINE' => 0, 'MA-BASE' => 0, 'AO' => 0, 'OTHER' => 0],
        ];
    }
}

/**
 * Fetch detailed open picklist rows as associative arrays.
 *
 * AO target logic:
 *  - If TRUNC(CREATED_DATE) = TRUNC(REQUIRE_ON) then TARGET_HOURS = 1
 *  - Else TARGET_HOURS = (TRUNC(REQUIRE_ON) + 1 - ph.CREATED_DATE) * 24
 * TARGET_DATE:
 *  - If same-day: CREATED_DATE + 1 hour
 *  - Else: TRUNC(REQUIRE_ON) + 1 (end of REQUIRE_ON day)
 *
 * OTHER logic (updated):
 *  - TARGET_HOURS = (TRUNC(REQUIRE_ON) + 1 - ph.CREATED_DATE) * 24  (end of REQUIRE_ON day)
 *  - TARGET_DATE = TRUNC(REQUIRE_ON) + 1
 *  - REMAINING_HOURS = TARGET_HOURS - RUNNING_HOURS
 *
 * MA1/MA5 continue to use configured priority-based hours.
 *
 * @param int|string $LOCATION
 * @return array
 */
function fetch_open_picklist_rows($LOCATION = 0): array
{
    $conn = null;
    $stid = null;
    try {
        $conn = connect_db();

        // Use LOCATION for picklists filtering
        $locCodes = get_location_codes($LOCATION);
        [$locClause, $locBinds] = build_in_clause('LOCATION', $locCodes, 'loc');

        $sql = "
            SELECT
                TO_CHAR(ph.CREATED_DATE, 'YYYY-MM-DD HH24:MI:SS') AS PICK_DATE,
                ROUND((SYSDATE - ph.CREATED_DATE) * 24) AS RUNNING_HOURS,
                -- TARGET_HOURS:
                -- AO: based on REQUIRE_ON vs CREATED_DATE
                -- MA1/MA5: configured priority-based hours
                -- OTHER: target is end of REQUIRE_ON day (hours from CREATED_DATE to TRUNC(REQUIRE_ON)+1)
                CASE
                  WHEN SUBSTR(NVL(rm.DIVISION,''),1,2) = 'AO' THEN
                    CASE
                      WHEN TRUNC(ph.CREATED_DATE) = TRUNC(ph.REQUIRE_ON) THEN 1
                      ELSE (TRUNC(ph.REQUIRE_ON) + 1 - ph.CREATED_DATE) * 24
                    END
                  WHEN SUBSTR(NVL(rm.DIVISION,''),1,3) = 'MA1' THEN
                    CASE WHEN ph.PRIORITY = 'AOG' THEN :ml_aog_hours
                         WHEN ph.PRIORITY = 'WSP' THEN :ml_wsp_hours
                         ELSE :ml_other_hours END
                  WHEN SUBSTR(NVL(rm.DIVISION,''),1,3) = 'MA5' THEN
                    CASE WHEN ph.PRIORITY = 'AOG' THEN :mb_aog_hours
                         WHEN ph.PRIORITY = 'WSP' THEN :mb_wsp_hours
                         ELSE :mb_other_hours END
                  ELSE
                    -- OTHER: end of REQUIRE_ON day
                    (TRUNC(ph.REQUIRE_ON) + 1 - ph.CREATED_DATE) * 24
                END AS TARGET_HOURS,
                -- REMAINING_HOURS = TARGET_HOURS - RUNNING_HOURS
                (CASE
                  WHEN SUBSTR(NVL(rm.DIVISION,''),1,2) = 'AO' THEN
                    CASE
                      WHEN TRUNC(ph.CREATED_DATE) = TRUNC(ph.REQUIRE_ON) THEN 1
                      ELSE (TRUNC(ph.REQUIRE_ON) + 1 - ph.CREATED_DATE) * 24
                    END
                  WHEN SUBSTR(NVL(rm.DIVISION,''),1,3) = 'MA1' THEN
                    CASE WHEN ph.PRIORITY = 'AOG' THEN :ml_aog_hours
                         WHEN ph.PRIORITY = 'WSP' THEN :ml_wsp_hours
                         ELSE :ml_other_hours END
                  WHEN SUBSTR(NVL(rm.DIVISION,''),1,3) = 'MA5' THEN
                    CASE WHEN ph.PRIORITY = 'AOG' THEN :mb_aog_hours
                         WHEN ph.PRIORITY = 'WSP' THEN :mb_wsp_hours
                         ELSE :mb_other_hours END
                  ELSE
                    (TRUNC(ph.REQUIRE_ON) + 1 - ph.CREATED_DATE) * 24
                END) - ROUND((SYSDATE - ph.CREATED_DATE) * 24) AS REMAINING_HOURS,
                -- TARGET_DATE computed appropriately:
                -- AO same-day: CREATED_DATE + 1 hour
                -- AO different-day: TRUNC(REQUIRE_ON) + 1
                -- MA1/MA5: CREATED_DATE + configured hours
                -- OTHER: TRUNC(REQUIRE_ON) + 1 (end of REQUIRE_ON day)
                TO_CHAR(
                  CASE
                    WHEN SUBSTR(NVL(rm.DIVISION,''),1,2) = 'AO' THEN
                      CASE
                        WHEN TRUNC(ph.CREATED_DATE) = TRUNC(ph.REQUIRE_ON) THEN ph.CREATED_DATE + 1/24
                        ELSE TRUNC(ph.REQUIRE_ON) + 1
                      END
                    WHEN SUBSTR(NVL(rm.DIVISION,''),1,3) = 'MA1' THEN ph.CREATED_DATE + (CASE WHEN ph.PRIORITY = 'AOG' THEN :ml_aog_hours WHEN ph.PRIORITY = 'WSP' THEN :ml_wsp_hours ELSE :ml_other_hours END)/24
                    WHEN SUBSTR(NVL(rm.DIVISION,''),1,3) = 'MA5' THEN ph.CREATED_DATE + (CASE WHEN ph.PRIORITY = 'AOG' THEN :mb_aog_hours WHEN ph.PRIORITY = 'WSP' THEN :mb_wsp_hours ELSE :mb_other_hours END)/24
                    ELSE TRUNC(ph.REQUIRE_ON) + 1
                  END
                , 'YYYY-MM-DD HH24:MI:SS') AS TARGET_DATE,
                ph.PICKLIST,
                ph.PRIORITY,
                ph.LOCATION,
                ph.DELIVERY_LOCATION,
                -- include REQUIRE_ON in the select list
                TO_CHAR(ph.REQUIRE_ON, 'YYYY-MM-DD HH24:MI:SS') AS REQUIRE_ON,
                CASE
                  WHEN SUBSTR(NVL(rm.DIVISION,''),1,3) = 'MA1' THEN 'MA-LINE'
                  WHEN SUBSTR(NVL(rm.DIVISION,''),1,3) = 'MA5' THEN 'MA-BASE'
                  WHEN SUBSTR(NVL(rm.DIVISION,''),1,2) = 'AO'  THEN 'AO'
                  ELSE 'OTHER'
                END AS DIV_GROUP
            FROM (
                SELECT PICKLIST_HEADER.PICKLIST
                FROM PICKLIST_HEADER
                INNER JOIN PICKLIST_DISTRIBUTION ON PICKLIST_HEADER.PICKLIST = PICKLIST_DISTRIBUTION.PICKLIST
                WHERE PICKLIST_HEADER.CREATED_DATE >= TO_DATE(:created_threshold, 'YYYY-MM-DD')
                  AND PICKLIST_HEADER.STATUS = 'OPEN'
                  {$locClause}
                GROUP BY PICKLIST_HEADER.PICKLIST
            ) PL_LIST
            LEFT JOIN PICKLIST_HEADER ph ON ph.PICKLIST = PL_LIST.PICKLIST
            LEFT JOIN RELATION_MASTER rm ON ph.CREATED_BY = rm.RELATION_CODE
            ORDER BY REMAINING_HOURS ASC
        ";

        // Prepare binds: convert keys to no-colon form for execute_query
        $binds = [];
        $initial = [
            'created_threshold' => CREATED_DATE_THRESHOLD,
            // MA-LINE: AOG 35 minutes, WSP 90 minutes, other 4 hours
            'ml_aog_hours' => 35.0 / 60.0,
            'ml_wsp_hours' => 90.0 / 60.0,
            'ml_other_hours' => 4.0,
            // MA-BASE: AOG 30 minutes, WSP 90 minutes, other 8 hours
            'mb_aog_hours' => 30.0 / 60.0,
            'mb_wsp_hours' => 90.0 / 60.0,
            'mb_other_hours' => 8.0,
            // OTHER division fallback kept for compatibility (not used for OTHER now)
            'other_div_hours' => 8.0,
            // Fallback (kept for safety)
            'aog_hours' => PRIORITY_TARGET_HOURS['AOG'],
            'wsp_hours' => PRIORITY_TARGET_HOURS['WSP'],
            'default_hours' => 72,
        ];
        foreach ($initial as $k => $v) $binds[$k] = $v;
        // add location binds (build_in_clause returned keys with leading colon)
        foreach ($locBinds as $k => $v) {
            $binds[ltrim((string)$k, ':')] = $v;
        }

        $stid = execute_query($conn, $sql, $binds);

        $rows = [];
        while (($row = oci_fetch_array($stid, DEFAULT_FETCH_MODE)) !== false) {
            $rows[] = [
                'PICK_DATE' => $row['PICK_DATE'] ?? '',
                'RUNNING_HOURS' => $row['RUNNING_HOURS'] ?? '',
                'TARGET_HOURS' => $row['TARGET_HOURS'] ?? '',
                'REMAINING_HOURS' => $row['REMAINING_HOURS'] ?? '',
                'TARGET_DATE' => $row['TARGET_DATE'] ?? '',
                'PICKLIST' => $row['PICKLIST'] ?? '',
                'PRIORITY' => $row['PRIORITY'] ?? '',
                'LOCATION' => $row['LOCATION'] ?? '',
                'DELIVERY_LOCATION' => $row['DELIVERY_LOCATION'] ?? '',
                // return REQUIRE_ON as string (or empty)
                'REQUIRE_ON' => $row['REQUIRE_ON'] ?? '',
                'DIV_GROUP' => $row['DIV_GROUP'] ?? 'OTHER',
            ];
        }

        oci_free_statement($stid);
        $stid = null;
        close_db($conn);
        $conn = null;

        return $rows;
    } catch (Throwable $e) {
        if ($stid) @oci_free_statement($stid);
        if ($conn) close_db($conn);
        error_log('fetch_open_picklist_rows error: ' . $e->getMessage());
        return [];
    }
}
