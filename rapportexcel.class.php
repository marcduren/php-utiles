<?php
use Vtiful\Kernel\Excel;
use Vtiful\Kernel\Format;

class RapportExcel
{
    public $excel = null;
    public $fileName = "rapport.xlsx";
    public $fileObject;
    public $fileHandle;
    public $colonnes = array(); // [id=>'code',libelle=>'libellé',lg=>10]
    public $premiereLigne = 1;
    public $ligne = 2;

    public function __construct($fichier = 'rapport.xlsx', $classeur='Feuille 1', $gridline=\Vtiful\Kernel\Excel::GRIDLINES_SHOW_ALL)
    {
        $config = ['path' => '/tmp'];
        $this->excel  = new \Vtiful\Kernel\Excel($config);
        $this->fileName = $fichier;
        $this->fileObject = $this->excel->fileName($fichier, $classeur);
        $this->fileHandle = $this->fileObject->getHandle();
        $this->fileObject->gridline($gridline);
    }
    public function titre($titre, $logo)
    {
        $ft = new \Vtiful\Kernel\Format($this->fileHandle);
        $titreStyle = $ft->fontSize(16)->align(\Vtiful\Kernel\Format::FORMAT_ALIGN_VERTICAL_CENTER)->toResource();
        $this->fileObject->insertText(0, 1, $titre, "", $titreStyle);
        $this->fileObject->setRow('A1', 50);
        if ($logo > "") {
            $this->fileObject->insertImage(0, 0, $logo, 0.5, 0.5);
        }
    }
    public function colonnes($colonnes, $hauteurLigne = 20)
    {
        /** $colonnes = array(
           ['id'=>'ID1','libelle'=>'libellé 1','lg'=>20],
           ['id'=>'ID2','libelle'=>'libellé 2','lg'=>30],
           .....);
        ) */
        $this->colonnes = $colonnes;
        $ft = new \Vtiful\Kernel\Format($this->fileHandle);
        $backgroundStyle  = $ft->background(0xeeeeee)->toResource();
        $this->fileObject->setRow('A2', $hauteurLigne, $backgroundStyle);

        for ($c=0;$c<count($colonnes);$c++) {
            $col = $colonnes[$c];
            $sCol = \Vtiful\Kernel\Excel::stringFromColumnIndex($c);
            $this->fileObject->setColumn($sCol.':'.$sCol, $col['lg']);
            $this->fileObject->insertText($this->premiereLigne, $c, $col['libelle'], "");
        }
        //$this->fileObject->setRow('A2', 40); // hauteur entete colonnes
    }
    public function ajouterLigne($ligne)
    {
        /** $ligne = array('ID1'=>'valeur 1','ID2'=>'valeur 2',...);
         *
         */
        for ($c=0;$c<count($ligne);$c++) {
            $colId = $this->colonnes[$c]['id'];
            $this->fileObject->insertText($this->ligne, $c, $ligne[$colId]);
        }
        $this->ligne++;
    }
    public function nouvelleFeuille($nom)
    {
        $this->fileObject->addSheet($nom);
        $this->ligne = 2;
    }
    public function outPut()
    {
        $this->fileObject->output();
    }
    public function download()
    {
        $filePath = $this->fileObject->output();
        header('Content-Disposition: attachment; filename='.basename($filePath));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Length: '.filesize($filePath));
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        flush();
        if (copy($filePath, 'php://output') === false) {
            // Throw exception
        }

        // Delete temporary file
        @unlink($filePath);
    }
}
