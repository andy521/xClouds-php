<?php
/**
 * 包名：AliGenieGateWay
 * 创建时间：2019.6.28
 * 创建者博客： http://blog.csdn.net/xh870189248
 * 创建者GitHub： https://github.com/xuhongv
 * 创建者：徐宏
 * 描述： TODO
 */

namespace app\api\controller\oauth;


use app\common\device\DeviceCenter;
use think\Validate;

class AliGenieGateWay extends BaseController
{

    private $server;
    private $cloudsType;
    private $cloudsMyType;

    public function _initialize()
    {
        parent::_initialize(); // TODO: Change the autogenerated stub

        $this->cloudsType = config('devicesAttr.AligenieName');
        $this->cloudsMyType = config('devicesAttr.MineName');

        $dsn = 'mysql:dbname=' . config('database')['database'] . ';host=' . config('database')['hostname'];
        $username = config('database')['username'];
        $password = config('database')['password'];

        \OAuth2\Autoloader::register();

        // $dsn is the Data Source Name for your database, for exmaple "mysql:dbname=my_oauth2_db;host=localhost"
        $storage = new \OAuth2\Storage\Pdo(array('dsn' => $dsn, 'username' => $username, 'password' => $password));

        // Pass a storage object or array of storage objects to the OAuth2 server class
        $this->server = new \OAuth2\Server($storage);

        // Add the "Client Credentials" grant type (it is the simplest of the grant types)
        $this->server->addGrantType(new \OAuth2\GrantType\ClientCredentials($storage));

        // Add the "Authorization Code" grant type (this is where the oauth magic happens)
        $this->server->addGrantType(new \OAuth2\GrantType\AuthorizationCode($storage));
    }


    /**
     *  刷新设备列表
     */
    private function getDiscoveryDevicesList($userId, $messageId = '')
    {
        //第一步
        //判断该用户是否存在
        try {
            $user = model('User')->get(['id' => $userId]);
        } catch (\Exception $exception) {
            return utilsResponse(-1, '用户查询报错:' . $exception->getMessage(), [], 400);
        }
        $devices = [
            "devices" => [],
        ];

        //判断天猫精灵请求的用户不存在！？返回空数据给天猫精灵
        if ($user) {
            //根据userID从数据库查询此用户已经绑定的设备列表列表
            $device = model('RelDeviceUser')->getThisUserDevicesList(['user_id' => $userId]);
            //根据userID从数据库查询此用户已经绑定的设备列表个数
            $devicesNums = model('RelDeviceUser')->countWhere(['user_id' => $userId]);
            //utilsSaveLogs('the user\'s nums:' . $devicesNums, 3);
            if (0 != $devicesNums) {
                //= $deviceInf;
                for ($i = 0; $i < $devicesNums; $i++) {
                    //判断此设备是否支持天猫精灵语音控制
                    if (DeviceCenter::isSupportThisClouds($device[$i]['type'], $this->cloudsType))
                        array_push($devices['devices'], [
                            "deviceId" => $userId . '-' . (string)($device[$i]['device_id']),
                            "deviceName" => $device[$i]['alias'],
                            "deviceType" => DeviceCenter::getDeviceTypeToCloudsType($device[$i]['type'], $this->cloudsType),
                            "zone" => "",
                            "brand" => "",
                            "model" => "AiClouds3.0",
                            "icon" => $device[$i]['img'],
                            "properties" => array()
                            ,
                            "actions" => DeviceCenter::getThisDeviceAllControlActions($device[$i]['type'], $this->cloudsType),
                            "extensions" => array(
                                "extension1" => "",
                                "extension2" => ""
                            ),
                        ]);
                }
            }
        }
        $arry = ["header" => array(
            "namespace" => "AliGenie.Iot.DeviceCenter.Discovery",
            "name" => "DiscoveryDevicesResponse",
            "messageId" => $messageId,
            "payLoadVersion" => 1,
        ),
            "payload" => ($devices),
        ];

        return ($arry);
    }


