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

namespace CentreonAdministration\Controllers;

use Centreon\Internal\Form\Generator\Web\Wizard;
use Centreon\Controllers\FormController;
use Centreon\Internal\Di;

class TimezoneController extends FormController
{
    protected $objectDisplayName = 'Timezone';
    public static $objectName = 'timezone';
    protected $objectBaseUrl = '/centreon-administration/timezone';
    protected $objectClass = '\CentreonAdministration\Models\Timezone';
    protected $repository = '\CentreonAdministration\Repository\TimezoneRepository';
    
    public static $relationMap = array();
    
    protected $datatableObject = '\CentreonAdministration\Internal\TimezoneDatatable';
    public static $isDisableable = false;
    
    /**
     * addtouser a timezone
     *
     * @method get
     * @route /timezone/addtouser
     */
    public function addtouserAction()
    {
        $di = Di::getDefault();
        $config = $di->get('config');
        $form = new Wizard(
            '/centreon-administration/timezone/addtouser',
            array('id' => '')
        );
        $form->getFormFromDatabase();
        $this->tpl->assign('validateUrl', '/centreon-administration/user/settimezone');
        echo $form->generate();
    }
}
