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

namespace CentreonRealtime\Controllers;

use CentreonConfiguration\Models\Host as HostConf;
use CentreonRealtime\Models\Host as HostRealtime;
use CentreonRealtime\Models\Service as ServiceRealtime;
use CentreonConfiguration\Models\Poller;
use CentreonRealtime\Repository\HostdetailRepository;
use Centreon\Internal\Utils\Status;
use Centreon\Internal\Utils\Datetime;
use CentreonRealtime\Events\HostDetailData;
use CentreonRealtime\Repository\HostRepository;
use CentreonRealtime\Repository\ServiceRepository;
use CentreonConfiguration\Repository\HostRepository as HostConfRepository;
use Centreon\Internal\Di;
use Centreon\Internal\Controller;

/**
 * Display service monitoring states
 *
 * @author Sylvestre Ho
 * @package CentreonRealtime
 * @subpackage Controllers
 */
class HostController extends Controller
{
    /**
     *
     * @var type 
     */
    protected $datatableObject = '\CentreonRealtime\Internal\HostDatatable';
    
    /**
     *
     * @var type 
     */
    protected $objectClass = '\CentreonRealtime\Models\Host';
    
    /**
     * 
     * @param type $request
     */
    public function __construct($request)
    {
        $confRepository = '\CentreonConfiguration\Repository\HostRepository';
        $confRepository::setObjectClass('\CentreonConfiguration\Models\Host');
        parent::__construct($request);
    }
    
    /**
     * Display services
     *
     * @method get
     * @route /host
     * @todo work on ajax refresh
     */
    public function displayHostsAction()
    {
        $router = Di::getDefault()->get('router');
        /* Load css */
        $this->tpl->addCss('dataTables.tableTools.min.css')
            ->addCss('dataTables.colVis.min.css')
            ->addCss('dataTables.colReorder.min.css')
            ->addCss('dataTables.bootstrap.css')
            ->addCss('select2.css')
            ->addCss('select2-bootstrap.css')
            ->addCss('centreon-wizard.css');

        /* Load js */
        $this->tpl->addJs('jquery.min.js')
            ->addJs('jquery.dataTables.min.js')
            ->addJs('dataTables.tableTools.min.js')
            ->addJs('dataTables.colVis.min.js')
            ->addJs('dataTables.colReorder.min.js')
            ->addJs('bootstrap-dataTables-paging.js')
            ->addJs('jquery.dataTables.columnFilter.js')
            ->addJs('jquery.select2/select2.min.js')
            ->addJs('jquery.validation/jquery.validate.min.js')
            ->addJs('jquery.validation/additional-methods.min.js')
            ->addJs('hogan-3.0.0.min.js')
            ->addJs('daterangepicker.js')
            ->addJs('centreon.search.js')
            ->addJs('centreon.tag.js', 'bottom', 'centreon-administration')
            ->addJs('bootstrap3-typeahead.js')
            ->addJs('centreon.search.js')
            ->addJs('centreon-wizard.js');

        
        
        /* Datatable */
        $this->tpl->assign('moduleName', 'CentreonRealtime');
        $this->tpl->assign('datatableObject', $this->datatableObject);
        $this->tpl->assign('objectName', 'Host');
        $this->tpl->assign('objectDisplayName', 'Host');
        $this->tpl->assign('objectListUrl', '/centreon-realtime/host/list');
        
        $actions = array();
        $actions[] = array(
            'group' => _('Hosts'),
            'actions' => HostdetailRepository::getMonitoringActions()
        );
        $this->tpl->assign('actions', $actions);
        
        $urls = array(
            'tag' => array(
                'add' => $router->getPathFor('/centreon-administration/tag/add'),
                'del' => $router->getPathFor('/centreon-administration/tag/delete'),
                'getallGlobal' => $router->getPathFor('/centreon-administration/tag/all'),
                'getallPerso' => $router->getPathFor('/centreon-administration/tag/allPerso'),
                'addMassive' => $router->getPathFor('/centreon-administration/tag/addMassive')
            )
        );
        $this->tpl->append('jsUrl', $urls, true);

        $this->tpl->display('file:[CentreonMainModule]list.tpl');
    }

