<?php

    $tbFeries = array(); //jours fériés France Moselle
    function defJoursFeries($annee)
    {
        global $anneeFeries;
        global $tbFeries;

        $dpaques = easter_date($annee) + 43200; // midi
        $p1 = $dpaques - 172800; //Vendredi saint
        $p2 = $dpaques + 86400; //Lundi de Pâques
        $p3 = $dpaques + 3369600; //Jeudi de l'Ascension
        $p4 = $dpaques + 4320000; //Lundi de Pentecôte

        $tbFeries = array(
            array("m" => 1, "j" => 1), //Nouvel An
            array("m" => 5, "j" => 1), //Fête du Travail
            array("m" => 5, "j" => 8), //8 Mai 1945
            array("m" => 7, "j" => 14), //Fête Nationale
            array("m" => 8, "j" => 15), //Assomption
            array("m" => 11, "j" => 1), //La Toussaint
            array("m" => 11, "j" => 11), //11 Novembre 1918
            array("m" => 12, "j" => 25), //Noël
            array("m" => 12, "j" => 26), //Lendemain de Noël
            array("m" => date("m", $p1) + 0, "j" => date("d", $p1) + 0), //Vendredi saint
            array("m" => date("m", $p2) + 0, "j" => date("d", $p2) + 0), //Lundi de Pâques
            array("m" => date("m", $p3) + 0, "j" => date("d", $p3) + 0), //Jeudi de l'Ascension
            array("m" => date("m", $p4) + 0, "j" => date("d", $p4) + 0), //Lundi de Pentecôte
        );
    }

    function nb_jours($date1, $date2, $dem = "-")
    {
        list($a1, $m1, $j1) = explode($dem, substr($date1, 0, 10));
        list($a2, $m2, $j2) = explode($dem, substr($date2, 0, 10));
        $mk1 = mktime(12, 0, 0, $m1, $j1, $a1);
        $mk2 = mktime(12, 0, 0, $m2, $j2, $a2);
        $n1 = floor($mk1 / 86400);
        $n2 = floor($mk2 / 86400);
        $nbjo = $n2 - $n1;
        return $nbjo;
    }

    function nb_jours_ouvres($date1, $date2, $dem = "-")
    {
        /* http://www.eggheadcafe.com/community/aspnet/2/44982/how-to-calculate-num-of-w.aspx */

        list($a1, $m1, $j1) = explode($dem, substr($date1, 0, 10));
        list($a2, $m2, $j2) = explode($dem, substr($date2, 0, 10));
        $mk1 = mktime(12, 0, 0, $m1, $j1, $a1);
        $mk2 = mktime(12, 0, 0, $m2, $j2, $a2);

        $dowStart = date("w", $mk1) + 0;
        $dowEnd = date("w", $mk2) + 0;
        if ($dowStart == 0) {
            $dowStart = 7;
        }
        if ($dowEnd == 0) {
            $dowEnd = 7;
        }
        $n1 = floor($mk1 / 86400);
        $n2 = floor($mk2 / 86400);
        $tSpan = $n2 - $n1;

        if ($dowStart <= $dowEnd) {
            $nbjo = ((floor($tSpan / 7) * 5) + Max((Min(($dowEnd + 1), 6) - $dowStart), 0));
        } else {
            $nbjo = ((floor($tSpan / 7) * 5) + Min(($dowEnd + 6) - Min($dowStart, 6), 5));
        }
        return $nbjo;
    }

    function dateExcel($dateExcel /** format numérique */) /* return yyyy.mm.dd hh:mm:ss */
    {
        return date('Y.m.d H:i:s', ($dateExcel - 25569)*24*60*60);
    }

