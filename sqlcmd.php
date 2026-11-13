<?php

/**
 * Auteur original : Marc Duren
 * Optimisation et corrections : ChatGPT
 *
 * Couche d'abstraction SQL MySQLi
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
     * b = blob
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

    public function MakeUpdateQuery($table, $sqlwhere)
    {
        $sets = array();
        foreach ($this->m_fields as $n => $field) {
            $value = $this->m_is_prepared[$n]
                ? "?"
                : $this->formated($n);

            $sets[] = $field . "=" . $value;
        }

        $this->m_command = "UPDATE " . $table . " SET \n" . implode(",\n", $sets) . " WHERE " . $sqlwhere;
        return $this->m_command;
    }

    public function MakeInsertQuery($table)
    {
        $values = array();
        for ($n = 0; $n < count($this->m_values); $n++) {
            $values[] = $this->m_is_prepared[$n]
                ? "?"
                : $this->formated($n);
        }
        $this->m_command = "INSERT INTO $table (\n" . implode(",", $this->m_fields)
            . ") VALUES (" . implode(",", $values) . ")\n";
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
    public function sql_connect($host, $user, $password, $database);
    public function sql_error();
    public function sql_close();
    /**
     * Exécute une requête, préparée ou non.
     *
     * @param string $query  SQL, avec des "?" pour les paramètres préparés
     * @param string $types  Types des paramètres ("s", "i", "d", "b"), ex. "sis"
     * @param array  $params Valeurs des paramètres, dans l'ordre des "?"
     * @return mysqli_result|bool Résultat pour un SELECT, true pour une écriture, false en cas d'échec
     */
    public function sql_query(string $query, string $types = "", array $params = array());
    public function sql_query_params(sqlcmd $cmd);
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

class mysqlResult implements sqlResult
{
    public $dbResult = null;

    public function __construct($r)
    {
        $this->dbResult = $r;
    }

    public function sql_num_rows()
    {
        return mysqli_num_rows($this->dbResult);
    }

    public function sql_fetch_array($r = null)
    {
        if (!is_null($r)) {
            mysqli_data_seek($this->dbResult, $r);
        }
        return mysqli_fetch_array($this->dbResult);
    }

    public function sql_fetch_assoc($r = null)
    {
        if (!is_null($r)) {
            mysqli_data_seek($this->dbResult, $r);
        }
        return mysqli_fetch_assoc($this->dbResult);
    }
    public function sql_fetch_all()
    {
        return mysqli_fetch_all($this->dbResult, MYSQLI_ASSOC);
    }

    public function sql_num_fields()
    {
        return mysqli_num_fields($this->dbResult);
    }

    public function sql_free_result()
    {
        if ($this->dbResult !== null) {
            mysqli_free_result($this->dbResult);
            $this->dbResult = null;
        }
    }
}

class interface_mysql implements sqlInterface
{
    public $dbLink = null;

    public function sql_connect($host, $user, $password, $database)
    {
        $this->dbLink = mysqli_connect($host, $user, $password, $database);
        mysqli_set_charset($this->dbLink, "utf8");
        return $this->dbLink;
    }

    public function sql_error()
    {
        return mysqli_error($this->dbLink);
    }

    public function sql_close()
    {
        if ($this->dbLink) {
            mysqli_close($this->dbLink);
        }
        $this->dbLink = null;
    }

    public function sql_query($query, $types = "", $params = array())
    {
        if (!$this->dbLink) {
            return false;
        }

        // Ancien fonctionnement : requête classique.
        if ($types === "" && empty($params)) {
            return mysqli_query($this->dbLink, $query);
        }

        // Vérification des paramètres.
        if (strlen($types) !== count($params)) {
            return false;
        }

        $stmt = mysqli_prepare($this->dbLink, $query);

        if (!$stmt) {
            return false;
        }

        if (!empty($params)) {
            $bindParams = array();
            foreach ($params as $n => &$value) {
                $bindParams[$n] = &$value;
            }
            unset($value);
            if (!mysqli_stmt_bind_param(
                $stmt,
                $types,
                ...$bindParams
            )) {
                mysqli_stmt_close($stmt);
                return false;
            }
        }

        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }

        // Requête retournant des résultats.
        if ($stmt->field_count > 0) {
            $result = mysqli_stmt_get_result($stmt);

            if ($result === false) {
                mysqli_stmt_close($stmt);
                return false;
            }

            mysqli_stmt_close($stmt);

            return $result;
        }

        mysqli_stmt_close($stmt);

        return true;
    }

    public function sql_result($query)
    {
        $r = mysqli_query($this->dbLink, $query);
        if ($r) {
            return new mysqlResult(($r));
        } else {
            return false;
        }
    }

    public function sql_num_rows($result)
    {
        return mysqli_num_rows($result);
    }

    public function sql_fetch_array($result, $r = null)
    {
        if (!is_null($r)) {
            mysqli_data_seek($result, $r);
        }
        return mysqli_fetch_array($result);
    }

    public function sql_fetch_assoc($result, $r = null)
    {
        if (!is_null($r)) {
            mysqli_data_seek($result, $r);
        }
        return mysqli_fetch_assoc($result);
    }
    public function sql_fetch_result($result, $r, $field)
    {
        if (!is_null($r)) {
            mysqli_data_seek($result, $r);
        }
        return mysqli_fetch_array($result)[$field];
    }

    public function sql_fetch_all($result)
    {
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }

    public function sql_num_fields($result)
    {
        return mysqli_num_fields($result);
    }

    public function sql_free_result($result)
    {
        mysqli_free_result($result);
    }

    public function sql_start_transaction()
    {
        return mysqli_begin_transaction($this->dbLink);
    }

    public function sql_commit()
    {
        return mysqli_commit($this->dbLink);
    }

    public function sql_rollback()
    {
        return mysqli_rollback($this->dbLink);
    }

    public function sql_insert_id()
    {
        return mysqli_insert_id($this->dbLink);
    }
    public function sql_query_params(sqlcmd $cmd)
    {
        return $this->sql_query(
            $cmd->GetSQL(),
            $cmd->GetParamTypes(),
            $cmd->GetParams()
        );
    }
}
