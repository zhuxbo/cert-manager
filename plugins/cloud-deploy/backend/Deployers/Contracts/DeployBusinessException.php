<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

use RuntimeException;

/**
 * 确定性业务错误(配置缺失、参数非法等),区别于疑似瞬态的 SDK/网络异常。
 * extends RuntimeException：保持 guardSdk 的 catch(Throwable) 与现有
 * `->toThrow(RuntimeException::class)` 测试(子类 is-a)不变;
 * CloudDeployJob 据此类型分流——业务错误 is_final 不重试。
 */
class DeployBusinessException extends RuntimeException {}
