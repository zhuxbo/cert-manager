<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * 重置管理员密码命令
 *
 * 使用方法：
 * php artisan admin:reset-password admin newPassword123
 *
 * 示例：
 * php artisan admin:reset-password admin P@ssw0rd123
 *
 * 注意事项：
 * 1. 密码至少需要6个字符
 * 2. 重置后会使管理员的所有现有令牌失效
 * 3. 建议管理员登录后立即修改密码
 */
class ResetAdminPasswordCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:reset-password {username : 管理员用户名} {password : 新密码}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重置管理员密码';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $username = $this->argument('username');
        $password = $this->argument('password');

        // 验证参数
        $validator = Validator::make([
            'username' => $username,
            'password' => $password,
        ], [
            'username' => 'required|string|min:1|max:255',
            'password' => 'required|string|min:6|max:255',
        ], [
            'username.required' => '用户名不能为空',
            'username.string' => '用户名必须是字符串',
            'username.min' => '用户名不能为空',
            'username.max' => '用户名不能超过255个字符',
            'password.required' => '密码不能为空',
            'password.string' => '密码必须是字符串',
            'password.min' => '密码至少需要6个字符',
            'password.max' => '密码不能超过255个字符',
        ]);

        if ($validator->fails()) {
            $this->error('参数验证失败：');
            foreach ($validator->errors()->all() as $error) {
                $this->line("  - $error");
            }

            return CommandAlias::FAILURE;
        }

        // 查找管理员
        $admin = Admin::where('username', $username)->first();

        if (! $admin) {
            $this->error("管理员用户 '$username' 不存在");

            return CommandAlias::FAILURE;
        }

        // 确认操作
        if (! $this->confirm("确定要重置管理员 '$username' 的密码吗？")) {
            $this->info('操作已取消');

            return CommandAlias::SUCCESS;
        }

        try {
            // 更新密码
            $admin->password = $password; // 模型会自动进行 Hash 处理
            $admin->save();

            // 吊销全部现存会话（三件套：bump token_version + logout_at + 清 refresh token），
            // 否则只 bump token_version 会漏 logout_at（中间件宽限期起算）+ 漏清 refresh token（旧会话仍可续期）
            $admin->revokeAllSessions();

            $this->info("管理员 '$username' 的密码已成功重置");
            $this->line("新密码：$password");
            $this->warn('请妥善保管新密码，并建议管理员登录后立即修改密码');
        } catch (Exception $e) {
            $this->error("重置密码失败：{$e->getMessage()}");

            return CommandAlias::FAILURE;
        }

        return CommandAlias::SUCCESS;
    }
}
