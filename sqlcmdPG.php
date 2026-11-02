<?php
/*
 * auteur : Marc Duren
 *
 * Version PostgreSQL (pgsql).
 * Ce fichier et sqlcmdMysql.php exposent exactement la même API :
 * on peut passer de l'un à l'autre en changeant uniquement le require.
 */

if (!defined('SQL_SLOW_THRESHOLD')) {
    define('SQL_SLOW_THRESHOLD', 0.1); //secondes : au-delà, la requête est journalisée
}

if (!function_exists('sqllogs')) {
    function sqllogs($log)
    {
        $dir = __DIR__ . "/log/";
        if (is_writable($dir)) {
            @file_put_contents($dir . "sql.log", date("d.m.Y H:i:s") . " " . $log . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}

class sqlCmd
{
    public $m_fields = array();
    public $m_values = array();
    public $m_type = array(); //s=string,n=numeric,d=date,l=litteral,b=bool
    public $legacyUnescape = true; //true : transforme \' en ' (anciennes entrées déjà échappées)
    protected $m_command = "";
    protected $m_link = null; //connexion pgsql (optionnelle) pour un échappement fiable

    /**
     * Fournit la connexion pgsql : l'échappement se fait alors avec
     * pg_escape_literal (tient compte de l'encodage et de standard_conforming_strings).
     */
    public function setLink($link)
    {
        $this->m_link = $link;
    }

    protected function unslash($v)
    {
        return $this->legacyUnescape ? str_replace("\\'", "'", $v) : $v;
    }

    /** Échappe une chaîne et la met entre apostrophes (spécifique au SGBD). */
    protected function quote($v)
    {
        $v = str_replace("\0", "", $this->unslash((string)$v));
        if ($this->m_link) {
            $lit = @pg_escape_literal($this->m_link, $v);
            if ($lit !== false) {
                return $lit;
            }
        }
        //repli valable quel que soit standard_conforming_strings : littéral E'...'
        return "E'" . str_replace(["\\", "'"], ["\\\\", "''"], $v) . "'";
    }

    /** Valide un nom de champ ou de table : `nom` ou `schema.nom`. */
    protected function checkField($f)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', (string)$f)) {
            throw new InvalidArgumentException("Nom de champ invalide : $f");
        }
    }

    /**
     * Identifiant (spécifique au SGBD). Volontairement NON entre guillemets :
     * PostgreSQL met en minuscules les identifiants non protégés, et les colonnes
     * existantes ont probablement été créées ainsi. checkField() garantit la sûreté.
     */
    protected function quoteField($f)
    {
        return $f;
    }

    /** Nombre "propre" (pas de zéro initial : "0612345678" reste du texte). */
    protected function isCleanNumber($v)
    {
        if (is_int($v)) {
            return true;
        }
        if (is_float($v)) {
            return is_finite($v);
        }
        return is_string($v) && preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/', $v) === 1;
    }

    protected function autoType($v)
    {
        if (is_bool($v)) {
            return "b";
        }
        return $this->isCleanNumber($v) ? "n" : "s";
    }

    protected function formated($n)
    {
        $v = $this->m_values[$n];
        $t = $this->m_type[$n];

        if ($v === null) {
            return "null";
        }
        if (is_bool($v)) { //évite false => "" => null
            return $v ? "TRUE" : "FALSE";
        }
        $v = (string)$v;

        switch ($t) {
            case "n":
                $v = trim(str_replace(',', '.', $v));
                if ($v === "" || $v === "NaN") {
                    return "null";
                }
                // sécurité : uniquement un vrai nombre (empêche l'injection via un champ numérique)
                if (!is_numeric($v)) {
                    throw new InvalidArgumentException("Valeur non numérique pour le champ " . $this->m_fields[$n]);
                }
                return $v;
            case "d":
                $v = trim($v);
                if ($v === "") {
                    return "null";
                }
                return $this->quote($v);
            case "b":
                $v = trim($v);
                if ($v === "") {
                    return "null";
                }
                return in_array($v, ["t", "Oui", "on", "true", "TRUE", "1"], true) ? "TRUE" : "FALSE";
            case "l":
                return $v; // littéral brut : ne jamais y mettre de données utilisateur
        }
        // type "s"
        return $this->quote($v);
    }

    public function Add($tuple, $valeur, $type = "")
    {
        // le nom de colonne est inséré dans la requête : on le valide
        $this->checkField($tuple);
        if ($type === "" || $type === null) { //auto
            $type = $this->autoType($valeur);
        } elseif (!in_array($type, ["s", "n", "d", "b", "l"], true)) {
            throw new InvalidArgumentException("Type inconnu '$type' pour le champ $tuple");
        }
        $this->m_fields[] = $tuple;
        $this->m_values[] = $valeur;
        $this->m_type[] = $type;
        return count($this->m_type) - 1;
    }

    public function AddNull($tuple)
    {
        $this->checkField($tuple);
        $this->m_fields[] = $tuple;
        $this->m_values[] = "null";
        $this->m_type[] = "l";
        return count($this->m_type) - 1;
    }

    public function MakeUpdateQuery($table, $sqlwhere)
    {
        $this->checkField($table);
        if (count($this->m_fields) == 0) {
            throw new LogicException("UPDATE sans aucun champ");
        }
        if (trim((string)$sqlwhere) === "") {
            throw new InvalidArgumentException("UPDATE sans clause WHERE refusé");
        }
        $sets = array();
        for ($n = 0; $n < count($this->m_fields); $n++) {
            $sets[] = $this->quoteField($this->m_fields[$n]) . "=" . $this->formated($n);
        }
        //$sqlwhere doit être construit par l'appelant de façon sûre
        $this->m_command = "UPDATE " . $this->quoteField($table) . " SET \n"
            . implode(",\n", $sets) . " \nWHERE $sqlwhere";
        return $this->m_command;
    }

    public function MakeInsertQuery($table)
    {
        $this->checkField($table);
        if (count($this->m_fields) == 0) {
            throw new LogicException("INSERT sans aucun champ");
        }
        $fields = array();
        $values = array();
        for ($n = 0; $n < count($this->m_fields); $n++) {
            $fields[] = $this->quoteField($this->m_fields[$n]);
            $values[] = $this->formated($n);
        }
        $this->m_command = "INSERT INTO " . $this->quoteField($table) . " (\n" . implode(",", $fields)
            . ") \nVALUES (" . implode(",", $values) . ")\n";
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
    public function sql_fetch_result($row, $field);
    public function sql_num_fields();
    public function sql_free_result();
    public function forEachRow($f);
}

interface sqlInterface
{
    public function sql_connect($host, $user, $password, $database);
    public function sql_error();
    public function sql_close();
    public function sql_query($query);
    public function sql_query_param($query, $params); //placeholders $1, $2...
    public function sql_result($query); //return sqlResult|false
    public function sql_num_rows($result);
    public function sql_fetch_array($result, $r = null);
    public function sql_fetch_assoc($result, $r = null);
    public function sql_fetch_row($result, $r = null); // tableau associatif avec conversion numérique
    public function sql_fetch_all($result);
    public function sql_fetch_result($result, $r, $field);
    public function sql_num_fields($result);
    public function sql_free_result($result);
    public function sql_start_transaction();
    public function sql_commit();
    public function sql_rollback();
    public function sql_insert_id();
    public function assoc_numeric($row); // convertir les 'is_numeric' en numérique
}

class pgsqlResult implements sqlResult
{
    public $dbRresult = null;

    public function __construct($r)
    {
        $this->dbRresult = $r;
    }

    public function __destruct()
    {
        $this->sql_free_result();
    }

    public function sql_num_rows()
    {
        return pg_num_rows($this->dbRresult);
    }

    public function sql_fetch_array($r = null)
    {
        return pg_fetch_array($this->dbRresult, $r);
    }

    public function sql_fetch_assoc($r = null)
    {
        return pg_fetch_assoc($this->dbRresult, $r);
    }

    public function sql_fetch_all()
    {
        return pg_fetch_all($this->dbRresult) ?: array(); //false si aucune ligne (PHP < 8)
    }

    public function sql_fetch_result($row, $field)
    {
        return pg_fetch_result($this->dbRresult, $row, $field);
    }

    public function sql_num_fields()
    {
        return pg_num_fields($this->dbRresult);
    }

    public function sql_free_result()
    {
        if ($this->dbRresult !== null) {
            pg_free_result($this->dbRresult);
            $this->dbRresult = null;
        }
    }

    public function forEachRow($f)
    {
        $index = 0;
        while ($row = pg_fetch_assoc($this->dbRresult)) {
            $f($row, $index++);
        }
    }
}

class pgsqlInterface implements sqlInterface
{
    public $dbLink = null;
    protected $m_connectError = null;

    protected function logSlow($debut, $query)
    {
        $temps = microtime(true) - $debut;
        if ($temps > SQL_SLOW_THRESHOLD) {
            sqllogs("Requête lente " . round($temps, 3) . "s : " . str_replace("\n", " ", $query));
        }
    }

    public function sql_connect($host, $user, $password, $database)
    {
        // échappement des valeurs (espaces, apostrophes, antislashs) dans la chaîne de connexion
        $esc = function ($v) {
            return "'" . addcslashes((string)$v, "'\\") . "'";
        };
        // pg_connect émet un warning en cas d'échec : on récupère le message pour sql_error()
        $erreur = null;
        set_error_handler(function ($no, $str) use (&$erreur) {
            $erreur = $str;
            return true;
        });
        $link = pg_connect(
            "host=" . $esc($host) . " dbname=" . $esc($database) .
                " user=" . $esc($user) . " password=" . $esc($password)
        );
        restore_error_handler();

        if (!$link) {
            $this->m_connectError = $erreur;
            $this->dbLink = null;
            return false;
        }
        pg_set_client_encoding($link, "UTF8");
        $this->m_connectError = null;
        $this->dbLink = $link;
        return $this->dbLink;
    }

    public function sql_error()
    {
        if (!$this->dbLink) {
            return $this->m_connectError ?: "Pas de connexion";
        }
        return pg_last_error($this->dbLink);
    }

    public function sql_close()
    {
        if ($this->dbLink) {
            pg_close($this->dbLink);
        }
        $this->dbLink = null;
    }

    public function sql_query($query)
    {
        $debut = microtime(true);
        $reponse = pg_query($this->dbLink, $query);
        $this->logSlow($debut, $query);
        return $reponse;
    }

    public function sql_query_param($query, $params)
    {
        $debut = microtime(true);
        $reponse = pg_query_params($this->dbLink, $query, array_values((array)$params));
        $this->logSlow($debut, $query);
        return $reponse;
    }

    public function sql_result($query)
    {
        $r = $this->sql_query($query); // passe par le log des requêtes lentes
        return $r ? new pgsqlResult($r) : false;
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

    public function sql_fetch_row($result, $r = null)
    {
        return $this->assoc_numeric(pg_fetch_assoc($result, $r));
    }

    public function sql_fetch_all($result)
    {
        return pg_fetch_all($result) ?: array(); //false si aucune ligne (PHP < 8)
    }

    public function sql_fetch_result($result, $r, $field)
    {
        return pg_fetch_result($result, $r, $field);
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
        return $this->sql_query("BEGIN");
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
     * Dernier identifiant de séquence généré dans la session (équivalent de mysqli_insert_id).
     * Retourne false si aucune séquence n'a encore été utilisée.
     * Pour une table précise, préférer "INSERT ... RETURNING id".
     */
    public function sql_insert_id()
    {
        $r = @pg_query($this->dbLink, "SELECT lastval()");
        if (!$r) {
            return false;
        }
        $id = pg_fetch_result($r, 0, 0);
        pg_free_result($r);
        return $id === null ? false : (int)$id;
    }

    public function assoc_numeric($row) // converti les 'is_numeric' en numérique
    {
        if (is_array($row)) {
            foreach ($row as $key => $value) {
                // nombres "propres" uniquement : on garde en texte "0612345678", "1e5", " 12", etc.
                if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/', $value)) {
                    $row[$key] = (strpos($value, ".") !== false) ? floatval($value) : intval($value);
                }
            }
        }
        return $row;
    }
}
