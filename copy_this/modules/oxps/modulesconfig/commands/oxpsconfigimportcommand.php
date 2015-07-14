<?php
/**
 * This file is part of OXID Console.
 *
 * OXID Console is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OXID Console is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with OXID Console.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    OXID Professional services
 * @link      http://www.oxid-esales.com
 * @copyright (C) OXID eSales AG 2003-2015
 */

use Symfony\Component\Yaml\Yaml;

class OxpsConfigImportCommand extends oxConsoleCommand
{

    /**
     * @var oxConfig[]
     */
    protected $aConfigs;

    /**
     * Configuration loaded from file
     *
     * @var array
     */
    protected $aConfiguration;

    /**
     * @var oxOutput
     */
    protected $oDebugOutput;

    /**
     * @var string
     */
    protected $sEnv = null;

    /**
     * @var oxIOutput $oOutput
     */
    protected $oOutput;

    /**
     * @var string
     */
    protected $sNameForGeneralShopSettings = "GeneralShopSettings";

    protected $sNameForMetaData = "Meta";

    protected $aDefaultConfig;

    protected $aEnvConfig;

    /**
     * @var oxConfig $oConfig
     */
    protected $oConfig;
    protected $sShopId;
    protected $oModuleStateFixer;


    /**
     * {@inheritdoc}
     */
    public function configure()
    {
        $this->setName('config:import');
        $this->setDescription('Import shop config');
    }

    /**
     * {@inheritdoc}
     */
    public function help(oxIOutput $oOutput)
    {
        $oOutput->writeLn('Usage: config:import [options]');
        $oOutput->writeLn();
        $oOutput->writeLn('This command imports shop config');
        $oOutput->writeLn();
        $oOutput->writeLn('Available options:');
        $oOutput->writeLn('  -n, --no-debug     No debug output');
        $oOutput->writeLn('  -e, --env          Environment');
        $oOutput->writeLn('  -s, --shop         Shop');
    }

    /**
     * init
     *
     * @param oxIOutput $oOutput
     */
    protected function init($oOutput)
    {
        $this->oOutput = $oOutput;
        $oInput = $this->getInput();
        if ($oInput->hasOption(array('e', 'env'))) {
            $this->sEnv = $oInput->getOption(array('e', 'env'));
        } else {
            $this->sEnv = 'develop';
        }
        $this->initConfiguration();
        $this->setDebugOutput($oOutput);
    }

    /**
     * Execute current command
     *
     * @param oxIOutput $oOutput
     */
    public function execute(oxIOutput $oOutput)
    {
        $this->init($oOutput);
        // import environment specific config values

        $aMetaConfig = $this->readConfigValues($this->getShopsConfigFileName());
        $aShops = $aMetaConfig['shops'];
        foreach ($aShops as $sShop => $sFileName) {
            $this->runShopConfigImport($sShop, $sFileName);
        }

        $this->getDebugOutput()->writeLn("done");
    }

    protected function runShopConfigImport($sShop, $sRelativeFileName)
    {
        $this->oOutput->writeLn("Importing default config");
        $this->importConfigValues($sShop, $this->aDefaultConfig, true);

        $sFileName = $this->getConfigDir() . $sRelativeFileName;
        $this->importConfigFile($sShop, $sFileName);

        if ($this->sEnv) {
            $sEnvDirName = $this->getEnviromentConfigDir();
            $sFileName = $sEnvDirName . $sRelativeFileName;
            $this->importConfigFile($sShop, $sFileName);
        }
    }

    protected function importConfigFile($sShop, $sFileName)
    {
        $aResult = $this->readConfigValues($sFileName);

        $this->oOutput->writeLn("Importing shop config file $sFileName");
        $this->importConfigValues($sShop, $aResult);

    }

    protected function getEnviromentConfigDir()
    {
        $sDir = null;

        if ($this->sEnv) {
            $sDir = $this->aEnvConfig["dir"];
            if (!$sDir) {
                $sDir = $this->getConfigDir() . '/' . $this->sEnv;
            }
            if (!is_readable($sDir)) {
                $this->oOutput->writeLn('There is no such ' . $sDir . ' config dir. stopping');
                exit;
            }
        }

        return $sDir;
    }

    protected function getConfigDir()
    {
        return $this->aConfiguration['dir'];
    }

