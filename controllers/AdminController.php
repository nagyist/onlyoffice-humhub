<?php

/**
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2017 HumHub GmbH & Co. KG
 * @license https://www.humhub.com/licences
 */

/**
 *  Copyright (c) Ascensio System SIA 2022. All rights reserved.
 *  http://www.onlyoffice.com
 */

namespace humhub\modules\onlyoffice\controllers;

use Yii;
use humhub\modules\file\models\File;
use humhub\modules\onlyoffice\models\ConfigureForm;
use humhub\modules\admin\components\Controller;
use yii\helpers\Url;

class AdminController extends Controller
{
    /**
     * @var Module
     */
    public $module;

    public function actionIndex()
    {
        $this->module = Yii::$app->getModule('onlyoffice');
        $model = new ConfigureForm();
        $model->loadSettings();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            $this->view->saved();
        }

        $trial = $this->getTrial();
        $serverApiUrl = $this->module->getServerApiUrl();

        list($error, $version) = $this->validation();

        return $this->render('index', [
                                        'model' => $model,
                                        'serverApiUrl' => $serverApiUrl,
                                        'error' => $error,
                                        'version' => $version,
                                        'trial' => $trial
                                      ]);
    }

    private function validation()
    {
        $version = null;

        if (!$this->checkValidHttps()) {
            return [Yii::t('OnlyofficeModule.base', 'Mixed Active Content is not allowed. HTTPS address for ONLYOFFICE Docs is required.'), $version];
        }

        $command = $this->module->commandService(['c' => 'version']);
        if (isset($command['version'])) {
            $version = $command['version'];
        } else {
            return [$command['error'], $version];
        }

        $status = $this->getServerStatus();
        if (isset($status['error'])) {
            return [$status['error'], $version];
        }

        $convert = $this->checkConvertFile();
        if (isset($convert['error'])) {
            return [$convert['error'], $version];
        }

        return ["", $version];
    }

    private function getServerStatus()
    {
        $url = $this->module->getInternalServerUrl() . '/healthcheck';

        try {
            $healthcheck = $this->module->request($url)->getContent();
            if ($healthcheck !== 'true') {
                throw new \Exception('Bad healthcheck status');
            }
        } catch (\Exception $ex) {
            Yii::error('ServerStatus: ' . $ex->getMessage());
            return ['error' => Yii::t('Bad healthcheck status')];
        }

        return [];
    }
    private function getTrial()
    {
        $module = Yii::$app->getModule('onlyoffice');
        if($module->isDemoServerEnabled())
            return $module->getTrial();
        return -1;
    }

    private function checkValidHttps()
    {
        $serverUrl = $this->module->getServerUrl();
        $baseUrl = Url::base(true);

        if ((substr($baseUrl, 0, strlen("https:")) === "https:")
            && (substr($serverUrl, 0, strlen("http:")) === "http:")) {
            return false;
        }

        return true;
    }

    private function checkConvertFile()
    {
        $user = Yii::$app->user->getIdentity();
        $userGuid = null;
        if (isset($user->guid)) {
            $userGuid = $user->guid;
        }

        $docHash = $this->module->generateHash(null, $userGuid, true);

        $downloadUrl = Url::to(['/onlyoffice/backend/empty-file', 'doc' => $docHash], true);
        if (!empty($this->module->getStorageUrl())) {
            $downloadUrl = $this->module->getStorageUrl() . Url::to(['/onlyoffice/backend/empty-file', 'doc' => $docHash], false);
        }

        $key = substr(strtolower(md5(Yii::$app->security->generateRandomString(20))), 0, 20);

        $result = $this->module->convertService($downloadUrl, "docx", "docx", $key, false);
        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        try {
        $this->module->request($result['fileUrl']);
        } catch (\Exception $ex) {
            Yii::error('CheckConvertFile: ' . $ex->getMessage());
            return ['error' => $ex->getMessage()];
        }

        return [];
    }
}