    /**
     *
     * 控制某个设备，目前先不做userID校验
     * @param string $getAligenieObj 全部数据
     * @param string $userId 用户ID
     * @return array 控制成功与否回调给天猫精灵
     */
    private function controllerDevice($userId = '', $getAligenieObj)
    {

        $mqttResult = $this->cloudsCodes_control_offline;
        $controlType = $this->cloudsType;
        $messageId = $getAligenieObj->header->messageId;
        $actionName = $getAligenieObj->header->name;
        $attribute = $getAligenieObj->payload->attribute;
        $deviceType = $getAligenieObj->payload->deviceType;
        $deviceId = $getAligenieObj->payload->deviceId;
        $value = $getAligenieObj->payload->value;
        $payLoadVersion = $getAligenieObj->header->payLoadVersion;
        //根据id从数据库查询此设备
        try {
            $device = model('Device')->get(['id' => (int)(explode('-', $deviceId)[1])]);
        } catch (\Exception $e) {
            utilsSaveLogs('$getPostDataArry ID database check fail ' . $e->getMessage(), 1);
            $mqttResult = $this->cloudsCodes_control_not_exit;
            goto repose;
        }
        //1 检查到设备不存在
        if ($device) {
            //判断设备是否在线？如果在线则发送数据，否则不返回提示发送失败
            if ($device->online != 1) {
                //utilsSaveLogs('检查到deviceId为【' . $deviceId . '】设备离线', 1);
                $mqttResult = $this->cloudsCodes_control_offline;
                goto repose;
            }
        } else {
            //utilsSaveLogs('检查到deviceId为【' . $deviceId . '】设备不存在', 1);
            $mqttResult = $this->cloudsCodes_control_not_exit;
            goto repose;
        }
        //2 检查设备是否支持该属性控制
        $properties_control = DeviceCenter::getThisDeviceAllControlActions($device->type, $controlType);
        if (!is_array($properties_control)) {
            $mqttResult = $this->cloudsCodes_control_error_server;
            goto repose;
        }
        //2 取出所有可控制的属性一一对比
        $enable = false;
        for ($j = 0; $j < count($properties_control); $j++) {
            if ($actionName == $properties_control[$j]) {
                //3 对可控制的属性的取值范围校验
                //获取规则
                $rulers = config('devicesAttr.' . $device->type)[$this->cloudsMyType][$controlType]['properties_control_value_range'][$attribute];
                if (!is_array($rulers)) {
                    $mqttResult = $this->cloudsCodes_control_error_server;
                    goto repose;
                }
                for ($index = 0; $index < count($rulers); $index++) {
                    //判断
                    $validate = new Validate([
                            'value' => $rulers[$index]
                        ]
                    );
                    //判断是否匹配规则
                    if ($validate->check(['value' => $value])) {
                        $enable = true;
                    }
                }
            }
        }
        //该属性不可控制
        if (!$enable) {
            $mqttResult = $this->cloudsCodes_control_proper_not_support;
        } else {
            $this->reposeToDevice($controlType, $device, $actionName
                , config('devicesAttr.' . $device->type)[$this->cloudsMine][$controlType]['properties_control'][$attribute]
                , $deviceId, $value, $payLoadVersion);
            $mqttResult = $this->cloudsCodes_control_ok;
        }
        repose:{
        $arryResult = [];
        $headerName = '';
        //如果返回正确则表示控制正确，否则则控制失败表示该设备不存在
        switch ($mqttResult) {
            //控制成功
            case $this->cloudsCodes_control_ok:
                $arryResult = [
                    'deviceId' => $getAligenieObj->payload->deviceId
                ];
                $headerName = $getAligenieObj->header->name . 'Response';
                break;
            //设备离线
            case $this->cloudsCodes_control_offline:
                $arryResult = [
                    'deviceId' => $getAligenieObj->payload->deviceId,
                    'errorCode' => 'IOT_DEVICE_OFFLINE',
                    'message' => 'device is offline',
                ];
                $headerName = 'ErrorResponse';
                break;
            //设备不存在
            case $this->cloudsCodes_control_not_exit:
                $arryResult = [
                    'deviceId' => $getAligenieObj->payload->deviceId,
                    'errorCode' => 'DEVICE_IS_NOT_EXIST',
                    'message' => 'device is not exist',
                ];
                $headerName = 'ErrorResponse';
                break;
            //服务器内部异常
            case $this->cloudsCodes_control_error_server:
                utilsSaveLogs('deviceId为【' . $getAligenieObj->payload->deviceId . '】设备 属性操作 服务器内部异常', 1);
                $arryResult = [
                    'deviceId' => $getAligenieObj->payload->deviceId,
                    'errorCode' => 'SERVICE_ERROR',
                    'message' => 'mqtt clouds error',
                ];
                $headerName = 'ErrorResponse';
                break;
            //设备不支持此属性操作
            case $this->cloudsCodes_control_proper_not_support:
                utilsSaveLogs('deviceId为【' . $getAligenieObj->payload->deviceId . '】设备不支持此属性操作', 1);
                $arryResult = [
                    'deviceId' => $getAligenieObj->payload->deviceId,
                    'errorCode' => 'DEVICE_NOT_SUPPORT_FUNCTION',
                    'message' => 'device not support',
                ];
                $headerName = 'ErrorResponse';
                break;
        }
        $arry = [
            'header' => [
                "namespace" => "AliGenie.Iot.DeviceCenter.Control",
                "name" => $headerName,
                'messageId' => $getAligenieObj->header->messageId,
                'payLoadVersion' => $getAligenieObj->header->payLoadVersion,
            ],
            'payload' => $arryResult
        ];
        return $arry;
    }
    }


