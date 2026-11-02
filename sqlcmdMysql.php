<?php
/*
 * auteur : Marc Duren
 *
 * Version MySQL / MariaDB (mysqli).
 * Ce fichier et sqlcmdPG.php exposent exactement la même API :
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
    protected $m_link = null; //connexion mysqli (optionnelle) pour un échappement fiable

    /**
     * Fournit la connexion mysqli : l'échappement se fait alors avec
     * mysqli_real_escape_string (tient compte du charset et du sql_mode).
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
        if ($this->m_link instanceof mysqli) {
            return "'" . mysqli_real_escape_string($this->m_link, $v) . "'";
        }
        //repli : doubler les antislashs ET les apostrophes
        return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $v) . "'";
    }

    /** Valide un nom de champ ou de table : `nom` ou `table.nom`. */
    protected function checkField($f)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', (string)$f)) {
            throw new InvalidArgumentException("Nom de champ invalide : $f");
        }
    }

    /** Identifiant protégé (spécifique au SGBD) : `champ` ou `table`.`champ` */
    protected function quoteField($f)
    {
        $parts = explode(".", $f);
        foreach ($parts as $i => $p) {
            $parts[$i] = "`" . str_replace("`", "``", $p) . "`";
        }
        return implode(".", $parts);
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
    public function sql_query_param($query, $params); //placeholders $1, $2... (comme PostgreSQL)
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

class mysqlResult implements sqlResult
{
    public $dbRresult = null; //(nom conservé pour compatibilité)

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
        return mysqli_num_rows($this->dbRresult);
    }

    public function sql_fetch_array($r = null)
    {
        if (!is_null($r)) {
            mysqli_data_seek($this->dbRresult, $r);
        }
        return mysqli_fetch_array($this->dbRresult);
    }

    public function sql_fetch_assoc($r = null)
    {
        if (!is_null($r)) {
            mysqli_data_seek($this->dbRresult, $r);
        }
        return mysqli_fetch_assoc($this->dbRresult);
    }

    public function sql_fetch_all()
    {
        return mysqli_fetch_all($this->dbRresult, MYSQLI_ASSOC);
    }

    public function sql_fetch_result($row, $field)
    {
        mysqli_data_seek($this->dbRresult, $row);
        $data = mysqli_fetch_array($this->dbRresult);
        return $data ? ($data[$field] ?? null) : null;
    }

    public function sql_num_fields()
    {
        return mysqli_num_fields($this->dbRresult);
    }

    public function sql_free_result()
    {
        if ($this->dbRresult !== null) {
            mysqli_free_result($this->dbRresult);
            $this->dbRresult = null;
        }
    }

    public function forEachRow($f)
    {
        $index = 0;
        while ($row = mysqli_fetch_assoc($this->dbRresult)) {
            $f($row, $index++);
        }
    }
}

class interface_mysql implements sqlInterface
{
    public $dbLink = null;
    protected $m_connectError = null;
    protected $m_lastError = null; //erreur d'une requête préparée

    protected function logSlow($debut, $query)
    {
        $temps = microtime(true) - $debut;
        if ($temps > SQL_SLOW_THRESHOLD) {
            sqllogs("Requête lente " . round($temps, 3) . "s : " . str_replace("\n", " ", $query));
        }
    }

    public function sql_connect($host, $user, $password, $database)
    {
        //PHP >= 8.1 lève des exceptions par défaut : on garde le comportement "retourne false"
        //+ sql_error(), identique à la version PostgreSQL
        mysqli_report(MYSQLI_REPORT_OFF);
        $link = @mysqli_connect($host, $user, $password, $database);
        if (!$link) {
            $this->m_connectError = mysqli_connect_error();
            $this->dbLink = null;
            return false;
        }
        mysqli_set_charset($link, "utf8mb4");
        $this->m_connectError = null;
        $this->dbLink = $link;
        return $this->dbLink;
    }

    public function sql_error()
    {
        if (!$this->dbLink) {
            return $this->m_connectError ?: "Pas de connexion";
        }
        return $this->m_lastError ?: mysqli_error($this->dbLink);
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
        $this->m_lastError = null;
        $debut = microtime(true);
        $reponse = mysqli_query($this->dbLink, $query);
        $this->logSlow($debut, $query);
        return $reponse;
    }

    /**
     * Requête paramétrée avec la syntaxe PostgreSQL ($1, $2...), convertie en "?".
     * Attention : ne pas écrire de "$1" à l'intérieur d'un littéral texte de la requête.
     * Retourne un mysqli_result (SELECT), true (INSERT/UPDATE...) ou false.
     */
    public function sql_query_param($query, $params)
    {
        $this->m_lastError = null;
        $params = array_values((array)$params);
        $ordre = array();
        $sql = preg_replace_callback('/\$(\d+)/', function ($m) use (&$ordre) {
            $ordre[] = (int)$m[1] - 1;
            return '?';
        }, $query);

        $bind = array();
        foreach ($ordre as $i) {
            if (!array_key_exists($i, $params)) {
                $this->m_lastError = "Paramètre \$" . ($i + 1) . " manquant";
                return false;
            }
            $p = $params[$i];
            $bind[] = ($p === null) ? null : (is_bool($p) ? (int)$p : (string)$p);
        }

        $debut = microtime(true);
        $stmt = mysqli_prepare($this->dbLink, $sql);
        if (!$stmt) {
            return false;
        }
        if ($bind) {
            mysqli_stmt_bind_param($stmt, str_repeat('s', count($bind)), ...$bind);
        }
        if (!mysqli_stmt_execute($stmt)) {
            $this->m_lastError = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            return false;
        }
        $res = mysqli_stmt_get_result($stmt); //false pour INSERT/UPDATE
        mysqli_stmt_close($stmt);
        $this->logSlow($debut, $query);
        return ($res instanceof mysqli_result) ? $res : true;
    }

    public function sql_result($query)
    {
        $r = $this->sql_query($query); // passe par le log des requêtes lentes
        if ($r instanceof mysqli_result) { //un INSERT/UPDATE renvoie true, pas un résultat
            return new mysqlResult($r);
        }
        return false;
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

    public function sql_fetch_row($result, $r = null)
    {
        return $this->assoc_numeric($this->sql_fetch_assoc($result, $r));
    }

    public function sql_fetch_all($result)
    {
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }

    public function sql_fetch_result($result, $r, $field)
    {
        if (!is_null($r)) {
            mysqli_data_seek($result, $r);
        }
        $row = mysqli_fetch_array($result);
        return $row ? ($row[$field] ?? null) : null;
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

//même nom de classe que la version PostgreSQL (pgsqlInterface)
class_alias('interface_mysql', 'mysqlInterface');
