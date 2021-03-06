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

namespace CentreonConfiguration\Models\Relation\Hosttemplate;

use Centreon\Internal\Di;
use Centreon\Models\CentreonRelationModel;

class Hosttemplate extends CentreonRelationModel
{
    protected static $relationTable = "cfg_hosts_templates_relations";
    protected static $firstKey = "host_host_id";
    protected static $secondKey = "host_tpl_id";
    public static $firstObject = "\CentreonConfiguration\Models\Hosttemplate";
    public static $secondObject = "\CentreonConfiguration\Models\Hosttemplate";

    /**
     * Insert host template / host relation
     * Order has importance
     *
     * @param int $fkey
     * @param int $skey
     * @return void
     */
    public static function insert($fkey, $skey)
    {
        $db = Di::getDefault()->get('db_centreon');
        $sql = "SELECT MAX(`order`) as maxorder FROM " .static::$relationTable . " WHERE " .static::$firstKey . " = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute(array($fkey));
        $row = $stmt->fetch();
        $order = 1;
        if (isset($row['maxorder'])) {
            $order = $row['maxorder']+1;
        }
        unset($res);
        $sql = "INSERT INTO ".static::$relationTable
            ." (".static::$firstKey.", ".static::$secondKey.", `order`) "
            . "VALUES (?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute(array($fkey, $skey, $order));
    }

    /**
     * Get target id from source id
     *
     * @param int $sourceKey
     * @param int $targetKey
     * @param array $sourceId
     * @return array
     */
    public static function getTargetIdFromSourceId($targetKey, $sourceKey, $sourceId)
    {
        if (!is_array($sourceId)) {
            $sourceId = array($sourceId);
        }
        $sql = "SELECT $targetKey FROM ".static::$relationTable." WHERE $sourceKey = ? ORDER BY `order`";
        $result = static::getResult($sql, $sourceId);
        $tab = array();
        foreach ($result as $rez) {
            $tab[] = $rez[$targetKey];
        }
        return $tab;
    }

    /**
     * Get Merged Parameters from seperate tables
     *
     * @param array $firstTableParams
     * @param array $secondTableParams
     * @param int $count
     * @param string $order
     * @param string $sort
     * @param array $filters
     * @param string $filterType
     * @return array
     */
    public static function getMergedParameters(
        $firstTableParams = array(),
        $secondTableParams = array(),
        $count = -1,
        $offset = 0,
        $order = null,
        $sort = "ASC",
        $filters = array(),
        $filterType = "OR"
    ) {
        if (!isset(static::$firstObject) || !isset(static::$secondObject)) {
            throw new Exception('Unsupported method on this object');
        }
        $fString = "";
        $sString = "";
        foreach ($firstTableParams as $fparams) {
            if ($fString != "") {
                $fString .= ",";
            }
            $fString .= "h.".$fparams;
        }
        foreach ($secondTableParams as $sparams) {
            if ($fString != "" || $sString != "") {
                $sString .= ",";
            }
            $sString .= "h2.".$sparams;
        }
        $firstObject = static::$firstObject;
        $secondObject = static::$secondObject;
        $sql = "SELECT ".$fString.$sString."
        		FROM ".$firstObject::getTableName()." h,".static::$relationTable."
        		JOIN ".$secondObject::getTableName()
                ." h2 ON ".static::$relationTable.".".static::$secondKey
                ." = h2.".$secondObject::getPrimaryKey() ."
        		WHERE h.".$firstObject::getPrimaryKey()." = ".static::$relationTable.".".static::$firstKey;
        $filterTab = array();
        if (count($filters)) {
            foreach ($filters as $key => $rawvalue) {
                $key = str_replace('cfg_hosts.', 'h.', $key);
                $sql .= " $filterType $key LIKE ? ";
                $value = trim($rawvalue);
                $value = str_replace("_", "\_", $value);
                $value = str_replace(" ", "\ ", $value);
                $filterTab[] = $value;
            }
        }
        if (isset($order) && isset($sort) && (strtoupper($sort) == "ASC" || strtoupper($sort) == "DESC")) {
            $sql .= " ORDER BY $order $sort ";
        }
        $db = Di::getDefault()->get('db_centreon');
        if (isset($count) && $count != -1) {
            $sql = $db->limit($sql, $count, $offset);
        }
        $result = static::getResult($sql, $filterTab);
        return $result;
    }
}
