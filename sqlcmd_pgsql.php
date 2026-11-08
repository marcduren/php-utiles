<?php
/*
 * auteur : Marc Duren
 * Version PostgreSQL (extension pgsql) de sqlcmd.php
 */

class sqlCmd
{
    public $m_fields = array();
    public $m_values = array();
    public $m_type = array();
    protected $m_command = "";
    protected $m_params = array();
    protected $m_param_types = "";
    protected $m_is_prepared = array();

    /**
     * Formate une valeur SQL selon son type.
     *
     * s = string
     * n = numeric
     * d = date
     * l = litteral SQL
     * b = boolean
     */
    protected function formated($n)
    {
        $v = $this->m_values[$n];
        $t = $this->m_type[$n];
        switch ($t) {
            case "n":
                $v = str_replace(["\\'", "'"], "", (string)$v);

                if ($v === "" || $v === "NaN" || !is_numeric(str_replace(',', '.', $v))) {
                    return "null";
                }
                return str_replace(',', '.', $v);
            case "d":
                $v = str_replace(["\\'", "'"], "", (string)$v);

                return $v === "" ? "null" : "'" . $v . "'";

            case "b":
                $v = str_replace(["\\'", "'"], "", (string)$v);

                if ($v === "") {
                    return "null";
                }

                return in_array(
                    strtolower($v),
                    ["t", "oui", "on", "true", "1"],
                    true
                ) ? "TRUE" : "FALSE";
            case "l":
                return $v;
        }
        return "'" . str_replace("'", "''", str_replace("\\'", "'", $v)) . "'";
    }

    public function Add($tuple, $valeur, $type = "")
    {
        $this->m_fields[] = $tuple;
        $this->m_values[] = $valeur;
        if ($type == "") { //auto
            $this->m_type[] = is_numeric($valeur) ? "n" : "s";
        } else {
            $this->m_type[] = $type;
        }
        $this->m_is_prepared[] = false;
        return count($this->m_type) - 1;
    }

    public function AddNull($tuple)
    {
        $this->m_fields[] = $tuple;
        $this->m_values[] = "null";
        $this->m_type[] = "l";
        $this->m_is_prepared[] = false;
        return count($this->m_type) - 1;
    }
    /**
     * Ajoute un champ destiné à une requête préparée.
     *
     * Types :
     * s = string
     * i = integer
     * d = double
     * b = blob (bytea)
     */
    public function AddP($tuple, $valeur, $type = "s") // Ajoute un champ préparé
    {
        $allowed = ["s", "i", "d", "b"];

        if (!in_array($type, $allowed, true)) {
            throw new InvalidArgumentException("Type SQL invalide : " . $type);
        }

        $this->m_fields[] = $tuple;
        $this->m_values[] = $valeur;
        $this->m_type[] = $type;
        $this->m_is_prepared[] = true;

        return count($this->m_type) - 1;
    }
    public function GetParams()
    {
        $params = array();

        foreach ($this->m_values as $n => $value) {
            if ($this->m_is_prepared[$n]) {
                $params[] = $value;
            }
        }
        return $params;
    }
    public function GetParamTypes()
    {
        $types = "";

        foreach ($this->m_type as $n => $type) {
            if ($this->m_is_prepared[$n]) {
                $types .= $type;
            }
        }

        return $types;
    }
    public function IsPrepared()
    {
        return in_array(true, $this->m_is_prepared, true);
    }

    /**
     * PostgreSQL utilise des paramètres numérotés ($1, $2...) et non "?".
     */
    public function MakeUpdateQuery($table, $sqlwhere)
    {
        $sets = array();
        $i = 0;
        foreach ($this->m_fields as $n => $field) {
            $value = $this->m_is_prepared[$n]
                ? '$' . (++$i)
                : $this->formated($n);

            $sets[] = $field . "=" . $value;
        }

        $this->m_command = "UPDATE " . $table . " SET \n" . implode(",\n", $sets) . " WHERE " . $sqlwhere;
        return $this->m_command;
    }