    /**
     *   查询某个设备的最新状态
     *
     * @param string $userId 当前操作用户id
     * @param string $deviceID 当前要查询的设备id
     * @param string $messageId msgId
     * @return array 返回给json数据给天猫精灵
     */
    private function queryDeviceStatus($userId = '', $getAligenieObj = '')
    {

        $deviceId = $getAligenieObj->payload->deviceId;
        //根据id从数据库查询此设备
        try {
            $device = model('Device')->get(['id' => (int)(explode('-', $deviceId)[1])]);
        } catch (\Exception $e) {
            utilsSaveLogs('get device ID ' . $deviceId . ' database get fail ' . $e->getMessage(), 1);
        }
        //utilsSaveLogs('attrbute for device: ' . json_encode($device['attrbute']), 1);
        $headerName = 'ErrorResponse';
        if ($device) {
            //正常回调
            $headerName = 'QueryResponse';
            $pro = [];
            $payload = [
                'deviceId' => $getAligenieObj->payload->deviceId,
            ];
            //判断是否在线
            if ($device['online'] == 1) {
                $statusArry = (json_decode($device['attrbute']));
                for ($i = 0; $i < count($statusArry); $i++) {
                    //这里要转换为天猫精灵识别的属性名字
                    $pro[$i] = [
                        'name' => $statusArry[$i]->name,
                        'value' => $statusArry[$i]->value,
                    ];
                }
            } else {
                //检查此设备激活状态
                if (!is_null($device['mac'])) {
                    //离线信息返回
                    $pro[0] = [
                        'name' => "onlinestate",
                        'value' => "offline",
                    ];
                    $pro[1] = [
                        'name' => "remotestatus",
                        'value' => "off",
                    ];
                    //错误提示此设备不存在
                } else {
                    $headerName = 'ErrorResponse';
                    $payload = [
                        'errorCode' => 'IOT_DEVICE_OFFLINE',
                        'message' => 'device is offline',
                        'deviceId' => $getAligenieObj->payload->deviceId,
                    ];
                }
            }
            $data = [
                'properties' => $pro,
                'header' => [
                    'namespace' => "AliGenie.Iot.Device.Query",
                    "name" => $headerName,
                    "messageId" => $getAligenieObj->header->messageId,
                    "payLoadVersion" => $getAligenieObj->header->payLoadVersion
                ],
                'payload' => $payload
            ];
            return $data;
        }

        $arry = [
            'header' => [
                "namespace" => "AliGenie.Iot.DeviceCenter.Query",
                "name" => $headerName,
                'messageId' => $getAligenieObj->header->messageId,
                'payLoadVersion' => $getAligenieObj->header->payLoadVersion,
            ],
            'payload' => [

            ]
        ];

        return $arry;
    }

    function index()
    {
        //捕获post过来的数据
        $poststr = file_get_contents("php://input");
        $post = input('post.');

        //生成日志
        utilsSaveLogs('***********Aligenie to me gateway*************', 2);
//        utilsSaveLogs($poststr, 3);
        utilsSaveLogs(json_encode($post), 2);

        //解析成对象
        $getAligenieObj = json_decode($poststr);

        //是否对象
        if (!is_object($getAligenieObj)) {
            //utilsSaveLogs('fail pass' . gettype($getAligenieObj), 2);
            return utilsResponse(0, 'are you aligenie?', [], 400);
        }

        $namespace = '';
        $name = '';
        $messageId = '';

        try {
            //判断在 payload 是否有这个字段
            $accessToken = $getAligenieObj->payload->accessToken;
            $namespace = $getAligenieObj->header->namespace;
            $name = $getAligenieObj->header->name;
            $messageId = $getAligenieObj->header->messageId;
            //这里兼容天猫精灵发来的json消息的access_token中没有放在一级中
            $_POST['access_token'] = $accessToken;
            $_GET['access_token'] = $accessToken;
        } catch (\Exception $exception) {
            utilsSaveLogs('fail pass' . $exception->getMessage(), 2);
            return utilsResponse($this->CodeFail, 'are you aligenie? ' . $exception->getMessage(), [], 400);
        }

        // 校验accessToken是否正确？
        if (!$this->server->verifyResourceRequest(\OAuth2\Request::createFromGlobals())) {
            //utilsSaveLogs('fail pass' . $this->server->getResponse(), 2);
            return utilsResponse(0, 'invalid_token check fail', [], 400);
        }

        $token = $this->server->getAccessTokenData(\OAuth2\Request::createFromGlobals());

        //utilsSaveLogs('success pass get Current userId : ' . $token['user_id'] . ' , and the action :'.$namespace, 3);

        $repository = [];
        switch ($namespace) {
            //请求刷新设备列表
            case 'AliGenie.Iot.Device.Discovery':
                $repository = $this->getDiscoveryDevicesList($token['user_id'], $messageId);
                break;
            //请求控制单个设备
            case 'AliGenie.Iot.Device.Control':
                $repository = $this->controllerDevice($token['user_id'], $getAligenieObj);
                break;
            //查询某个设备的状态
            case 'AliGenie.Iot.Device.Query':
                $repository = $this->queryDeviceStatus($token['user_id'], $getAligenieObj);
                break;
            default:
                break;
        }
        //utilsSaveLogs('success send aglinie msg ==> ' . json_encode($repository), 2);
        return json($repository);
    }

}