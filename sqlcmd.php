<?php
/*
     * auteur : Marc Duren
     */

class sqlCmd
{
    public $m_fields = array();
    public $m_values = array();
    public $m_type = array(); //s=string,n=numeric,d=date,l=litteral,b=bool
    protected $m_command = "";

    protected function formated($n)
    {
        $v = $this->m_values[$n];
        $t = $this->m_type[$n];
        switch ($t) {
            case "n":
                $v = str_replace(["\'", "'"], "", $v); //pas de guillemets
                if (strlen($v) === 0 || $v === "NaN") {
                    return 'null';
                } else {
                    return str_replace(',', '.', $v);
                }
                // no break
            case "d":
                $v = str_replace(["\'", "'"], "", $v); //pas de guillemets
                if (strlen($v) == 0) {
                    return "null";
                }
                return "'" . $v . "'";
            case "b":
                $v = str_replace(["\'", "'"], "", $v); //pas de guillemets
                if (strlen($v) == 0) {
                    return "null";
                } else {
                    if ($v == "t" || $v == "Oui" || $v == "on" || $v == "true" || $v == "TRUE" || $v === "1") {
                        return "TRUE";
                    }
                }
                return "FALSE";
            case "l":
                return $v;
        }
        return "'" . str_replace("'", "''", str_replace("\'", "'", $v)) . "'";
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
        $this->m_command = "UPDATE `$table` SET \n";
        for ($n = 0; $n < count($this->m_fields); $n++) {
            $f = $this->m_fields[$n];
            if ($n > 0) {
                $this->m_command .= ",\n";
            }
            $this->m_command .= "$f=" . $this->formated($n);
        }
        $this->m_command .= " \nWHERE $sqlwhere";
        return $this->m_command;
    }

    public function MakeInsertQuery($table)
    {
        $this->m_command = "INSERT INTO `$table` (\n";
        for ($n = 0; $n < count($this->m_fields); $n++) {
            if ($n > 0) {
                $this->m_command .= ",";
            }
            $this->m_command .= $this->m_fields[$n];
        }
        $this->m_command .= ") \nVALUES (";
        for ($n = 0; $n < count($this->m_values); $n++) {
            if ($n > 0) {
                $this->m_command .= ",";
            }
            $this->m_command .= $this->formated($n);
        }

        $this->m_command .= ")\n";
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
        mysqli_autocommit($this->dbLink, false);
        return $this->sql_query("START TRANSACTION");
    }

    public function sql_commit()
    {
        $this->sql_query("COMMIT");
        mysqli_autocommit($this->dbLink, true);
    }

    public function sql_rollback()
    {
        $this->sql_query("ROLLBACK");
        mysqli_autocommit($this->dbLink, true);
    }

    public function sql_insert_id()
    {
        return mysqli_insert_id($this->dbLink);
    }
}
