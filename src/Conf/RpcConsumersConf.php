<?php

namespace Dleno\CommonCore\Conf;

/**
 * rpc服务对应节点配置
 */
class RpcConsumersConf
{
    //是否使用注册中心
    const RPC_REGISTRY = false;
    //rpc服务对应访问节点
    public static $nodes = [
        //公共业务服务
        'Service.Account'     => [['port' => 9504, 'host' => 'Account.Rpc-Service']],
        'Service.Alarm'       => [['port' => 9504, 'host' => 'Alarm.Rpc-Service']],
        'Service.AppVersion'  => [['port' => 9504, 'host' => 'App-Version.Rpc-Service']],
        'Service.Cdk'         => [['port' => 9504, 'host' => 'Cdk.Rpc-Service']],
        'Service.Config'      => [['port' => 9504, 'host' => 'Config.Rpc-Service']],
        'Service.Data'        => [['port' => 9504, 'host' => 'Data.Rpc-Service']],
        'Service.Device'      => [['port' => 9504, 'host' => 'Device.Rpc-Service']],
        'Service.Finance'     => [['port' => 9504, 'host' => 'Finance.Rpc-Service']],
        'Service.Instruct'    => [['port' => 9504, 'host' => 'Instruct.Rpc-Service']],
        'Service.Manager'     => [['port' => 9504, 'host' => 'Manager.Rpc-Service']],
        'Service.Market'      => [['port' => 9504, 'host' => 'Market.Rpc-Service']],
        'Service.Push'        => [['port' => 9504, 'host' => 'Push.Rpc-Service']],
        'Service.Robot'       => [['port' => 9504, 'host' => 'Robot.Rpc-Service']],
        'Service.RolePermis'  => [['port' => 9504, 'host' => 'Role-Permis.Rpc-Service']],
        'Service.SameTrade'   => [['port' => 9504, 'host' => 'Same-Trade.Rpc-Service']],
        'Service.Schedule'    => [['port' => 9504, 'host' => 'Schedule.Rpc-Service']],
        'Service.Settlement'  => [['port' => 9504, 'host' => 'Settlement.Rpc-Service']],
        'Service.Strategy'    => [['port' => 9504, 'host' => 'Strategy.Rpc-Service']],
        'Service.TargetUser'  => [['port' => 9504, 'host' => 'Target-User.Rpc-Service']],
        'Service.Task'        => [['port' => 9504, 'host' => 'Task.Rpc-Service']],
        //公共基础服务
        'Base.Account'        => [['port' => 9504, 'host' => 'Account.Rpc-Base']],
        'Base.Alarm'          => [['port' => 9504, 'host' => 'Alarm.Rpc-Base']],
        'Base.AppVersion'     => [['port' => 9504, 'host' => 'App-Version.Rpc-Base']],
        'Base.Cdk'            => [['port' => 9504, 'host' => 'Cdk.Rpc-Base']],
        'Base.Config'         => [['port' => 9504, 'host' => 'Config.Rpc-Base']],
        'Base.Data'           => [['port' => 9504, 'host' => 'Data.Rpc-Base']],
        'Base.Device'         => [['port' => 9504, 'host' => 'Device.Rpc-Base']],
        'Base.Finance'        => [['port' => 9504, 'host' => 'Finance.Rpc-Base']],
        'Base.Instruct'       => [['port' => 9504, 'host' => 'Instruct.Rpc-Base']],
        'Base.Manager'        => [['port' => 9504, 'host' => 'Manager.Rpc-Base']],
        'Base.Market'         => [['port' => 9504, 'host' => 'Market.Rpc-Base']],
        'Base.Robot'          => [['port' => 9504, 'host' => 'Robot.Rpc-Base']],
        'Base.RolePermis'     => [['port' => 9504, 'host' => 'Role-Permis.Rpc-Base']],
        'Base.SameTrade'      => [['port' => 9504, 'host' => 'Same-Trade.Rpc-Base']],
        'Base.Schedule'       => [['port' => 9504, 'host' => 'Schedule.Rpc-Base']],
        'Base.Settlement'     => [['port' => 9504, 'host' => 'Settlement.Rpc-Base']],
        'Base.Strategy'       => [['port' => 9504, 'host' => 'Strategy.Rpc-Base']],
        'Base.TargetUser'     => [['port' => 9504, 'host' => 'Target-User.Rpc-Base']],
        'Base.Task'           => [['port' => 9504, 'host' => 'Task.Rpc-Base']],
        'Base.Unrepeated'     => [['port' => 9504, 'host' => 'Unrepeated.Rpc-Base']],
        //app业务服务
        'Service.Douyin'      => [['port' => 9504, 'host' => 'Douyin.Rpc-Service']],
        'Service.Kuaishou'    => [['port' => 9504, 'host' => 'Kuaishou.Rpc-Service']],
        'Service.Xiaohongshu' => [['port' => 9504, 'host' => 'Xiaohongshu.Rpc-Service']],
    ];

