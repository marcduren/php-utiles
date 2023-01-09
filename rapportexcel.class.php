<?php
use Vtiful\Kernel\Excel;
use Vtiful\Kernel\Format;

class RapportExcel
{
    public $excel = null;
    public $colonnes = array(); // [id=>'code',libelle=>'libellé',lg=>10]
    public $premiereLigne = 1;
    public $ligne = 2;

    public function __construct($fichier = 'rapport.xlsx', $classeur='Feuille 1', $gridline=Excel::GRIDLINES_SHOW_ALL)
    {
        $config = ['path' => '/tmp'];
        $this->excel  = new Excel($config);
        $this->excel = $this->excel->fileName($fichier, $classeur);
        $this->excel->setPaper(Excel::PAPER_A4);
        $this->excel->gridline($gridline)->setPrintScale(80)->setLandscape()->setMargins(1, 1, 1, 1);
    }
    public function format()
    {
        return new Format($this->excel->getHandle());
    }
    public function titre($titre, $logo)
    {
        $titreStyle = $this->format()->fontSize(16)->align(Format::FORMAT_ALIGN_VERTICAL_CENTER)->toResource();
        $this->excel->insertText(0, 1, $titre, "", $titreStyle);
        $this->excel->setRow('A1', 50);
        if ($logo > "") {
            $this->excel->insertImage(0, 0, $logo, 0.5, 0.5);
        }
    }
    public function colonnes($colonnes, $hauteurLigne = 40)
    {
        /** $colonnes = array(
           ['id'=>'ID1','libelle'=>'libellé 1','lg'=>20],
           ['id'=>'ID2','libelle'=>'libellé 2','lg'=>30],
           .....);
        ) */
        $colonneStyle  = $this->format()->background(0xeeeeee)
        ->align(Format::FORMAT_ALIGN_CENTER, Format::FORMAT_ALIGN_VERTICAL_CENTER)
        ->toResource();
        $this->colonnes = $colonnes;
        for ($c=0;$c<count($colonnes);$c++) {
            $col = $colonnes[$c];
            $sCol = Excel::stringFromColumnIndex($c);
            $this->excel->setColumn($sCol.':'.$sCol, $col['lg']);
            $this->excel->insertText($this->premiereLigne, $c, $col['libelle'], '', $colonneStyle);
        }
        $this->excel->setRow('A'.($this->premiereLigne+1), $hauteurLigne);
    }
    public function ajouterLigne($ligne, $hauteurLigne = 20, $style=null)/** $ligne = array('ID1'=>'valeur 1','ID2'=>'valeur 2',...);*/
    {
        if ($ligne != null) {
        for ($c=0;$c<count($ligne);$c++) {
            $colId = $this->colonnes[$c]['id'];
                if (substr($ligne[$colId], 0, 1) == "=") {
                    $this->excel->insertFormula($this->ligne, $c, $ligne[$colId]);
                } else {
                    $this->excel->insertText($this->ligne, $c, $ligne[$colId],'');
                }
            }
            if ($style != null) {
                $this->excel->setRow('A'.($this->ligne+1), $hauteurLigne, $style);
            } else {
                $this->excel->setRow('A'.($this->ligne+1), $hauteurLigne);
            }
        }
        $this->ligne++;
        return $this->ligne - 1;
    }
    public function nouvelleFeuille($nom)
    {
        $this->excel->addSheet($nom);
        $this->ligne = 2;
    }
    public function outPut()
    {
        $this->excel->output();
    }
    public function download()
    {
        $filePath = $this->excel->output();
        header('Content-Disposition: attachment; filename='.basename($filePath));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Length: '.filesize($filePath));
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        flush();
        readfile($filePath);
        @unlink($filePath);
    }
}
