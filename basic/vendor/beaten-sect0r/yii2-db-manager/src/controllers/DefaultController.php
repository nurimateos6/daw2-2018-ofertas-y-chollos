<?php

namespace bs\dbManager\controllers;

/*incluir*///include __DIR__ . '/zip2.php';
use Yii;
use yii\data\ArrayDataProvider;
use yii\filters\VerbFilter;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\StringHelper;
use yii\web\Controller;
use bs\dbManager\models\Dump;
use bs\dbManager\models\Restore;
use Symfony\Component\Process\Process;

use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

//use zip;

/**
 * Default controller.
 */
class DefaultController extends Controller
{
    /**
     * @return Module
     */
    public function getModule()
    {
        return $this->module;
    }

    /**
     * @return array
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'create' => ['post'],
                    'delete' => ['post'],
                    'delete-all' => ['post'],
                    'restore' => ['get', 'post'],
                    '*' => ['get'],
                ],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function actionIndex()
    {
        $dataArray = $this->prepareFileData();
        $dbList = $this->getModule()->dbList;
        $model = new Dump($dbList, $this->getModule()->customDumpOptions);
        $dataProvider = new ArrayDataProvider([
            'allModels' => $dataArray,
            'pagination' => [
                'pageSize' => 30,
            ],
        ]);
        $activePids = $this->checkActivePids();

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'model' => $model,
            'dbList' => $dbList,
            'activePids' => $activePids,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function actionCreate()
    {
        $model = new Dump($this->getModule()->dbList, $this->getModule()->customDumpOptions);
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $dbInfo = $this->getModule()->getDbInfo($model->db);
            $dumpOptions = $model->makeDumpOptions();
            $manager = $this->getModule()->createManager($dbInfo);
            $dumpPath = $manager->makePath($this->getModule()->path, $dbInfo, $dumpOptions);
            $dumpCommand = $manager->makeDumpCommand($dumpPath, $dbInfo, $dumpOptions);
            Yii::trace(compact('dumpCommand', 'dumpPath', 'dumpOptions'), get_called_class());
            if ($model->runInBackground) {
                $this->runProcessAsync($dumpCommand);
            } else {
                $this->runProcess($dumpCommand);
            }
        } else {
            Yii::$app->session->setFlash('error', Yii::t('dbManager', 'Respuesta invalida.') . '<br>' . Html::errorSummary($model));
        }
        $this->zipping();
		//Zip::create_zip();
        return $this->redirect(['index']);
    }

	public function agregar_zip($dir, $zip) 
	{
		//verificamos si $dir es un directorio
		if (is_dir($dir)) {
		//abrimos el directorio y lo asignamos a $da
			if ($da = opendir($dir)) {
		//leemos del directorio hasta que termine
				while (($archivo = readdir($da)) !== false) {
        /*Si es un directorio imprimimos la ruta
         * y llamamos recursivamente esta función
         * para que verifique dentro del nuevo directorio
         * por mas directorios o archivos
         */
					if (is_dir($dir . DIRECTORY_SEPARATOR . $archivo) && $archivo != "." && $archivo != "..") {
						self::agregar_zip2($dir . DIRECTORY_SEPARATOR . $archivo , $zip);

						/*si encuentra un archivo imprimimos la ruta donde se encuentra
						* y agregamos el archivo al zip junto con su ruta 
						*/
					}elseif (is_file($dir . DIRECTORY_SEPARATOR . $archivo) && $archivo != "." && $archivo != "..") {

						$zip->addFile($dir . DIRECTORY_SEPARATOR . $archivo, $archivo);
					}
				}
				//cerramos el directorio abierto en el momento
				closedir($da);
			}
		}
	}
    public function agregar_zip2($dir, $zip) 
    {
        //verificamos si $dir es un directorio
        if (is_dir($dir)) {
            $ruta = explode(DIRECTORY_SEPARATOR, $dir);
            $r = end( $ruta );
            
        //abrimos el directorio y lo asignamos a $da
            if ($da = opendir($dir)) {
        //leemos del directorio hasta que termine
                while (($archivo = readdir($da)) !== false) {
        /*Si es un directorio imprimimos la ruta
         * y llamamos recursivamente esta función
         * para que verifique dentro del nuevo directorio
         * por mas directorios o archivos
         */
                    if (is_dir($dir . DIRECTORY_SEPARATOR . $archivo) && $archivo != "." && $archivo != "..") { echo $archivo;

                        self::agregar_zip2($dir . DIRECTORY_SEPARATOR . $archivo , $zip);

                        /*si encuentra un archivo imprimimos la ruta donde se encuentra
                        * y agregamos el archivo al zip junto con su ruta 
                        */
                    }elseif (is_file($dir . DIRECTORY_SEPARATOR . $archivo) && $archivo != "." && $archivo != "..") {

                        $zip->addFile($dir . DIRECTORY_SEPARATOR . $archivo, $r . DIRECTORY_SEPARATOR. $archivo);
                    }
                }
                //cerramos el directorio abierto en el momento
                closedir($da);
            }
        }
    }
	public function zipping()
	{

		//creamos una instancia de ZipArchive
		$zip = new ZipArchive();

		/*directorio a comprimir
		* la barra inclinada al final es importante
		* la ruta debe ser relativa no absoluta
		*/
		$dir = Yii::getAlias('@webroot').'\imagenes';

		//ruta donde guardar los archivos zip, ya debe existir
		$rutaFinal = Yii::getAlias('@app').'\backups';

		if(!file_exists($rutaFinal)){
			mkdir($rutaFinal);
		}

        $archivoZip =$rutaFinal. DIRECTORY_SEPARATOR . "imagenes". date('Y-m-d_H-i-s') . ".zip";


        if ($zip->open($archivoZip, ZIPARCHIVE::CREATE | ZIPARCHIVE::OVERWRITE) === true) { 
            $this->agregar_zip($dir, $zip);

            $zip->close();


		}
	}
    
    /**
     * @inheritdoc
     */
    public function actionDownload($id)
    {
        $dumpPath = $this->getModule()->path . StringHelper::basename(ArrayHelper::getValue($this->getModule()->getFileList(), $id));

        return Yii::$app->response->sendFile($dumpPath);
    }

    /**
     * @inheritdoc
     */
    public function actionRestore($id)
    {
        $dumpFile = $this->getModule()->path . StringHelper::basename(ArrayHelper::getValue($this->getModule()->getFileList(), $id));
        $model = new Restore($this->getModule()->dbList, $this->getModule()->customRestoreOptions);
        if (Yii::$app->request->isPost) {
            if ($model->load(Yii::$app->request->post()) && $model->validate()) {
                $dbInfo = $this->getModule()->getDbInfo($model->db);
                $restoreOptions = $model->makeRestoreOptions();
                $manager = $this->getModule()->createManager($dbInfo);
                $restoreCommand = $manager->makeRestoreCommand($dumpFile, $dbInfo, $restoreOptions);
                Yii::trace(compact('restoreCommand', 'dumpFile', 'restoreOptions'), get_called_class());
                if ($model->runInBackground) {
                    $this->runProcessAsync($restoreCommand, true);
                } else {
                    $this->runProcess($restoreCommand, true);
                }

                return $this->redirect(['index']);
            }
        }
		$this->restaurarImagenes();
        return $this->render('restore', [
            'model' => $model,
            'file' => $dumpFile,
            'id' => $id,
        ]);
    }

    /**
     * @inheritdoc
     */
	 
	public function restaurarImagenes()
	{
		//exec('compact /u'. Yii::getAlias('@app').'\backups\imagenes.zip');
		$archivo = Yii::getAlias('@app').'\backups\imagenes.zip';
		$destino = Yii::getAlias('@webroot').'\imagenes';
		$zip = new zipArchive;
		if($zip->open($archivo)===TRUE)
		{
			$zip->extractTo($destino);
			$zip->close();
			echo 'OK';
		}
		else
		{
			echo 'Fail';
		}
	}
    public function actionStorage($id)
    {
        if (Yii::$app->has('backupStorage')) {
            $dumpname = StringHelper::basename(ArrayHelper::getValue($this->getModule()->getFileList(), $id));
            $dumpPath = $this->getModule()->path . $dumpname;
            $exists = Yii::$app->backupStorage->has($dumpname);
            if ($exists) {
                Yii::$app->backupStorage->delete($dumpname);
                Yii::$app->session->setFlash('success', Yii::t('dbManager', 'Copia borrada del almacenamiento.'));
            } else {
                $stream = fopen($dumpPath, 'r+');
                Yii::$app->backupStorage->writeStream($dumpname, $stream);
                Yii::$app->session->setFlash('success', Yii::t('dbManager', 'Copia subida al almacenamiento.'));
            }
        }
		$this->restaurarImagenes();
        return $this->redirect(['index']);
    }

    /**
     * @inheritdoc
     */
    public function actionDelete($id)
    {
        $dumpFile = $this->getModule()->path . StringHelper::basename(ArrayHelper::getValue($this->getModule()->getFileList(), $id));
        if (unlink($dumpFile)) {
            Yii::$app->session->setFlash('success', Yii::t('dbManager', 'Copia eliminada correctamente.'));
        } else {
            Yii::$app->session->setFlash('error', Yii::t('dbManager', 'Error al eliminar la copia.'));
        }

        return $this->redirect(['index']);
    }

    /**
     * @inheritdoc
     */
    public function actionDeleteAll()
    {
        if (!empty($this->getModule()->getFileList())) {
            $fail = [];
            foreach ($this->getModule()->getFileList() as $file) {
                if (!unlink($file)) {
                    $fail[] = $file;
                }
            }
            if (empty($fail)) {
                Yii::$app->session->setFlash('success', Yii::t('dbManager', 'Copia eliminadas correctamente.'));
            } else {
                Yii::$app->session->setFlash('error', Yii::t('dbManager', 'Error al eliminar las copias.'));
            }
        }

        return $this->redirect(['index']);
    }

    /**
     * @param $command
     * @param bool $isRestore
     */
    protected function runProcess($command, $isRestore = false)
    {
        $process = new Process($command);
        $process->run();
        if ($process->isSuccessful()) {
            $msg = (!$isRestore) ? Yii::t('dbManager', 'Copia creada correctamente.') : Yii::t('dbManager', 'Copia restaurada correctamente.');
            Yii::$app->session->addFlash('success', $msg);
        } else {
            $msg = (!$isRestore) ? Yii::t('dbManager', 'Copia fallida.') : Yii::t('dbManager', 'Restauración fallida.');
            Yii::$app->session->addFlash('error', $msg . '<br>' . 'Command - ' . $command . '<br>' . $process->getOutput() . $process->getErrorOutput());
            Yii::error($msg . PHP_EOL . 'Command - ' . $command . PHP_EOL . $process->getOutput() . PHP_EOL . $process->getErrorOutput());
        }
    }

    /**
     * @param $command
     * @param bool $isRestore
     */
    protected function runProcessAsync($command, $isRestore = false)
    {
        $process = new Process($command);
        $process->start();
        $pid = $process->getPid();
        $activePids = Yii::$app->session->get('backupPids', []);
        if (!$process->isRunning()) {
            if ($process->isSuccessful()) {
                $msg = (!$isRestore) ? Yii::t('dbManager', 'Dump successfully created.') : Yii::t('dbManager', 'Dump successfully restored.');
                Yii::$app->session->addFlash('success', $msg);
            } else {
                $msg = (!$isRestore) ? Yii::t('dbManager', 'Dump failed.') : Yii::t('dbManager', 'Restore failed.');
                Yii::$app->session->addFlash('error', $msg . '<br>' . 'Command - ' . $command . '<br>' . $process->getOutput() . $process->getErrorOutput());
                Yii::error($msg . PHP_EOL . 'Command - ' . $command . PHP_EOL . $process->getOutput() . PHP_EOL . $process->getErrorOutput());
            }
        } else {
            $activePids[$pid] = $command;
            Yii::$app->session->set('backupPids', $activePids);
            Yii::$app->session->addFlash('info', Yii::t('dbManager', 'Process running with pid={pid}', ['pid' => $pid]) . '<br>' . $command);
        }
    }

    /**
     * @return array
     */
    protected function checkActivePids()
    {
        $activePids = Yii::$app->session->get('backupPids', []);
        $newActivePids = [];
        if (!empty($activePids)) {
            foreach ($activePids as $pid => $cmd) {
                $process = new Process('ps -p ' . $pid);
                $process->run();
                if (!$process->isSuccessful()) {
                    Yii::$app->session->addFlash('success',
                        Yii::t('dbManager', 'Process complete!') . '<br> PID=' . $pid . ' ' . $cmd);
                } else {
                    $newActivePids[$pid] = $cmd;
                }
            }
        }
        Yii::$app->session->set('backupPids', $newActivePids);

        return $newActivePids;
    }

    /**
     * @return array
     */
    protected function prepareFileData()
    {
        foreach ($this->getModule()->getFileList() as $id => $file) {
            $columns = [];
            $columns['id'] = $id;
            $columns['type'] = pathinfo($file, PATHINFO_EXTENSION);
            $columns['name'] = StringHelper::basename($file);
            $columns['size'] = Yii::$app->formatter->asSize(filesize($file));
            $columns['create_at'] = Yii::$app->formatter->asDatetime(filectime($file));
            $dataArray[] = $columns;
        }
        ArrayHelper::multisort($dataArray, ['create_at'], [SORT_DESC]);

        return $dataArray;
    }
}
