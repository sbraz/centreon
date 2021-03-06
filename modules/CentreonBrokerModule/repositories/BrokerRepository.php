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
use CentreonConfiguration\Models\Poller;
use CentreonBroker\Models\Broker;
use CentreonBroker\Models\BrokerPollerValues;
use CentreonAdministration\Repository\OptionRepository;
use CentreonConfiguration\Internal\Poller\Template\Manager as PollerTemplateManager;
use CentreonRealtime\Events\ExternalCommand;
use CentreonBroker\Repository\ConfigGenerateRepository;

/**
 * @author Sylvestre Ho <sho@centreon.com>
 * @package CentreonEngine
 * @subpackage Repository
 */
class BrokerRepository
{
    /**
     * Save broker parameters of a node
     *
     * @param int $pollerId
     * @param array $params
     */
    public static function save($pollerId, $params)
    {
        $db = Di::getDefault()->get('db_centreon');

        $arr = array();
        foreach ($params as $k => $v) {
            $arr[$k] = $v;
        }

        /* Save paths */
        /* Test if exists in db */
        $query = "SELECT COUNT(poller_id) as poller
            FROM cfg_centreonbroker_paths
            WHERE poller_id = :poller_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':poller_id', $pollerId, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        $stmt->closeCursor();

        $sqlParams = array();
        $sqlParams['poller_id'] = $pollerId;
        if (isset($params['directory_config'])) {
            $sqlParams['directory_config'] = $params['directory_config'];
        }
        if (isset($params['directory_modules'])) {
            $sqlParams['directory_modules'] = $params['directory_modules'];
        }
        if (isset($params['directory_logs'])) {
            $sqlParams['directory_logs'] = $params['directory_logs'];
        }
        if (isset($params['directory_data'])) {
            $sqlParams['directory_data'] = $params['directory_data'];
        }
        if (isset($params['init_script'])) {
            $sqlParams['init_script'] = $params['init_script'];
        }
        if (isset($params['directory_cbmod'])) {
            $sqlParams['directory_cbmod'] = $params['directory_cbmod'];
        }
        if ($row['poller'] > 0) {
            /* Update */
            Broker::update($pollerId, $sqlParams);
            BrokerPollerValues::delete($pollerId, false);
        } else {
            /* Insert */
            Broker::insert($sqlParams, true);
        }
        
        /* Save extract params */
        $listTpl = PollerTemplateManager::buildTemplatesList();
        $tmpl = "";
        if (isset($params['tmpl_name'])) {
            $tmpl = $params['tmpl_name'];
        }
        if (!isset($listTpl[$tmpl])) {
            return;
        }
        
        $fileTplList = $listTpl[$tmpl]->getBrokerPath();
        $information = array();
        foreach ($fileTplList as $fileTpl) {
            $information = static::mergeBrokerConf($information, $fileTpl);
        }
        
        $listType = array('output', 'input', 'logger');
        /* setup */
        foreach ($information['content']['broker']['setup'] as $setup) {
            /* mode */
            foreach ($setup['params']['mode'] as $mode) {
                /* type */
                foreach ($mode as $type => $config) {
                    /* @todo one peer retention */
                    if ($type == 'normal') {
                        /* module */
                        /* Sort for central in first install */
                        $configSorted = array();
                        if ($params['tmpl_name'] == 'Central') {
                            $configTmp = array();
                            foreach ($config as $module) {
                                if ($module['general']['name'] == 'central-broker') {
                                    $configTmp[0] = $module;
                                } else if ($module['general']['name'] == 'central-rrd') {
                                    $configTmp[1] = $module;
                                } else if ($module['general']['name'] == 'poller-module') {
                                    $configTmp[2] = $module;
                                }
                            }
                            for ($i = 0; $i < count($configTmp); $i++) {
                                $configSorted[] = $configTmp[$i];
                            }
                        } else {
                            $configSorted = $config;
                        }
                        foreach ($configSorted as $module) {
                            $configId =  static::insertConfig($pollerId, $module['general']['name'], $arr);
                            foreach ($listType as $type) {
                                if (isset($module[$type])) {
                                    $groupNb = 1;
                                    foreach ($module[$type] as $typeInfo) {
                                        /* Key */
                                        foreach ($typeInfo as $key => $value) {
                                            if (is_string($value) && preg_match("/%([\w_]+|[\w-]+)%/", $value, $matches)) {
                                                if (isset($params[$matches[1]]) && trim($params[$matches[1]]) !== "") {
                                                    static::insertPollerInfo($pollerId, $matches[1], $params[$matches[1]]);
                                                }
                                            } else {
                                                /* @todo add user infos */
                                            }
                                        }
                                        $groupNb++;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     *
     * @param type $finalFileTpl
     * @param type $fileTpl
     * @return $finalFileTpl
     */
    public static function mergeBrokerConf($finalFileTpl, $fileTpl)
    {
        $content = json_decode(file_get_contents($fileTpl), true);
        if (count($finalFileTpl) > 0) {
            foreach ($content['content']['broker']['setup'] as $setup) {
                $finalFileTpl['content']['broker']['setup'] = static::mergeBrokerConfSetup($finalFileTpl['content']['broker']['setup'], $setup);
            }
        } else {
            $finalFileTpl = $content;
        }
        return $finalFileTpl;
    }

    /**
     *
     * @param type $finalSetup
     * @param type $tmpSetup
     * @return $finalSetup
     */
    public static function mergeBrokerConfSetup($finalSetup, $tmpSetup)
    {
        foreach ($finalSetup as &$setup) {
            if ($setup['name'] === $tmpSetup['name']) {
                foreach ($tmpSetup['params']['mode'] as $mode) {
                    $setup['params']['mode'] = static::mergeBrokerConfMode($setup['params']['mode'], $mode);
                }
            } else {
                $count = count($setup);
                $setup[$count]['name'] = $tmpSetup['name'];
                $setup[$count]['params'] = $tmpSetup['params'];
            }
        }
        return $finalSetup;
    }

    /**
     *
     * @param type $finalMode
     * @param type $tmpMode
     * @return $finalMode
     */
    public static function mergeBrokerConfMode($finalMode, $tmpMode)
    {
        $tmpModeName = current(array_keys($tmpMode));
        $tmpModeValue = $tmpMode[$tmpModeName];
        $merged = false;
        foreach ($finalMode as &$mode) {
            $modeName = current(array_keys($mode));
            if ($modeName === $tmpModeName) {
                $merged = true;
                foreach ($tmpMode[$tmpModeName] as $property) {
                    $mode[$modeName] = static::mergeBrokerConfProperty($mode[$modeName], $property);
                }
            }
        }
        return $finalMode;
    }

    /**
     *
     * @param type $finalProperty
     * @param type $tmpProperty
     * @return $finalProperty
     */
    public static function mergeBrokerConfProperty($finalProperty, $tmpProperty)
    {
        $merged = false;
        foreach ($finalProperty as &$property) {
            if ($property['general']['name'] === $tmpProperty['general']['name']) {
                $merged = true;
                if (isset ($tmpProperty['input'])) {
                    $property['input'] = isset($property['input']) ? $property['input'] : array();
                    $property['input'] = array_merge($property['input'], $tmpProperty['input']);
                }
                if (isset ($tmpProperty['output'])) {
                    $property['output'] = isset($property['output']) ? $property['output'] : array();
                    $property['output'] = array_merge($property['output'], $tmpProperty['output']);
                }
                if (isset ($tmpProperty['logger'])) {
                    $property['logger'] = isset($property['logger']) ? $property['logger'] : array();
                    $property['logger'] = array_merge($property['logger'], $tmpProperty['logger']);
                }
                if (isset ($tmpProperty['correlation'])) {
                    $property['correlation'] = isset($property['correlation']) ? $property['correlation'] : array();
                    $property['correlation'] = array_merge($property['correlation'], $tmpProperty['correlation']);
                }
                if (isset ($tmpProperty['stats'])) {
                    $property['stats'] = isset($property['stats']) ? $property['stats'] : array();
                    $property['stats'] = array_merge($property['stats'], $tmpProperty['stats']);
                }
                if (isset ($tmpProperty['general'])) {
                    $property['general'] = isset($property['general']) ? $property['general'] : array();
                    $property['general'] = array_merge($property['general'], $tmpProperty['general']);
                }
            }
        }
        if (!$merged) {
            $finalProperty[] = $tmpProperty;
        }
        return $finalProperty;
    }

    /**
     * 
     * @param type $pollerId
     * @param type $configName
     * @return type
     */
    public static function getConfig($pollerId, $configName = "", $withName = false)
    {
        $dbconn = Di::getDefault()->get('db_centreon');
        /* Test if the configuration is in database */
        $query = "SELECT config_id";
        
        if ($withName) {
            $query .= ", config_name";
        }
        
        $query .= " FROM cfg_centreonbroker WHERE poller_id = :poller_id";
        
        if (!empty($configName)) {
            $query .= " AND config_name = :config_name";
        }
        
        $stmt = $dbconn->prepare($query);
        $stmt->bindParam(':poller_id', $pollerId, \PDO::PARAM_INT);
        
        if (!empty($configName)) {
            $stmt->bindParam(':config_name', $configName, \PDO::PARAM_STR);
        }
        
        $stmt->execute();
        $row = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        
        $return = false;
        
        if ($withName) {
            if (count($row) > 1) {
                $return = $row;
            } else {
                $return = array();
            }
        } elseif (count($row) == 1) {
            $return = $row[0]['config_id'];
        }
        return $return;
    }
    
    /**
     * Add a configuration for a module
     * 
     * @param int $pollerId The poller id
     * @param string $configName The configuration module name
     * @param array $params
     * @return mixed
     */
    public static function insertConfig($pollerId, $configName, $params) {
        $dbconn = Di::getDefault()->get('db_centreon');
        /* Test if the configuration is in database */
        $configId = static::getConfig($pollerId, $configName);
        if (false !== $configId) {
            $queryInsert = "UPDATE cfg_centreonbroker
                SET event_queue_max_size = :event_queue_max_size,
                write_thread_id = :write_thread_id,
                write_timestamp = :write_timestamp,
                flush_logs = :flush_logs
                WHERE config_name = :config_name
                AND poller_id = :poller_id";
            $stmt = $dbconn->prepare($queryInsert);
            $stmt->bindParam(':config_name', $configName, \PDO::PARAM_STR);
            $stmt->bindParam(':event_queue_max_size', $params['event_queue_max_size'], \PDO::PARAM_STR);
            $stmt->bindParam(':write_thread_id', $params['write_thread_id'], \PDO::PARAM_STR);
            $stmt->bindParam(':write_timestamp', $params['write_timestamp'], \PDO::PARAM_STR);
            $stmt->bindParam(':flush_logs', $params['flush_logs'], \PDO::PARAM_STR);
            $stmt->bindParam(':poller_id', $pollerId, \PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $queryInsert = "INSERT INTO cfg_centreonbroker
                (poller_id, config_name) VALUES
                (:poller_id, :config_name)";
            $stmt = $dbconn->prepare($queryInsert);
            $stmt->bindParam(':poller_id', $pollerId, \PDO::PARAM_INT);
            $stmt->bindParam(':config_name', $configName, \PDO::PARAM_STR);
            $stmt->execute();

            $configId = static::getConfig($pollerId, $configName);
        }
        
        return $configId;
    }
    
    /**
     * 
     * @param type $pollerId
     * @return type
     */
    public static function getUserInfo($pollerId)
    {
        $fullInfo = array();
        
        $configs = static::getConfig($pollerId, "", true);
        
        foreach ($configs as $config) {
            $configInfo = static::loadUserInfoForConfig($config['config_id']);
            $fullInfo[$config['config_name']] = $configInfo;
        }
        
        return $fullInfo;
    }
    
    /**
     * 
     * @param type $configId
     * @return type
     */
    public static function loadUserInfoForConfig($configId)
    {
        $dbconn = Di::getDefault()->get('db_centreon');
        /* Test if the information is already in database */
        
        $query = "SELECT *
            FROM cfg_centreonbroker_info
            WHERE config_id = :config_id";
        $stmt = $dbconn->prepare($query);
        $stmt->bindParam(':config_id', $configId, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $row;
    }

    /**
     * Add or update a custom information for Centreon Broker set by a user
     *
     * @param string $group The group name
     * @param int $groupId The group id
     * @param string $key The configuration name
     * @param string $value The configuration value
     */
    public static function insertUserInfo($configId, $group, $groupId, $key, $value)
    {
        $dbconn = Di::getDefault()->get('db_centreon');
        /* Test if the information is already in database */
        $query = "SELECT COUNT(*) as nb
            FROM cfg_centreonbroker_info
            WHERE config_id = :config_id
                AND config_key = :config_key
                AND config_group = :config_group
                AND config_group_id = :config_group_id";
        $stmt = $dbconn->prepare($query);
        $stmt->bindParam(':config_id', $configId, \PDO::PARAM_INT);
        $stmt->bindParam(':config_key', $key, \PDO::PARAM_STR);
        $stmt->bindParam(':config_group', $group, \PDO::PARAM_STR);
        $stmt->bindParam(':config_group_id', $groupId, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row['nb'] > 0) {
            $query = "UPDATE cfg_centreonbroker_info SET
                config_value = :config_value
                WHERE config_id = :config_id
                    AND config_key = :config_key
                    AND config_group = :config_group
                    AND config_group_id = :config_group_id";
        } else {
            $query = "INSERT INTO cfg_centreonbroker_info
                (config_id, config_key, config_value, config_group, config_group_id) VALUES
                (:config_id, :config_key, :config_value, :config_group, :config_group_id)";
        }
        $stmt = $dbconn->prepare($query);
        $stmt->bindParam(':config_id', $configId, \PDO::PARAM_INT);
        $stmt->bindParam(':config_key', $key, \PDO::PARAM_STR);
        $stmt->bindParam(':config_value', $value, \PDO::PARAM_STR);
        $stmt->bindParam(':config_group', $group, \PDO::PARAM_STR);
        $stmt->bindParam(':config_group_id', $groupId, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Insert a custom information for a poller
     *
     * @param int $pollerId The poller id
     * @param string $key The name of configuration
     * @param string $value The value of configuration
     */
    public static function insertPollerInfo($pollerId, $key, $value)
    {
        $dbconn = Di::getDefault()->get('db_centreon');
        /* Test if the information is in database */
        $query = "SELECT COUNT(*) as nb
            FROM cfg_centreonbroker_pollervalues
            WHERE poller_id = :poller_id
                AND name = :name";
        $stmt = $dbconn->prepare($query);
        $stmt->bindParam(':poller_id', $pollerId, \PDO::PARAM_INT);
        $stmt->bindParam(':name', $key, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row['nb'] > 0) {
            $query = "UPDATE cfg_centreonbroker_pollervalues SET
                value = :value
                WHERE poller_id = :poller_id
                    AND name = :name";
        } else {
            $query = "INSERT INTO cfg_centreonbroker_pollervalues
                (poller_id, name, value) VALUES
                (:poller_id, :name, :value)";
        }
        $stmt = $dbconn->prepare($query);
        $stmt->bindParam(':poller_id', $pollerId, \PDO::PARAM_INT);
        $stmt->bindParam(':name', $key, \PDO::PARAM_INT);
        $stmt->bindParam(':value', $value, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Get the paths for a Centreon Broker poller
     * 
     * @param int $pollerId
     * @return array
     */
    public static function getPathsFromPollerId($pollerId)
    {
        $db = Di::getDefault()->get('db_centreon');
        $sql = "SELECT directory_modules, directory_config, directory_logs, 
            directory_data, directory_cbmod, init_script
            FROM cfg_centreonbroker_paths
            WHERE poller_id = :poller_id";
        $stmt = $db->prepare($sql);
        $stmt->execute(array(
            ':poller_id' => $pollerId
        ));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row;
    }
    
    /**
     * 
     * @param integer $pollerId
     * @return array
     */
    public static function getGeneralValues($pollerId)
    {
        $db = Di::getDefault()->get('db_centreon');
        $sql = "SELECT write_thread_id, event_queue_max_size, write_timestamp, flush_logs
            FROM cfg_centreonbroker
            WHERE poller_id = :poller_id";
        $stmt = $db->prepare($sql);
        $stmt->execute(array(
            ':poller_id' => $pollerId
        ));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row;
    }

    /**
     * Load custom configuration values for Centreon Broker
     *
     * @param int $pollerId The poller id
     * @return array
     */
    public static function loadValues($pollerId)
    {
        $dbconn = Di::getDefault()->get('db_centreon');
        $query = "SELECT name, value
            FROM cfg_centreonbroker_pollervalues
            WHERE poller_id = :poller_id";
        $stmt = $dbconn->prepare($query);
        $stmt->bindParam(':poller_id', $pollerId, \PDO::PARAM_INT);
        $stmt->execute();
        $values = array();
        while ($row = $stmt->fetch()) {
            $values[$row['name']] = $row['value'];
        }
        return $values;
    }
    
    /**
     * 
     * @return type
     */
    public static function getGlobalValues()
    {
        $globalOptions = array();
        
        $defaultOptionskeys = array(
            'rrd_metric_path',
            'rrd_status_path',
            'rrd_path',
            'rrd_port',
            'storage_interval',
            'broker_modules_directory',
            'broker_data_directory',
        );
        $defaultOptionsValues = OptionRepository::get('default', $defaultOptionskeys);
        
        foreach($defaultOptionskeys as $key){
            if(!isset($defaultOptionsValues[$key])){
                $defaultOptionsValues[$key] = '';
            }
        }
        
        $defaultOptionsValuesKeys = array_keys($defaultOptionsValues);
        foreach ($defaultOptionsValuesKeys as &$optValue) {
            switch($optValue) {
                case 'rrd_metric_path':
                    $optValue = 'rrd_metrics';
                    break;
                case 'rrd_status_path':
                    $optValue = 'rrd_status';
                    break;
                case 'storage_interval':
                    $optValue = 'interval';
                    break;
                default:
                    break;
            }
            $optValue = 'global_' . $optValue;
        }
        if (count($defaultOptionsValues)) {
            $globalOptions = array_combine($defaultOptionsValuesKeys, array_values($defaultOptionsValues));
        }
        
        return $globalOptions;
    }

    /**
     * Send external command for poller
     *
     * @param int $cmdId
     */
    public static function sendCommand($pollerId, $command)
    {
        $dbconn = Di::getDefault()->get('db_centreon');

        $query = 'SELECT config_id'
            . ' FROM cfg_centreonbroker'
            . ' WHERE poller_id = :poller_id'
            . ' AND config_name = "central-broker"';
        $stmt = $dbconn->prepare($query);
        $stmt->bindParam(':poller_id', $pollerId, \PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();
        if ($row === false) {
            throw new \Exception ("Can't get config id");
        }
        $brokerId = $row['config_id'];

        $commandFile = self::getBrokerCommandFileFromBrokerId($brokerId);

        $finalCommand = 'EXECUTE;' . $brokerId . ';' . $command;
        self::writeCommand($finalCommand, $commandFile);
    }
    
    /**
     * Write the command to the Centreon Broker socket
     *
     * @param string $command the command to execute
     * @return array
     */
    private static function writeCommand($command, $commandFile)
    {
        /* @todo get the path */
        $socketPath = 'unix://' . $commandFile;
        ob_start();
        $stream = stream_socket_client($socketPath, $errno, $errstr, 10);
        ob_end_clean();
        if (false === $stream) {
            throw new \Exception("Error to connect to the socket.");
        }
        fwrite($stream, $command . "\n");
        $rStream = array($stream);
        $nbStream = stream_select($rStream, $wStream = null, $eStream = null, 5);
        if (false === $nbStream || 0 === $nbStream) {
            fclose($stream);
            throw new \Exception("Error to read the socket.");
        }
        $ret = explode(' ', fgets($stream), 3);
        fclose($stream);
        if ($ret[1] !== '0x1' && $ret[1] !== '0x0') {
            throw new \Exception("Error when execute command : " . $ret[2]);
        }
        $running = true;
        if ($ret[1] === '0x0') {
            $running = false;
        }
        return array('id' => $ret[0], 'running' => $running);
    }

    /**
     * Send external command for poller
     *
     * @param int $pollerId
     */
    public static function getConfigEndpoints($pollerId)
    {
        $dbconn = Di::getDefault()->get('db_centreon');

        $query = 'SELECT value'
            . ' FROM cfg_centreonbroker_pollervalues'
            . ' WHERE poller_id = :poller_id'
            . ' AND name like "%dump_dir%"';
        $stmt = $dbconn->prepare($query);
        $stmt->bindParam(':poller_id', $pollerId, \PDO::PARAM_INT);
        $stmt->execute();

        $endpoints = array();
        while ($row = $stmt->fetch()) {
            $endpoints[] = $row['value'];
        }

        return $endpoints;
    }

    /**
     * Get broker config from poller id
     *
     * @param int $pollerId
     */
    public static function getBrokerConfigFromPollerId($pollerId)
    {
        $poller = Poller::getParameters($pollerId, 'tmpl_name');
        $tmpl = $poller['tmpl_name'];
        $listTpl = PollerTemplateManager::buildTemplatesList();

        $fileTplList = $listTpl[$tmpl]->getBrokerPath();

        $information = array();
        foreach ($fileTplList as $fileTpl) {
            $information = static::mergeBrokerConf($information, $fileTpl);
        }

        return $information;
    }

    /**
     * Get broker config from broker id
     *
     * @param int $brokerId
     */
    public static function getBrokerConfigFromBrokerId($brokerId)
    {
        $dbconn = Di::getDefault()->get('db_centreon');

        $poller = Broker::getParameters($brokerId, 'poller_id');
        $pollerId = $poller['poller_id'];

        $configuration = self::getBrokerConfigFromPollerId($pollerId);

        $query = 'SELECT config_name'
            . ' FROM cfg_centreonbroker'
            . ' WHERE config_id = :config_id';
        $stmt = $dbconn->prepare($query);
        $stmt->bindParam(':config_id', $brokerId, \PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();
        if ($row === false) {
            throw new \Exception ("Can't get config name");
        }
        $brokerName = $row['config_name'];

        $brokerConfig = array();

        if (isset($configuration['content']['broker']['setup'])) {
            $setups = $configuration['content']['broker']['setup'];
            foreach ($setups as $setup) {
                if (isset($setup['params']['mode'])) {
                    $modes = $setup['params']['mode'];
                    foreach ($modes as $mode) {
                        if (isset($mode['normal'])) {
                            $normals = $mode['normal'];
                            foreach ($normals as $normal) {
                                if (isset($normal['general']['name']) && $normal['general']['name'] == $brokerName) {
                                    $brokerConfig = $normal;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $brokerConfig;
    }

    /**
     * Get broker endpoint from broker id
     *
     * @param int $brokerId
     */
    public static function getBrokerEndpointFromBrokerId($brokerId, $type)
    {
        $brokerConfig = self::getBrokerConfigFromBrokerId($brokerId);

        $endpoints = array();
        foreach ($brokerConfig as $configKey => $configValue) {
            if ($configKey != 'general') {
                foreach ($configValue as $value) {
                    if (isset($value['type']) && $value['type'] == $type) {
                        $endpoints[] = $value;
                    }
                }
            }
        }

        return $endpoints;
    }

    /**
     * Get broker command file from broker id
     *
     * @param int $brokerId
     */
    public static function getBrokerCommandFileFromBrokerId($brokerId)
    {
        $brokerConfig = self::getBrokerConfigFromBrokerId($brokerId);

        $commandFile = "";
        if (isset($brokerConfig['general']) && isset($brokerConfig['general']['command_file'])) {
            $commandFile = $brokerConfig['general']['command_file'];
        }
        $commandFile = self::getBrokerFinalValue($commandFile);

        return $commandFile;
    }

    /**
     * Get broker final value
     *
     * @param int $brokerId
     */
    public static function getBrokerFinalValue($value)
    {
        if (is_string($value) && preg_match("/%([\w_]+|[\w-]+)%/", $value, $matches)) {
            if (isset($matches[1]) && trim($matches[1]) !== "") {
                $globalValues = self::getGlobalValues();
                if (isset($globalValues[$matches[1]])) {
                    $value = str_replace('%' . $matches[1] . '%', $globalValues[$matches[1]], $value);
                }
            }
        }
                
        return $value;
    }
}
