<?php
/*
 * Copyright 2015 Centreon (http://www.centreon.com/)
 * 
 * Centreon is a full-fledged industry-strength solution that meets 
 * the needs in IT infrastructure and application monitoring for 
 * service performance.
 * 
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 * 
 *    http://www.apache.org/licenses/LICENSE-2.0  
 * 
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * 
 * For more information : contact@centreon.com
 * 
 */

namespace CentreonBroker\Repository;

use Centreon\Internal\Di;
use Centreon\Internal\Db as CentreonDb;
use Centreon\Internal\Exception;
use CentreonConfiguration\Models\Poller;
use CentreonBroker\Models\Broker;
use CentreonBroker\Models\BrokerPollerValues;
use CentreonBroker\Repository\BrokerRepository;
use CentreonConfiguration\Events\BrokerModule as BrokerModuleEvent;
use CentreonConfiguration\Internal\Poller\Template\Manager as PollerTemplateManager;
use CentreonMain\Events\Generic as GenericEvent;

/**
 * Factory for generate Centron Broker configuration
 *
 * @author Maximilien Bersoult <mbersoult@centreon.com>
 * @version 3.0.0
 * @package CentreonBroker
 */
class ConfigGenerateRepository
{
    private $tmpPath;
    private $pollerId;
    private $paths = array();
    private $baseConfig = array();
    private $tplInformation = array();
    private $defaults = array();
    private $parsedDefault = array();

    /**
     * Construt
     */
    public function __construct()
    {
        $di = Di::getDefault();

        $this->tmpPath = $di->get('config')->get('global', 'centreon_generate_tmp_dir');

        if (!isset($this->tmpPath)) {
            throw new Exception('Temporary path not set');
        }
        $this->tmpPath = rtrim($this->tmpPath, '/') . '/broker/generate';

        /* Load defaults values */
        $this->defaults = json_decode(file_get_contents(dirname(__DIR__) . '/data/default.json'), true);
        if (is_null($this->defaults)) {
            throw new Exception("Bad json format for default values");
        }

        /* Create directories if they don't exist */
        if (!is_dir($this->tmpPath)) {
            mkdir($this->tmpPath);
        }
    }

