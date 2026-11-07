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
        return count($this->m_type) - 1;
    }

    public function AddNull($tuple)
    {
        $this->m_fields[] = $tuple;
        $this->m_values[] = "null";
        $this->m_type[] = "l";
        return count($this->m_type) - 1;
    }

    public function MakeUpdateQuery($table, $sqlwhere)
    {
        $sets = array();
        for ($n = 0; $n < count($this->m_fields); $n++) {
            $sets[] = $this->m_fields[$n] . "=" . $this->formated($n);
        }
        $this->m_command = "UPDATE $table SET \n" . implode(",\n", $sets) . " WHERE $sqlwhere";
        return $this->m_command;
    }

    public function MakeInsertQuery($table)
    {
        $values = array();
        for ($n = 0; $n < count($this->m_values); $n++) {
            $values[] = $this->formated($n);
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
    public function sql_query($query);
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

    public function sql_query($query)
    {
        return mysqli_query($this->dbLink, $query);
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
}
