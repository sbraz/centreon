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

namespace CentreonConfiguration\Repository;
use \Centreon\Internal\Exception\Http\BadRequestException;
use Centreon\Internal\Form\Validators\ForbiddenChar;
use Centreon\Internal\Di;

/**
 * Description of CustomMacrosRepository
 *
 * @author lionel
 */
class CustomMacroRepository extends Repository
{
    
    public static $forbidenCHar = "`~$^&\"|'<>";
    
    /**
     *
     * @var type 
     */
    public static $unicityFields = array(
        'fields' => array(
            'macroHost'    => 'cfg_customvariables_hosts, host_macro_id, host_macro_name',
            'macroService' => 'cfg_customvariables_services, svc_macro_id, svc_macro_name'
        ),
    );
    
    
    /**
     * 
     * @param type $objectId
     */
    public static function loadHostCustomMacro($objectId)
    {
        $dbconn = Di::getDefault()->get('db_centreon');
        
        $getRequest = "SELECT host_macro_name AS macro_name, host_macro_value AS macro_value, is_password AS macro_hidden "
            . "FROM cfg_customvariables_hosts WHERE host_host_id = :host ";
        $stmtGet = $dbconn->prepare($getRequest);
        $stmtGet->bindParam(':host', $objectId, \PDO::PARAM_INT);
        $stmtGet->execute();
        $rowMacro = $stmtGet->fetchAll(\PDO::FETCH_ASSOC);
        return $rowMacro;
    }
    
    /**
     * 
     * @param type $objectId
     * @param type $submittedValues
     */
    public static function saveHostCustomMacro($sTypeObject, $objectId, $submittedValues, $deleteFirst = true)
    {
        $dbconn = Di::getDefault()->get('db_centreon');

        if ($deleteFirst){
            $deleteRequest = "DELETE FROM cfg_customvariables_hosts WHERE host_host_id = :host";
            $stmtDelete = $dbconn->prepare($deleteRequest);
            $stmtDelete->bindParam(':host', $objectId, \PDO::PARAM_INT);
            $stmtDelete->execute();
        }
        foreach ($submittedValues as $customMacroName => $customMacro) {
            self::validate($sTypeObject, $customMacroName, $objectId);
            
        }        
        $insertRequest = "INSERT INTO cfg_customvariables_hosts(host_macro_name, host_macro_value, is_password, host_host_id)"
            . " VALUES(:macro_name, :macro_value, :is_password, :host)";
        $stmtInsert = $dbconn->prepare($insertRequest);
        
        foreach ($submittedValues as $customMacroName => $customMacro) {            
            $stmtInsert->bindValue(':macro_name', '$_HOST' . $customMacroName . '$', \PDO::PARAM_STR);
            $stmtInsert->bindParam(':macro_value', $customMacro['value'], \PDO::PARAM_STR);
            $stmtInsert->bindParam(':is_password', $customMacro['is_password'], \PDO::PARAM_INT);
            $stmtInsert->bindParam(':host', $objectId, \PDO::PARAM_INT);
            $stmtInsert->execute();
        }
    }
    
    /**
     * 
     * @param type $objectId
     */
    public static function loadServiceCustomMacro($objectId)
    {
        $dbconn = Di::getDefault()->get('db_centreon');
        
        $getRequest = "SELECT svc_macro_name AS macro_name, svc_macro_value AS macro_value, is_password AS macro_hidden "
            . "FROM cfg_customvariables_services WHERE svc_svc_id = :svc ";
        $stmtGet = $dbconn->prepare($getRequest);
        $stmtGet->bindParam(':svc', $objectId, \PDO::PARAM_INT);
        $stmtGet->execute();
        $rowMacro = $stmtGet->fetchAll(\PDO::FETCH_ASSOC);
        return $rowMacro;
    }
    
    /**
     * 
     * @param type $objectId
     * @param type $iHostId
     * @param type $submittedValues
     * @param type $deleteFirst
     */
    public static function saveServiceCustomMacro($sTypeObject, $objectId, $submittedValues, $deleteFirst = true, $iHostId = null)
    {
        $dbconn = Di::getDefault()->get('db_centreon');
        
        if($deleteFirst){
            $deleteRequest = "DELETE FROM cfg_customvariables_services WHERE svc_svc_id = :svc";
            $stmtDelete = $dbconn->prepare($deleteRequest);
            $stmtDelete->bindParam(':svc', $objectId, \PDO::PARAM_INT);
            $stmtDelete->execute();
        }
        foreach ($submittedValues as $customMacroName => $customMacro) {
            self::validate($sTypeObject, $customMacroName, $objectId, $iHostId);
            
        }
        $insertRequest = "INSERT INTO cfg_customvariables_services(svc_macro_name, svc_macro_value, is_password, svc_svc_id)"
            . " VALUES(:macro_name, :macro_value, :is_password, :svc)";
        $stmtInsert = $dbconn->prepare($insertRequest);
        
        foreach ($submittedValues as $customMacroName => $customMacro) {
            $stmtInsert->bindValue(':macro_name', '$_SERVICE' . $customMacroName . '$', \PDO::PARAM_STR);
            $stmtInsert->bindParam(':macro_value', $customMacro['value'], \PDO::PARAM_STR);
            $stmtInsert->bindParam(':is_password', $customMacro['is_password'], \PDO::PARAM_INT);
            $stmtInsert->bindParam(':svc', $objectId, \PDO::PARAM_INT);
            $stmtInsert->execute();
        }
    }
    