    /**
     * @param string $returning Colonnes du RETURNING (ex. "id"), vide pour aucun.
     */
    public function MakeInsertQuery($table, $returning = "")
    {
        $values = array();
        $i = 0;
        for ($n = 0; $n < count($this->m_values); $n++) {
            $values[] = $this->m_is_prepared[$n]
                ? '$' . (++$i)
                : $this->formated($n);
        }
        $this->m_command = "INSERT INTO $table (\n" . implode(",", $this->m_fields)
            . ") VALUES (" . implode(",", $values) . ")";
        if ($returning !== "") {
            $this->m_command .= "\nRETURNING " . $returning;
        }
        $this->m_command .= "\n";
        return $this->m_command;
    }

    public function Clear()
    {
        $this->m_fields = array();
        $this->m_values = array();
        $this->m_type = array();
        $this->m_command = "";
        $this->m_is_prepared = array();
        $this->m_params = array();
        $this->m_param_types = "";
    }

    public function GetSQL()
    {
        return $this->m_command;
    }
}

interface sqlResult
{
    public function sql_num_rows();
    public function sql_fetch_array($r = null);
    public function sql_fetch_assoc($r = null);
    public function sql_fetch_all();
    public function sql_num_fields();
    public function sql_free_result();
}

interface sqlInterface
{
    public function sql_connect($host, $user, $password, $database, $port = 5432);
    public function sql_error();
    public function sql_close();
    /**
     * Exécute une requête, préparée ou non.
     *
     * @param string $query  SQL, avec des "$1, $2..." pour les paramètres préparés
     * @param string $types  Types des paramètres ("s", "i", "d", "b"), ex. "sis"
     * @param array  $params Valeurs des paramètres, dans l'ordre des $n
     * @return PgSql\Result|bool Résultat pour un SELECT, true pour une écriture, false en cas d'échec
     */
    public function sql_query(string $query, string $types = "", array $params = array());
    public function sql_query_cmd(sqlcmd $cmd);
    public function sql_result($query); //return sqlResult
    public function sql_num_rows($result);
    public function sql_fetch_array($result, $r = null);
    public function sql_fetch_assoc($result, $r = null);
    public function sql_fetch_result($result, $r, $field);
    public function sql_fetch_all($result);
    public function sql_num_fields($result);
    public function sql_free_result($result);
    public function sql_start_transaction();
    public function sql_commit();
    public function sql_rollback();
    public function sql_insert_id();
}

class pgsqlResult implements sqlResult
{
    public $dbResult = null;

    public function __construct($r)
    {
        $this->dbResult = $r;
    }

    public function sql_num_rows()
    {
        return pg_num_rows($this->dbResult);
    }

    public function sql_fetch_array($r = null)
    {
        return pg_fetch_array($this->dbResult, $r);
    }

    public function sql_fetch_assoc($r = null)
    {
        return pg_fetch_assoc($this->dbResult, $r);
    }

    public function sql_fetch_all()
    {
        // pg_fetch_all renvoie false si le résultat est vide.
        return pg_fetch_all($this->dbResult, PGSQL_ASSOC) ?: array();
    }

    public function sql_num_fields()
    {
        return pg_num_fields($this->dbResult);
    }

    public function sql_free_result()
    {
        if ($this->dbResult !== null) {
            pg_free_result($this->dbResult);
            $this->dbResult = null;
        }
    }
}

class interface_pgsql implements sqlInterface
{
    public $dbLink = null;

    public function sql_connect($host, $user, $password, $database, $port = 5432)
    {
        $esc = function ($v) {
            return "'" . addcslashes((string)$v, "'\\") . "'";
        };

        $conn = "host=" . $esc($host)
            . " port=" . (int)$port
            . " dbname=" . $esc($database)
            . " user=" . $esc($user)
            . " password=" . $esc($password);

        $this->dbLink = pg_connect($conn);
        if ($this->dbLink) {
            pg_set_client_encoding($this->dbLink, "UTF8");
        }
        return $this->dbLink;
    }

