<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PluginOperation extends BaseModel
{
    public const TYPE_INSTALL_REMOTE = 'install_remote';

    public const TYPE_INSTALL_UPLOAD = 'install_upload';

    public const TYPE_UPDATE = 'update';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STAGE_QUEUED = 'queued';

    public const STAGE_ERROR = 'error';

    protected $fillable = [
        'uuid',
        'type',
        'plugin_name',
        'version',
        'release_url',
        'upload_path',
        'status',
        'stage',
        'message',
        'result',
        'error',
        'admin_id',
        'attempts',
        'run_token',
        'last_heartbeat_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'result' => 'array',
        'attempts' => 'integer',
        'last_heartbeat_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true);
    }

    public function isStale(): bool
    {
        if (! in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            return false;
        }

        $heartbeat = $this->status === self::STATUS_RUNNING
            ? ($this->last_heartbeat_at ?? $this->updated_at)
            : ($this->updated_at ?? $this->created_at);

        return $heartbeat !== null
            && $heartbeat->lt(now()->subSeconds((int) config('plugin.operations.stale_after', 330)));
    }

    public function toPublicArray(): array
    {
        $result = is_array($this->result) ? array_intersect_key($this->result, array_flip([
            'name',
            'version',
            'from_version',
            'message',
            'nginx_reload',
            'remove_data',
        ])) : null;

        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'plugin_name' => $this->plugin_name,
            'version' => $this->version,
            'status' => $this->status,
            'stage' => $this->stage,
            'message' => $this->message,
            'error' => $this->error,
            'result' => $result,
            'admin_id' => $this->admin_id,
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
            'started_at' => optional($this->started_at)->toDateTimeString(),
            'finished_at' => optional($this->finished_at)->toDateTimeString(),
            'is_stale' => $this->isStale(),
        ];
    }
}