    /**
     * 
     * @param int $objectId
     * @param string $macro
     */
    public static function deleteHostCustomMacro($objectId, $macro)
    {
        $dbconn = Di::getDefault()->get('db_centreon');

        $deleteRequest = "DELETE FROM cfg_customvariables_hosts WHERE host_host_id = :host AND host_macro_name = :macro_name ";
        $macroName = '$_HOST'.$macro.'$';
        $stmtDelete = $dbconn->prepare($deleteRequest);
        $stmtDelete->bindParam(':host', $objectId, \PDO::PARAM_INT);
        $stmtDelete->bindParam(':macro_name', $macroName, \PDO::PARAM_STR);
        $stmtDelete->execute();
        
        $deletedRow = $stmtDelete->rowCount();
        
        if($deletedRow == 0){
            throw new \Exception('This macro : \''.$macro.'\' can\'t be found on the object', 1);
        }
        
        return $deletedRow;
    }

    /**
     * 
     * @param int $objectId
     * @param string $macro
     */
    public static function deleteServiceCustomMacro($objectId, $macro)
    {
        $dbconn = Di::getDefault()->get('db_centreon');
        $macroName = '$_SERVICE'.$macro.'$';
        $deleteRequest = "DELETE FROM cfg_customvariables_services WHERE svc_svc_id = :service AND svc_macro_name = :macro_name ";
        $stmtDelete = $dbconn->prepare($deleteRequest);
        $stmtDelete->bindParam(':service', $objectId, \PDO::PARAM_INT);
        $stmtDelete->bindParam(':macro_name', $macroName, \PDO::PARAM_STR);
        $stmtDelete->execute();
        $deletedRow = $stmtDelete->rowCount();
        
        if($deletedRow == 0){
            throw new \Exception('This macro : \''.$macro.'\' can\'t be found on the object', 1);
        }
        
        return $deletedRow;
    }
    
    /**
     * 
     * @param int $objectId
     * @param string $macro
     * @param array $params
     */
    public static function updateHostCustomMacro($objectId, $macro, $params){

        $setPart = "";
        $paramArray = array();

        
        if(isset($params['host_macro_value'])){
            if (!empty($paramArray)) {
                $setPart = $setPart.' , ';
            }
            $setPart .= ' host_macro_value = :host_macro_value ';
            $paramArray[':host_macro_value'] = array('param' => $params['host_macro_value'] , 'type' => \PDO::PARAM_STR);
        }
        
        if(isset($params['host_macro_name'])){
            if (!empty($paramArray)) {
                $setPart = $setPart.' , ';
            }
            $setPart .= ' host_macro_name = :host_macro_name ';
            $paramArray[':host_macro_name'] = array('param' => $params['host_macro_name'] , 'type' => \PDO::PARAM_STR);
        }
        
        if(isset($params['is_password'])){
            if (!empty($paramArray)) {
                $setPart = $setPart.' , ';
            }
            $setPart .= ' is_password = :is_password ';
            $paramArray[':is_password'] = array('param' => $params['is_password'] , 'type' => \PDO::PARAM_INT);
        }

        
        if(!empty($paramArray)){
            $setPart = ' SET '.$setPart;
        }

        $dbconn = Di::getDefault()->get('db_centreon');
        
        $macroName = '$_HOST'.$macro.'$';
        $updateRequest = "UPDATE cfg_customvariables_hosts "
                . $setPart
                . "WHERE host_host_id = :host "
                . "AND host_macro_name = :macro_name ";

        $stmtUpdate = $dbconn->prepare($updateRequest);
        
        foreach($paramArray as $index=>$param2){
            $stmtUpdate->bindParam($index, $param2['param'], $param2['type']);
        }
        
        $stmtUpdate->bindParam(':host', $objectId, \PDO::PARAM_INT);
        $stmtUpdate->bindParam(':macro_name', $macroName, \PDO::PARAM_STR);
        $stmtUpdate->execute();
        
        $updatedRow = $stmtUpdate->rowCount();
        
        if($updatedRow == 0){
            throw new \Exception('This macro : \''.$macro.'\' can\'t be found on the object', 1);
        }
        
        return $updatedRow;

    }
    