    public function sql_error()
    {
        return pg_last_error($this->dbLink);
    }

    public function sql_close()
    {
        if ($this->dbLink) {
            pg_close($this->dbLink);
        }
        $this->dbLink = null;
    }

    /**
     * Les types ("s", "i", "d", "b") ne sont pas envoyés à PostgreSQL
     * (le serveur déduit les types), mais servent à valider les paramètres
     * et à encoder les blobs (bytea).
     */
    public function sql_query($query, $types = "", $params = array())
    {
        if (!$this->dbLink) {
            return false;
        }

        // Requête classique.
        if ($types === "" && empty($params)) {
            $r = pg_query($this->dbLink, $query);
            return $this->normalizeResult($r);
        }

        // Vérification des paramètres.
        if (strlen($types) !== count($params)) {
            return false;
        }

        $values = array();
        $i = 0;
        foreach ($params as $value) {
            $t = $types[$i++];

            if ($value === null) {
                $values[] = null;
            } elseif (is_bool($value)) {
                $values[] = $value ? 't' : 'f';
            } elseif ($t === "b") {
                $values[] = pg_escape_bytea($this->dbLink, $value);
            } else {
                $values[] = $value;
            }
        }

        $r = pg_query_params($this->dbLink, $query, $values);
        return $this->normalizeResult($r);
    }

    /**
     * Comme mysqli : true pour une écriture, la ressource pour un SELECT
     * (ou un INSERT ... RETURNING), false en cas d'échec.
     */
    protected function normalizeResult($r)
    {
        if ($r === false) {
            return false;
        }
        if (pg_result_status($r) === PGSQL_COMMAND_OK) {
            pg_free_result($r);
            return true;
        }
        return $r;
    }

    public function sql_result($query)
    {
        $r = pg_query($this->dbLink, $query);
        if ($r) {
            return new pgsqlResult($r);
        } else {
            return false;
        }
    }

    public function sql_num_rows($result)
    {
        return pg_num_rows($result);
    }

    public function sql_fetch_array($result, $r = null)
    {
        return pg_fetch_array($result, $r);
    }

    public function sql_fetch_assoc($result, $r = null)
    {
        return pg_fetch_assoc($result, $r);
    }

    public function sql_fetch_result($result, $r, $field)
    {
        if (is_null($r)) {
            return pg_fetch_result($result, $field);
        }
        return pg_fetch_result($result, $r, $field);
    }

    public function sql_fetch_all($result)
    {
        return pg_fetch_all($result, PGSQL_ASSOC) ?: array();
    }

    public function sql_num_fields($result)
    {
        return pg_num_fields($result);
    }

    public function sql_free_result($result)
    {
        pg_free_result($result);
    }

    public function sql_start_transaction()
    {
        return $this->sql_query("START TRANSACTION");
    }

    public function sql_commit()
    {
        return $this->sql_query("COMMIT");
    }

    public function sql_rollback()
    {
        return $this->sql_query("ROLLBACK");
    }

    /**
     * PostgreSQL n'a pas d'équivalent à mysqli_insert_id().
     * lastval() renvoie la dernière valeur de séquence de la session
     * (SERIAL / IDENTITY). Préférer INSERT ... RETURNING quand c'est possible.
     */
    public function sql_insert_id()
    {
        $r = pg_query($this->dbLink, "SELECT lastval()");
        if (!$r) {
            return false;
        }
        $id = pg_fetch_result($r, 0, 0);
        pg_free_result($r);
        return (int)$id;
    }

    public function sql_query_cmd(sqlcmd $cmd)
    {
        return $this->sql_query(
            $cmd->GetSQL(),
            $cmd->GetParamTypes(),
            $cmd->GetParams()
        );
    }
}
