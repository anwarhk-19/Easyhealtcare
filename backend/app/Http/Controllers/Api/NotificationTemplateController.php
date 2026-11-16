<?php

namespace App\Http\Controllers\Api;

use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationTemplateController extends CrudController
{
    protected string $model = NotificationTemplate::class;

    protected string $viewPermission = 'notifications.view';

    protected string $managePermission = 'notifications.manage';

    protected string $orderBy = 'event_key';

    protected array $searchable = ['event_key', 'body'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return ['event_key' => [$req, 'string', 'max:64'], 'channel' => [$req, Rule::in(['whatsapp', 'sms', 'email'])], 'locale' => ['nullable', 'string', 'max:8'], 'provider_template_name' => ['nullable', 'string'], 'dlt_template_id' => ['nullable', 'string'], 'subject' => ['nullable', 'string'], 'body' => [$req, 'string', 'max:1000']];
    }
}