    /**
     * The page structure for display
     *
     * @method get
     * @route /host/list
     */
    public function listAction()
    {
        $di = Di::getDefault();
        $router = $di->get('router');
        $this->tpl->addJs('centreon.tag.js', 'bottom', 'centreon-administration')
            ->addJs('moment-with-locales.js')
            ->addJs('moment-timezone-with-data.min.js');
        
        $myDatatable = new $this->datatableObject($this->getParams('get'), $this->objectClass);
        $myDataForDatatable = $myDatatable->getDatas();
        
        $router->response()->json($myDataForDatatable);
    }

    
    /**
     * Show parents issues of an host 
     *
     * @method get
     * @route /host/[i:id]/issues
     */
    public function issuesForHostAction()
    {
        $params = $this->getParams();
        $parent_issues = HostRepository::getParentIncidentsFromHost($params['id']);
        $parent_issues['success'] = true;
        /*
        echo '<pre>';
        print_r($parent_issues);
        echo '</pre>';
        die;
        */
        $this->router->response()->json($parent_issues);
    }
    
    /**
     * Host detail page
     *
     * @method get
     * @route /host/[i:id]
     */
    public function hostDetailAction()
    {
        $params = $this->getParams();
        $host = HostdetailRepository::getRealtimeData($params['id']);
        $this->tpl->assign('hostname', $host[0]['host_name']);
        $this->tpl->assign('address', $host[0]['host_address']);
        $this->tpl->assign('host_alias', $host[0]['host_alias']);
        $this->tpl->assign('host_icon', HostConfRepository::getIconImage($host[0]['host_name']));
        $this->tpl->assign('applications', array());
        $this->tpl->assign('routeParams', array(
            'id' => $params['id']
        ));

        $this->tpl->addCss('cal-heatmap.css')
             ->addCss('centreon.status.css')
             ->addCss('centreon-wizard.css');
        $this->tpl->addJs('d3.min.js')
             ->addJs('jquery.sparkline.min.js')
             ->addJs('cal-heatmap.min.js')
             ->addJs('jquery.knob.min.js')
             ->addJs('moment-timezone-with-data.min.js');

        $this->tpl->display('file:[CentreonRealtimeModule]host_detail.tpl');
    }
    
    /**
     * 
     * @method get
     * @route /host/[i:id]/data
     */
    public function hostDetailDataAction()
    {
        $params = $this->getParams('named');
        $events = Di::getDefault()->get('events');
        
        $success = true;
        $datas = array();
        
        // Get Host Infos
        $datas = HostRepository::getHostShortInfo($params['id']);
        $datas['output'] = nl2br(trim($datas['output']));

        $hostDetailDataEvent = new HostDetailData($params['id'], $datas);

        $events->emit('centreon-realtime.host.detail.data', array($hostDetailDataEvent));

        $this->router->response()->json(
            array(
                'success' => $success,
                'values' => $datas
            )
        );
    }

    /**
     * Host tooltip
     *
     * @method get
     * @route /host/[i:id]/tooltip
     */
    public function hostTooltipAction()
    {
        $params = $this->getParams();
        $rawdata = HostdetailRepository::getRealtimeData($params['id']);
        if (isset($rawdata[0])) {
            $data = $this->transformRawData($rawdata[0]);
            $this->tpl->assign('title', $rawdata[0]['host_name']);
            $this->tpl->assign('state', $rawdata[0]['state']);
            $this->tpl->assign('data', $data);
        } else {
            $this->tpl->assign('error', sprintf(_('No data found for host id:%s'), $params['id']));
        }
        $this->tpl->assign('params', array('host_id' => $params['id']));
        $this->tpl->display('file:[CentreonRealtimeModule]host_tooltip.tpl');
    }

