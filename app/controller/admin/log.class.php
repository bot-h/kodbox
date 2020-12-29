<?php 

class adminLog extends Controller{
    public $actionList = array();
	function __construct() {
        parent::__construct();
        $this->model = Model('SystemLog');
    }

    /**
     * 操作类型列表
     * this.actions()
     * @return void
     */
    public function typeList(){
        $typeList = $this->model->getTypeList();
        show_json($typeList);
    }

	/**
     * 日志列表
     * @return void
     */
    public function get(){
        $data = Input::getArray(array(
            'timeFrom'  => array('check' => 'require'),
            'timeTo'    => array('check' => 'require'),
            'type'      => array('default' => ''),
            'userID'    => array('default' => ''),
        ));
        $res = $this->model->get($data);
        if(empty($res)) show_json(array());
        show_json($res['list'], true, $res['pageInfo']);
    }

    /**
     * 记录日志
     * @param boolean $data
     * @return void
     */
    public function log($data=false,$info=null){
        $typeList = $this->model->allTypeList();
        if(!isset($typeList[ACTION])) return;
        $actionList = array(
            'user.index.logout',
            'user.index.loginSubmit',
            'user.bind.bindApi'
        );
        // 操作日志
        if(!in_array(ACTION, $actionList)){
            // 文件类的操作，此处只收集这3个
            if(MOD == 'explorer') {
                $act = ST . '.' . ACT;
                $func = array('fav.add', 'fav.del', 'index.fileDownload');
                if(!in_array($act, $func)) return;
            }
            if(!is_array($data)) {
                $data = $this->in;
                unset($data['URLremote'], $data['URLrouter'], $data['HTTP_DEBUG_URL'], $data[str_replace(".", "/", ACTION)]);
            }
        }
        // 第三方绑定
        if(ACTION == 'user.bind.bindApi' && !$data['success']) return;
        // 登录日志
        if(ACTION == 'user.index.loginSubmit'){
            return $this->loginLog();
        }
        return $this->model->addLog(ACTION, $data);
    }

    /**
     * 登录日志
     * @param string $action
     * @param [type] $ip
     * @return void
     */
    public function loginLog(){
        $data = array(
            'is_wap' => is_wap(),
            'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''
        );
        $action = 'user.index.loginSubmit';
        return $this->model->addLog($action, $data);
    }
}