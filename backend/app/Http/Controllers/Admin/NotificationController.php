<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Notification\IndexRequest;
use App\Http\Requests\Notification\ResendRequest;
use App\Http\Requests\Notification\SendTestRequest;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Throwable;

class NotificationController extends BaseController
{
    public function __construct(protected NotificationCenter $notificationCenter)
    {
        parent::__construct();
    }

    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = Notification::query()->with(['template', 'notifiable']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['template_code'])) {
            $templateCode = $validated['template_code'];
            $query->whereHas('template', function ($q) use ($templateCode) {
                $q->where('code', $templateCode);
            });
        }
        if (! empty($validated['user_id'])) {
            $query->where('notifiable_type', User::class)
                ->where('notifiable_id', $validated['user_id']);
        }
        if (! empty($validated['notifiable_type'])) {
            $query->where('notifiable_type', $validated['notifiable_type']);
        }
        if (! empty($validated['created_at'])) {
            $query->whereBetween('created_at', [
                Carbon::parse($validated['created_at'][0]),
                Carbon::parse($validated['created_at'][1]),
            ]);
        }

        $total = $query->count();
        $items = $query->orderBy('id', 'desc')
            ->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $this->success([
            'items' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ]);
    }

    public function show(int $id): void
    {
        $notification = Notification::with(['template', 'notifiable'])->find($id);
        if (! $notification) {
            $this->error('通知不存在');
        }

        $this->success($notification->toArray());
    }

    public function sendTest(SendTestRequest $request): void
    {
        $validated = $request->validated();
        $template = NotificationTemplate::where('code', $validated['template_type'])
            ->where('status', 1)
            ->first();
        if (! $template) {
            $this->error('通知模板不存在');
        }

        $notifiable = $this->resolveNotifiable($validated['notifiable_type'], (int) $validated['notifiable_id']);

        try {
            $payload = $this->buildTestPayload($template, $notifiable, $validated['data'] ?? []);
            $intent = new NotificationIntent(
                $template->code,
                $validated['notifiable_type'],
                $notifiable->getKey(),
                $payload
            );

            $this->notificationCenter->dispatch($intent);
        } catch (Throwable $e) {
            $this->error('发送通知失败: '.$e->getMessage());
        }

        $this->success(['queued' => true]);
    }

    public function resend(int $id, ResendRequest $request): void
    {
        $notification = Notification::with(['template', 'notifiable'])->find($id);
        if (! $notification) {
            $this->error('通知不存在');
        }
        if (! $notification->template || ! $notification->notifiable) {
            $this->error('通知数据不完整，无法重发');
        }

        $data = $notification->data ?? [];
        unset($data['result']);

        try {
            /** @var NotificationTemplate $template */
            $template = $notification->template;
            $intent = new NotificationIntent(
                $template->code,
                $notification->notifiable_type,
                $notification->notifiable_id,
                $data
            );
            $this->notificationCenter->dispatch($intent);
        } catch (Throwable $e) {
            $this->error('发送通知失败: '.$e->getMessage());
        }

        $this->success(['queued' => true]);
    }

    protected function resolveNotifiable(string $type, int $id): Model
    {
        $map = Config::get('notification.notifiables', []);
        $class = $map[$type] ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            $this->error('通知接收者类型不支持');
        }

        $model = $class::find($id);
        if (! $model) {
            $this->error('通知接收者不存在');
        }

        return $model;
    }

    protected function buildTestPayload(NotificationTemplate $template, Model $notifiable, array $input = []): array
    {
        $payload = [];
        $variables = is_array($template->variables) ? $template->variables : [];

        foreach ($variables as $variable) {
            if (array_key_exists($variable, $input)) {
                $payload[$variable] = $input[$variable];
            } else {
                $value = data_get($notifiable, $variable);
                if ($value !== null) {
                    $payload[$variable] = $value;
                }
            }
        }

        return array_merge($payload, $input);
    }
}