    /**
     * Transform raw data
     *
     * @param array $rawdata
     * @return array
     */
    protected function transformRawData($rawdata)
    {
        $data = array();

        /* Address */
        $data[] = array(
            'label' => _('Address'),
            'value' => $rawdata['host_address']
        );

        /* Instance */
        $data[] = array(
            'label' => _('Poller'),
            'value' => $rawdata['instance_name']
        );

        /* State */
        $data[] = array(
            'label' => _('State'),
            'value' => Status::numToString(
                $rawdata['state'],
                Status::TYPE_HOST,
                true
            ) . " (" . ($rawdata['state_type'] ? "HARD" : "SOFT") . ")"
        );

        /* Command line */
        $data[] = array(
            'label' => _('Command line'),
            'value' => chunk_split($rawdata['command_line'], 80, "<br/>")
        );

        /* Output */
        $data[] = array(
            'label' => _('Output'),
            'value' => $rawdata['output']
        );

        /* Acknowledged */
        $data[] = array(
            'label' => _('Acknowledged'),
            'value' => $rawdata['acknowledged'] ? _('Yes') : _('No')
        );

        /* Downtime */
        $data[] = array(
            'label' => _('In downtime'),
            'value' => $rawdata['scheduled_downtime_depth'] ? _('Yes') : _('No')
        );

        /* Latency */
        $data[] = array(
            'label' => _('Latency'),
            'value' => $rawdata['latency'] . ' s'
        );

        /* Check period */
        $data[] = array(
            'label' => _('Check period'),
            'value' => $rawdata['check_period']
        );

        /* Last check */
        $data[] = array(
            'label' => _('Last check'),
            'value' => $rawdata['last_check']
        );

        /* Next check */
        $data[] = array(
            'label' => _('Next check'),
            'value' => $rawdata['next_check']
        );

        return $data;
    }

    /**
     * Display the realtime snapshot of a host
     *
     * @method get
     * @route /host/snapshotslide/[i:id]
     */
    public function snapshotslideAction()
    {
        $params = $this->getParams();

        $data['configurationData'] = HostConfRepository::getInheritanceValues($params['id'], true);
        $data['configurationData']['host_id'] = $params['id'];
        $host = HostConf::get($params['id'], 'host_name');
        $data['configurationData']['host_name'] = $host['host_name'];

        $data['realtimeData'] = HostRealtime::get($params['id']);

        $hostInformations = HostRepository::formatDataForSlider($data);

        $servicesStatus = ServiceRepository::countAllStatusForHost($params['id']);

        $view_url = $this->router->getPathFor("/centreon-realtime/host/" . $params['id']);

        $this->router->response()->json(array(
            'hostInformations' => $hostInformations,
            'servicesStatus' => $servicesStatus,
            'view_url' => $view_url,
            'success' => true
         ));
    }

    /**
     * Get executed command for a specific host
     *
     * @method get
     * @route /host/[i:id]/command
     */
    public function commandHostAction()
    {
        $params = $this->getParams();

        $command = HostRealtime::get($params['id'], 'command_line');

        $this->router->response()->json(array(
            'command' => $command['command_line'],
            'success' => true
         ));
    }

    /**
     * Get output for a specific host
     *
     * @method get
     * @route /host/[i:id]/output
     */
    public function outputHostAction()
    {
        $params = $this->getParams();

        $output = HostRealtime::get($params['id'], array('output', 'perfdata'));

        $this->router->response()->json(array(
            'output' => $output['output'],
            'perfdata' => $output['perfdata'],
            'success' => true
         ));
    }

    /**
     * Get scheduling informations for a specific host
     *
     * @method get
     * @route /host/[i:id]/scheduling-infos
     */
    public function schedulingInfosHostAction()
    {
        $params = $this->getParams();

        $schedulingInfos = HostRealtime::get($params['id'], array('execution_time', 'latency'));

        $poller = HostConf::get($params['id'], 'poller_id');
        $schedulingInfos['poller_name'] = !is_null($poller['poller_id']) ? Poller::get($poller['poller_id'], 'name') : "";

        unset($schedulingInfos['poller_id']);

        $this->router->response()->json(array(
            'scheduling_infos' => $schedulingInfos,
            'success' => true
         ));
    }

    /**
     * Get service realtime informations for a specific host
     *
     * @method get
     * @route /host/[i:id]/service
     */
    public function hostForServiceAction()
    {
        $requestParam = $this->getParams('named');

        $services = ServiceRealtime::getList(array('description as name', 'state'), -1, 0, null, 'ASC', array('host_id' => $requestParam['id']));

        $this->router->response()->json(array('service' => $services,'success' => true));
    }
}