    protected function getShopsConfigFileName()
    {
        return $this->aConfiguration['dir'] . '/shops.' . $this->getFileExt();
    }

    protected function getFileExt()
    {
        return $this->getExportFormat();
    }

    protected function getExportFormat()
    {
        return $this->aConfiguration['type'];
    }

    /**
     * Read configuration from file
     * It is being done only once. It will be stored as object property
     *
     * @param string $sFileName Name/path to the config file, that configure this config ex/importer
     * @param null $sType
     * @return array|mixed
     * @throws Exception
     */
    protected function readConfigValues($sFileName, $sType = null)
    {
        if ($sType == null) {
            $sType = $this->aConfiguration['type'];
        }
        $sFileContent = file_get_contents($sFileName);
        //$sFileContent = utf8_encode($sFileContent);
        if ($sType == 'json') {
            $aResults = json_decode($sFileContent, true);
            $error = json_last_error();
            if ($error !== JSON_ERROR_NONE) {
                throw new Exception("invalid JSON in $sFileName $error");
            }
        } elseif ($sType == 'yaml') {
            $aResults = Yaml::parse($sFileContent);
        } else {
            throw new Exception("unsuported config type" . $sType);
        }

        return $aResults;
    }

    /**
     * Init configuration from file
     *
     * It is being done only once. It will be stored as object property
     * after first call and will return it.
     *
     */
    protected function initConfiguration()
    {
        $sCommandsDir = dirname(__DIR__) .
            DIRECTORY_SEPARATOR . 'commands';
        if ($this->aConfiguration === null) {
            $this->aConfiguration = require $sCommandsDir . DIRECTORY_SEPARATOR . 'oxpsconfig.php';
        }

        $aAllEnvConfigs = $this->aConfiguration['env'];
        $aEnvFields = $this->aConfiguration['envFields'];
        foreach ($aEnvFields as $sExcludeField) {
            $this->aConfiguration['excludeFields'][] = $sExcludeField;
        }
        $sFilename = $sCommandsDir . DIRECTORY_SEPARATOR . 'defaultconfig' . DIRECTORY_SEPARATOR . 'defaults.yaml';
        $this->aDefaultConfig = $this->readConfigValues($sFilename, 'yaml');
        $aEnvConfig = $aAllEnvConfigs[$this->sEnv];
        $this->aEnvConfig = $aEnvConfig;
    }

    /**
     * @param oxIOutput $oOutput
     */
    protected function setDebugOutput(oxIOutput $oOutput)
    {
        $oDebugOutput = $this->getInput()->hasOption(array('n', 'no-debug')) ? oxNew('oxNullOutput') : $oOutput;
        $this->oDebugOutput = $oDebugOutput;
    }

    /**
     * @return oxIOutput
     */
    protected function getDebugOutput()
    {
        return $this->oDebugOutput;
    }