    //rpc服务本地开发调试对应访问节点
    public static $localNodes = [
        //公共业务服务
        'Service.Account'     => [['port' => 9601, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Alarm'       => [['port' => 9602, 'host' => 'dev-app-api.dlenos.com']],
        'Service.AppVersion'  => [['port' => 9603, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Cdk'         => [['port' => 9604, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Config'      => [['port' => 9605, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Data'        => [['port' => 9606, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Device'      => [['port' => 9607, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Finance'     => [['port' => 9608, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Instruct'    => [['port' => 9609, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Manager'     => [['port' => 9610, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Market'      => [['port' => 9611, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Push'        => [['port' => 9612, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Robot'       => [['port' => 9613, 'host' => 'dev-app-api.dlenos.com']],
        'Service.RolePermis'  => [['port' => 9614, 'host' => 'dev-app-api.dlenos.com']],
        'Service.SameTrade'   => [['port' => 9615, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Schedule'    => [['port' => 9616, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Settlement'  => [['port' => 9617, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Strategy'    => [['port' => 9618, 'host' => 'dev-app-api.dlenos.com']],
        'Service.TargetUser'  => [['port' => 9619, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Task'        => [['port' => 9620, 'host' => 'dev-app-api.dlenos.com']],
        //公共基础服务
        'Base.Account'        => [['port' => 9621, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Alarm'          => [['port' => 9622, 'host' => 'dev-app-api.dlenos.com']],
        'Base.AppVersion'     => [['port' => 9623, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Cdk'            => [['port' => 9624, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Config'         => [['port' => 9625, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Data'           => [['port' => 9626, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Device'         => [['port' => 9627, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Finance'        => [['port' => 9628, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Instruct'       => [['port' => 9629, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Manager'        => [['port' => 9630, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Market'         => [['port' => 9631, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Robot'          => [['port' => 9632, 'host' => 'dev-app-api.dlenos.com']],
        'Base.RolePermis'     => [['port' => 9633, 'host' => 'dev-app-api.dlenos.com']],
        'Base.SameTrade'      => [['port' => 9634, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Schedule'       => [['port' => 9635, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Settlement'     => [['port' => 9636, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Strategy'       => [['port' => 9637, 'host' => 'dev-app-api.dlenos.com']],
        'Base.TargetUser'     => [['port' => 9638, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Task'           => [['port' => 9639, 'host' => 'dev-app-api.dlenos.com']],
        'Base.Unrepeated'     => [['port' => 9640, 'host' => 'dev-app-api.dlenos.com']],
        //app业务服务
        'Service.Douyin'      => [['port' => 9641, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Kuaishou'    => [['port' => 9642, 'host' => 'dev-app-api.dlenos.com']],
        'Service.Xiaohongshu' => [['port' => 9643, 'host' => 'dev-app-api.dlenos.com']],
    ];
}