    /**
     * Generate configuration files for Centreon Broker
     *
     * @param int $pollerId The poller id
     */
    public function generate($pollerId)
    {
        $dbconn = Di::getDefault()->get('db_centreon');
        $this->pollerId = $pollerId;

        if (!is_dir($this->tmpPath . '/' . $this->pollerId)) {
            mkdir($this->tmpPath . '/' . $this->pollerId);
        }
        /* Get poller template */
        $params = Poller::get($this->pollerId, 'tmpl_name');
        if (!isset($params['tmpl_name']) || is_null($params['tmpl_name'])) {
            throw new Exception('Not template defined');
        }
        $tmplName = $params['tmpl_name'];

        /* Load template information for poller */
        $listTpl = PollerTemplateManager::buildTemplatesList();
        if (!isset($listTpl[$tmplName])) {
            throw new Exception('The template is not found on list of templates');
        }
        $fileTplList = $listTpl[$tmplName]->getBrokerPath();
        //$this->tplInformation = json_decode(file_get_contents($fileTpl), true);

        $this->tplInformation = array();
        foreach ($fileTplList as $fileTpl) {
            $this->tplInformation = BrokerRepository::mergeBrokerConf($this->tplInformation, $fileTpl);
        }

        $this->loadMacros($pollerId);

        /* Get list of configuration files */
        $query = "SELECT config_id, config_name, flush_logs, write_timestamp, name, 
            write_thread_id, event_queue_max_size
            FROM cfg_centreonbroker c, cfg_pollers p
            WHERE c.poller_id = p.poller_id
            AND p.poller_id = :poller_id";
        $stmt = $dbconn->prepare($query);
        $stmt->bindParam(':poller_id', $pollerId, \PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchAll();

        foreach ($result as $row) {
            $this->baseConfig['%broker_name%'] = $row['config_name'];
            $this->baseConfig['%poller_name%'] = $row['name'];
            static::generateModule($row);
        }
    }

    /**
     * Gerenate configuration file for a module
     *
     * @param array $row The module information
     */
    private function generateModule($row)
    {
        $filename = $this->tmpPath . '/' . $this->pollerId . '/' . $row['config_name'] . '.xml';

        // store broker id
        $this->baseConfig['%broker_id%'] = $row['config_id'];

        $moduleInformation = $this->getInformationFromTpl($row['config_name']);

        $xml = new \XMLWriter();
        if (false === $xml->openURI($filename)) {
            throw new Exception('Error when create configuration file.');
        }
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('conf');

        foreach ($moduleInformation  as $module => $information) {
            if ($module == 'general') {
                /* Merge information with default */
                $configuration = $this->moduleGeneral($information, $row);
                $configuration = array_merge($this->defaults['general'], $configuration);
                $this->addModule($xml, $module, $configuration, true);
            } else {
                $nbGroup = 1;
                foreach ($information as $group) {
                    /* Merge information with default */
                    $configuration = $this->moduleBlock($row['config_id'], $module, $nbGroup, $group);
                    $default = $this->getDefaults($module, $group);
                    $configuration = array_merge($default, $configuration);
                    $this->addModule($xml, $module, $configuration);
                    $nbGroup++;
                }
            }
        }

        $xml->endElement();
        $xml->endDocument();
    }

    /**
     * Load information form the template
     *
     * @param string $name The name of the module
     * @return array
     */
    private function getInformationFromTpl($name)
    {
        foreach ($this->tplInformation['content']['broker']['setup'] as $setup) {
            foreach ($setup['params']['mode'] as $mode) {
                foreach ($mode as $type => $config) {
                    if ($type == 'normal') {
                        foreach ($config as $module) {
                            if ($module['general']['name'] == $name) {
                                return $module;
                            }
                        }
                    }
                }
            }
        }
        return array();
    }

    /**
     * Parse the general configuration
     *
     * @param array $info The information
     * @param array $row The Centreon Broker poller configuration
     * @return array 
     */
    private function moduleGeneral($info, $row)
    {
        /* Generate general */
        $listGeneralUser = array('flush_logs', 'write_timestamp', 'write_thread_id', 'event_queue_max_size');
        $generalConf = $info;
        foreach ($listGeneralUser as $info) {
            if (false === is_null($row[$info])) {
                $generalConf[$info] = $row[$info];
            }
        }
        return $generalConf;
    }

    /**
     * Parse a module block
     *
     * @param int $configId The configuration id
     * @param string $moduleType The module type
     * @param int $nbGroup The position in configuration
     * @param array $information The template information
     * @return array
     */
    private function moduleBlock($configId, $moduleType, $nbGroup, $information)
    {
        $dbconn = Di::getDefault()->get('db_centreon');
        /* Prepare general information */
        $defaultInformation = array();
        $blockConf = $information;
        /* Get user modification */
        $query = "SELECT config_key, config_value
            FROM cfg_centreonbroker_info
            WHERE config_id = :config_id
                AND config_group = :group
                AND config_group_id = :group_id";
        $stmt = $dbconn->prepare($query);
        $stmt->bindParam(':config_id', $configId, \PDO::PARAM_INT);
        $stmt->bindParam(':group', $moduleType, \PDO::PARAM_STR);
        $stmt->bindParam(':group_id', $nbGroup, \PDO::PARAM_INT);
        $stmt->execute();
        while ($row = $stmt->fetch()) {
            $blockConf[$row['config_key']] = $row['config_value'];
        }
        return $blockConf;
    }

    /**
     * Add a module to Centreon Broker configuration
     *
     * @param \XMLWriter $file The xml file
     * @param string $name The module type
     * @param array $configuration The configuration for the module
     * @param bool $isGeneral Is the module is the base configuration
     */
    private function addModule($file, $name, $configuration, $isGeneral = false)
    {
        if (false === $isGeneral) {
            $file->startElement($name);
        }
        foreach ($configuration as $key => $value) {
            if (is_array($value)) {
                if ($key == '%callback%') {
                    foreach ($value as $action) {
                        switch ($action) {
                            case 'pollerCommandLineCentreonEngine':
                                $this->addEngineCommandLineBlock($file);
                                break;
                            case 'pollerConfigCentreonEngine':
                                $this->addConfigCentreonEngineBlock($file);
                                break;
                            case 'pollerConfigCentreonBroker':
                                $this->addConfigCentreonBrokerBlock($file);
                                break;
                        }
                    }
                } else {
                    $valueIsString = false;
                    foreach (array_values($value) as $subvalue) {
                        if (is_string($subvalue)) {
                            $valueIsString = true;
                        }
                    }

                    if (!$valueIsString) {
                        $file->startElement($key);
                    }

                    foreach ($value as $subkey => $subvalue) {
                        if (is_array($subvalue)) {
                            foreach ($subvalue as $kkey => $vvalue) {
                                $vvalue = str_replace(
                                    array_keys($this->baseConfig),
                                    array_values($this->baseConfig),
                                    $vvalue
                                );
                                if (is_string($kkey)) {
                                    $file->writeElement($kkey, $vvalue);
                                }
                            }
                        } else {
                            $subvalue = str_replace(
                                array_keys($this->baseConfig),
                                array_values($this->baseConfig),
                                $subvalue
                            );
                            if (is_string($subkey)) {
                                $file->writeElement($subkey, $subvalue);
                            } else {
                                $file->writeElement($key, $subvalue);
                            }
                        }
                    }

                    if (!$valueIsString) {
                        $file->endElement();
                    }
                }
            } else {
                $value = str_replace(
                    array_keys($this->baseConfig),
                    array_values($this->baseConfig),
                    $value
                );
                $key = str_replace(
                    array_keys($this->baseConfig),
                    array_values($this->baseConfig),
                    $key
                );
                $key = str_replace(array('/','.', ' '), '-', $key);
                $file->writeElement($key, $value);
            }
        }
        if (false === $isGeneral) {
            $file->endElement();
        }
    }

    /**
     * Add block for external command line
     *
     * @param \XMLWriter $gile The xml file
     */
    private function addEngineCommandLineBlock($file)
    {
        /* Get broker modules list */
        $brokerModules = self::getBrokerModules();
        foreach ($brokerModules as $brokerModule) {
            $file->startElement("input");
            $file->writeElement("name", "central-broker-extcommands-engine-poller-module-" . $brokerModule['poller_id']);
            $file->writeElement("type", "dump_fifo");
            $file->writeElement("path", $this->baseConfig['%global_broker_data_directory%'] . "/central-broker-extcommands-engine-poller-module-" . $brokerModule['poller_id'] . ".cmd");
            $file->writeElement("tagname", "extcommands-engine-" . $brokerModule['poller_id']);
            $file->endElement();
        }
    }

    /**
     * Add block for send Centreon Engine configuration files
     *
     * @param \XMLWriter $gile The xml file
     */
    private function addConfigCentreonEngineBlock($file)
    {
        $db = Di::getDefault()->get('db_centreon');

        $sql = "DELETE FROM cfg_centreonbroker_pollervalues WHERE poller_id = ? and name = ?";
        $stmt = $db->prepare($sql);

        /* The path for generate configuration */
        $configGeneratePath = rtrim(Di::getDefault()->get('config')->get('global', 'centreon_generate_tmp_dir'), '/') . '/engine';
        /* Get broker modules list */
        $brokerModules = self::getBrokerModules();
        foreach ($brokerModules as $brokerModule) {
            $name = "central-broker-cfg-engine-poller-module-" . $brokerModule['poller_id'];
            $file->startElement("output");
                $file->writeElement("name", $name);
                $file->writeElement("type", "dump_dir");
                $file->writeElement("path", $configGeneratePath . '/apply/' . $brokerModule['poller_id']);
                $file->writeElement("tagname", "cfg-engine-" . $brokerModule['poller_id']);
                $file->startElement('read_filters');
                    $file->writeElement("category", "internal");
                $file->endElement();
            $file->endElement();
            $stmt->execute(array($brokerModule['poller_id'], 'dump_dir_engine'));
            BrokerPollerValues::insert(array('poller_id' => $brokerModule['poller_id'], 'name' => 'dump_dir_engine', 'value' => $name), true);
        }
    }

    /**
     * Add block for send Centreon Broker configuration files
     *
     * @param \XMLWriter $gile The xml file
     */
    private function addConfigCentreonBrokerBlock($file)
    {
        $db = Di::getDefault()->get('db_centreon');;

        $sql = "DELETE FROM cfg_centreonbroker_pollervalues WHERE poller_id = ? and name = ?";
        $stmt = $db->prepare($sql);

        /* The path for generate configuration */
        $configGeneratePath = rtrim(Di::getDefault()->get('config')->get('global', 'centreon_generate_tmp_dir'), '/') . '/broker';
        /* Get broker modules list */
        $brokerModules = self::getBrokerModules();
        foreach ($brokerModules as $brokerModule) {
            $name = "central-broker-cfg-broker-poller-module-" . $brokerModule['poller_id'];
            $file->startElement("output");
                $file->writeElement("name", $name);
                $file->writeElement("type", "dump_dir");
                $file->writeElement("path", $configGeneratePath . '/apply/' . $brokerModule['poller_id']);
                $file->writeElement("tagname", "cfg-broker-" . $brokerModule['poller_id']);
                $file->startElement('read_filters');
                    $file->writeElement("category", "internal");
                $file->endElement();
            $file->endElement();
            $stmt->execute(array($brokerModule['poller_id'], 'dump_dir_broker'));
            BrokerPollerValues::insert(array('poller_id' => $brokerModule['poller_id'], 'name' => 'dump_dir_broker', 'value' => $name), true);
        }
    }

    /**
     * Load macros for replace in default configuration
     *
     * @param int $pollerId The poller id
     */
    private function loadMacros($pollerId)
    {
        $config = Di::getDefault()->get('config');

        /* Load contant values */
        $this->baseConfig['broker_central_ip'] = getHostByName(getHostName());

        /* Load user value */
        $this->baseConfig = array_merge($this->baseConfig, BrokerRepository::loadValues($pollerId));

        /* Load paths */
        $paths = BrokerRepository::getPathsFromPollerId($pollerId);
        $pathsValue = array_values($paths);
        $pathsKeys = array_map(
            function($name) {
                switch ($name) {
                    case 'directory_modules':
                        $str = 'modules_directory';
                        break;
                    case 'directory_config':
                        $str = 'etc_directory';
                        break;
                    case 'directory_logs':
                        $str = 'logs_directory';
                        break;
                    case 'directory_data':
                        $str = 'data_directory';
                        break;
                    default:
                        $str = '';
                        break;
                }
                return 'global_broker_' . $str;
            },
            array_keys($paths)
        );
        $paths = array_combine($pathsKeys, $pathsValue);
        $this->baseConfig = array_merge($this->baseConfig, $paths);
        $this->baseConfig['poller_id'] = $this->pollerId;

        /* Information for database */
        $dbInformation = CentreonDb::parseDsn(
            $config->get('db_centreon', 'dsn'),
            $config->get('db_centreon', 'username'),
            $config->get('db_centreon', 'password')
        );
        $dbKeys = array_map(
            function($name) {
                return 'global_' . $name;
            },
            array_keys($dbInformation)
        );
        $dbInformation = array_combine($dbKeys, array_values($dbInformation));
        $this->baseConfig = array_merge($dbInformation, $this->baseConfig);

        /* Load general poller information */
        $pollerInformation = Poller::get($pollerId);
        $this->baseConfig['poller_name'] = $pollerInformation['name'];

        /* Load configuration information from Centren Engine */
        $eventObj = new GenericEvent(array('poller_id' => $pollerId));
        Di::getDefault()->get('events')->emit('centreon-broker.poller.configuration', array($eventObj));
        $this->baseConfig = array_merge($eventObj->getOutput(), $this->baseConfig);
        
        /* get global value in database */
        $globalOptions = BrokerRepository::getGlobalValues();
        $this->baseConfig = array_merge($globalOptions, $this->baseConfig);
        
        /* Add % in begin and end of keys */
        $keys = array_keys($this->baseConfig);
        $values = array_values($this->baseConfig);
        $keys = array_map(
            function($key) {
                return '%' . $key . '%';
            },
            $keys
        );
        $this->baseConfig = array_combine($keys, $values);
    }

    /**
     * Prepare default values by module and group
     *
     * @param string $module The module
     * @param string $group The information of current group
     * @return array
     */
    private function getDefaults($module, $group)
    {
        if (isset($this->parsedDefault[$module])) {
            if (false === $group['type']) {
                return $this->parsedDefault[$module];
            } elseif (isset($this->parsedDefault[$module][$group['type']])) {
                return $this->parsedDefault[$module][$group['type']];
            }
        }
        if (false === isset($this->defaults[$module])) {
            return array();
        }
        $values = array();
        foreach ($this->defaults[$module] as $key => $value) {
            if ($key != 'type') {
                $values[$key] = $value;
            } else {
                if (isset($group['type']) && isset($value[$group['type']])) {
                    foreach ($value[$group['type']] as $keyType => $valueType) {
                        $values[$keyType] = $valueType;
                    }
                }
            }
        }
        if (false === isset($this->parsedDefault[$module])) {
            $this->parsedDefault[$module] = array();
        }
        if (isset($group['type'])) {
            $this->parsedDefault[$module][$group['type']] = $values;
        } else {
            $this->parsedDefault[$module] = $values;
        }
        return $values;
    }

    /**
     * Get broker ids of poller modules
     *
     * @return array
     */
    public function getBrokerModules()
    {
        $dbconn = Di::getDefault()->get('db_centreon');

        $query = 'SELECT config_id, poller_id'
            . ' FROM cfg_centreonbroker'
            . ' WHERE config_name like "%module%"';
        $stmt = $dbconn->prepare($query);
        $stmt->execute();
        $result = $stmt->fetchAll();

        return $result;
    }
}