    /*
     * @param string $sShopId
     * @param array $aConfigValues
     * @param bool $blRestoreModuleDefaults
     */
    protected function importConfigValues($sShopId, $aConfigValues, $blRestoreModuleDefaults = false)
    {

        $oConfig = oxSpecificShopConfig::get($sShopId);
        $this->oConfig = $oConfig;
        $this->sShopId = $sShopId;

        /** @var oxModuleStateFixer $oModuleStateFixer */
        $oModuleStateFixer = oxRegistry::get('oxModuleStateFixer');
        $oModuleStateFixer->setConfig($oConfig);
        $this->oModuleStateFixer = $oModuleStateFixer;


        $oxModuleList = oxNew('oxModuleList');
        //only do the following on the first run when $blRestoreModuleDefaults = true
        if($blRestoreModuleDefaults) {
            //this will scan the module directory and add all modules (module paths),
            //that is something fixstates does not do.
            //this must be done before aDisabledModules is restored because this function deaktivate modules
            /**
             * @var oxModuleList $oxModuleList
             */
            $oxModuleList->getModulesFromDir(oxRegistry::getConfig()->getModulesDir());

            $aModules = $oxModuleList->getList();
            foreach($aModules as $sModuleId => $oModule) {
                // restore default module settings
                /** @var oxModule $oModule */
                $aDefaultModuleSettings = $oModule->getInfo("settings");
                foreach ($aDefaultModuleSettings as $aValue) {
                    $sVarName = $aValue["name"];
                    $mVarValue = $aValue["value"];
                    $this->oConfig->setConfigParam($sVarName, $mVarValue);
                }
            }
        }

        $aModuleVersions = [];

        $aGeneralSettings = $aConfigValues[$this->sNameForGeneralShopSettings];
        $sSectionModule = '';
        foreach ($aGeneralSettings as $sVarName => $mVarValue) {
            if ($sVarName == 'aModules') {
                $aModulesTmp = [];
                foreach ($mVarValue as $sBaseClass => $aClassNames) {
                    $sAmpSeparatedClassNames = join('&', $aClassNames);
                    $aModulesTmp[$sBaseClass] = $sAmpSeparatedClassNames;
                }
                $mVarValue = $aModulesTmp;
            } elseif ($sVarName == 'aModuleVersions') {
                $aModuleVersions = $mVarValue;
                continue;
            }
            $this->saveShopVar($sVarName, $mVarValue, $sSectionModule);
        }

        $this->importModuleConfig($aConfigValues['module']);

        $this->importThemeConfig($aConfigValues['theme'], $sShopId);

        /** @var oxModule $oModule */
        $oModule = oxNew('oxModule');
        foreach ($aModuleVersions as $sModuleId => $sVersion) {
            if (!$oModule->load($sModuleId)) {
                $this->oOutput->writeLn("[ERROR] {$sModuleId} does not exist - skipping");
                continue;
            }
            //save imported config and fix state
            $oModuleStateFixer->fix($oModule);

            //execute activate event
            if ($this->aConfiguration['executeModuleActivationEvents'] && $oModule->isActive()) {
                $oModuleStateFixer->activate($oModule);
            }
            $sCurrentVersion = $oModule->getInfo("version");
            if ($sCurrentVersion != $sVersion) {
                $this->oOutput->writeLn("[ERROR] {$sModuleId} version on export" .
                    " $sVersion vs current version $sCurrentVersion");
            }
        }

    }


    protected function getTypeAndValue($sVarName, $mVarValue)
    {
        if ($this->is_assoc_array($mVarValue)) {
            $iCount = count($mVarValue);
            if ($iCount == 0) {
                $sVarType = 'arr';
            } elseif ($iCount > 1) {
                $sVarType = 'aarr';
            } else {
                $sVarType = key($mVarValue);
                $mVarValue = $mVarValue[$sVarType];
            }
        } elseif (is_array($mVarValue)) {
            $sVarType = 'arr';
        } else {
            if (substr($sVarName, 0, 2) === "bl") {
                $sVarType = 'bool';
            } else {
                $sVarType = 'str';
            }
        }
        return array($sVarType, $mVarValue);
    }

    protected function saveShopVar($sVarName, $mVarValue, $sSectionModule)
    {
        $sShopId = $this->sShopId;
        $oConfig = $this->oConfig;

        list($sVarType, $mVarValue) = $this->getTypeAndValue($sVarName, $mVarValue);

        $oConfig->saveShopConfVar(
            $sVarType,
            $sVarName,
            $mVarValue,
            $sShopId,
            $sSectionModule
        );
    }


    protected function is_assoc_array($arr)
    {
        return is_array($arr) && (array_keys($arr) !== range(0, count($arr) - 1));
    }

    /**
     * @param array $aSectionData
     * @param oxModule $oModule
     * @return array
     */
    protected function importModuleConfig($aModules)
    {
        /** @var oxModule $oModule */
        $oModule = oxNew('oxModule');
        if ($aModules == null) {
            return;
        }
        foreach ($aModules as $sModuleId => $aModuleSettings) {
            if (!$oModule->load($sModuleId)) {
                $this->oOutput->writeLn("[ERROR] {$sModuleId} does not exist - skipping");
                continue;
            }
            // set module config values that are explicitly included in this import
            foreach ($aModuleSettings as $sVarName => $mVarValue) {
                $this->oConfig->setConfigParam($sVarName, $mVarValue);
            }
        }
    }

    /**
     * @param $aSectionData
     * @param $sShopId
     * @param $oConfig
     */
    protected function importThemeConfig($aThemes)
    {
        if ($aThemes == null) {
            return;
        }
        foreach ($aThemes as $sThemeId => $aSettings) {
            $sSectionModule = "theme:$sThemeId";
            foreach ($aSettings as $sVarName => $mVarValue) {
                $this->saveShopVar($sVarName, $mVarValue, $sSectionModule);
            }
        }
    }

}