    /**
     * 
     * @param int $objectId
     * @param string $macro
     * @param array $params
     */
    public static function updateServiceCustomMacro($objectId, $macro, $params)
    {
        $setPart = "";
        $paramArray = array();
        if(isset($params['svc_macro_value'])){
            if (!empty($paramArray)) {
                $setPart = $setPart.' , ';
            }
            $setPart .= ' svc_macro_value = :svc_macro_value ';
            $paramArray[':svc_macro_value'] = array('param' => $params['svc_macro_value'] , 'type' => \PDO::PARAM_STR);
        }
        
        if(isset($params['svc_macro_name'])){
            if (!empty($paramArray)) {
                $setPart = $setPart.' , ';
            }
            $setPart .= ' svc_macro_name = :svc_macro_name ';
            $paramArray[':svc_macro_name'] = array('param' => $params['svc_macro_name'] , 'type' => \PDO::PARAM_STR);
        }
        
        if(isset($params['is_password'])){
            if (!empty($paramArray)) {
                $setPart = $setPart.' , ';
            }
            $setPart .= ' is_password = :is_password ';
            $paramArray[':is_password'] = array('param' => $params['is_password'] , 'type' => \PDO::PARAM_INT);
        }

        if(!empty($paramArray)){
            $setPart = ' SET '.$setPart;
        }
        
        $dbconn = Di::getDefault()->get('db_centreon');
        
        $macroName = '$_SERVICE'.$macro.'$';
        $updateRequest = "UPDATE cfg_customvariables_services "
                . $setPart
                . "WHERE svc_svc_id = :service "
                . "AND svc_macro_name = :macro_name ";
        
        $stmtUpdate = $dbconn->prepare($updateRequest);
        
        foreach($paramArray as $index=>$param2){
            $stmtUpdate->bindParam($index, $param2['param'], $param2['type']);
        }
        
        $stmtUpdate->bindParam(':service', $objectId, \PDO::PARAM_INT);
        $stmtUpdate->bindParam(':macro_name', $macroName, \PDO::PARAM_STR);
        $stmtUpdate->execute();

        $updatedRow = $stmtUpdate->rowCount();
        
        if($updatedRow == 0){
            throw new \Exception('This macro : \''.$macro.'\' can\'t be found on the object', 1);
        }
        
        return $updatedRow;
    }
    
    /**
     * 
     * @param type $sType
     * @param type $sNameMacro
     * @param type $iIdObject
     * @return type
     */
    public static function validate($sType, $sNameMacro, $iIdObject, $iHostId = null)
    {
        $tables = array();
        $conditions = array();
        $objectId = 0;
        $errors = array();
        
        $bSuccess = true;
        $resultError = _("Object already exists");
        $sMessage = '';
        
        $db = Di::getDefault()->get('db_centreon');
        
        if (!in_array($sType, array('host', 'hosttemplate', 'service', 'servicetemplate'))) {
            return ;
        }
        
        if ($sType == 'host' || $sType == 'hosttemplate') {
            $sElement = 'host_macro_id';
            $query = 'SELECT host_macro_id FROM cfg_customvariables_hosts WHERE host_macro_name = :host_macro_name'
                    . ' AND host_host_id = :host_host_id';
            $stmt = $db->prepare($query);
            $macroName = '$_HOST'.$sNameMacro.'$';
            $stmt->bindParam(':host_macro_name', $macroName, \PDO::PARAM_STR);
            $stmt->bindParam(':host_host_id', $iIdObject, \PDO::PARAM_INT);

        } elseif ($sType == 'servicetemplate') {
       
            $sElement = 'svc_macro_id';
            $query = 'SELECT svc_macro_id FROM cfg_customvariables_services WHERE svc_macro_name = :svc_macro_name'
                    . ' AND svc_svc_id = :svc_svc_id';
  
            $stmt = $db->prepare($query);
            $macroName = '$_SERVICE'.$sNameMacro.'$';
            $stmt->bindParam(':svc_macro_name', $macroName, \PDO::PARAM_STR);
            $stmt->bindParam(':svc_svc_id', $iIdObject, \PDO::PARAM_INT); 
        } else {
            $sElement = 'svc_macro_id';
            $query = 'SELECT svc_macro_id FROM cfg_customvariables_services join cfg_hosts_services_relations on service_service_id = svc_svc_id'
                    . ' WHERE svc_macro_name = :svc_macro_name'
                    . ' AND svc_svc_id = :svc_svc_id ';

            $stmt = $db->prepare($query);
            $macroName = '$_SERVICE'.$sNameMacro.'$';
            $stmt->bindParam(':svc_macro_name', $macroName, \PDO::PARAM_STR);
            $stmt->bindParam(':svc_svc_id', $iIdObject, \PDO::PARAM_INT); 
        }
        
        $stmt->execute();

        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (count($result) > 0) {
            $iIdReturned = $result[0][$sElement];
            $bSuccess = false;
            $sMessage = $resultError;
            
        }        
        if ($bSuccess === false) {
            $errors[] = $sMessage;
        }
        
        $params['characters'] = self::$forbidenCHar;
        $res = ForbiddenChar::validate($sNameMacro,$params);
        if(!$res['success']){
            $errors[] = 'Macro name : '.$res['error'];
        }       
        
        
         // If we got error, we throw Exception
        if (count($errors) > 0) {
            self::raiseValidationException($errors);
        }
        
        
    }
    /**
     * 
     * @param type $errors
     * @throws \Exception
     */
    public function raiseValidationException($errors)
    {
        throw new BadRequestException('Validation error', $errors);
    }
}
